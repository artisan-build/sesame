<?php

namespace Tests\Unit;

use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Integration tests live here so the default PHPUnit suite discovers them
 * outside tests/Feature's global RefreshDatabase. Every test owns a whole
 * disposable database: the archive schema has a fixed public name.
 */
class LegacyAuthUpgradeTest extends TestCase
{
    private ?string $database = null;

    private ?string $fixtureDirectory = null;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        $connection = config('database.connections.pgsql');
        $this->assertContains($connection['host'], ['127.0.0.1', 'localhost', '::1']);
        // Never inherit a URL that could override the explicitly selected DB.
        $connection['url'] = null;
        $connection['database'] = 'sesame_test';
        config(['database.connections.legacy_upgrade_admin' => $connection]);
        $admin = DB::connection('legacy_upgrade_admin');
        $this->assertSame('pgsql', $admin->getDriverName());
        $name = 'sesame_auth_upgrade_'.bin2hex(random_bytes(8));
        $role = $admin->selectOne('SELECT current_user AS name, rolcreatedb, rolsuper FROM pg_roles WHERE rolname = current_user');

        if (! $role->rolcreatedb && ! $role->rolsuper) {
            $this->markTestSkipped('Auth upgrade integration tests require a local PostgreSQL role with CREATEDB; CI provides one.');
        }

        $admin->statement('CREATE DATABASE "'.$name.'" TEMPLATE template0');
        $this->database = $name;
        $connection['database'] = $name;
        config(['database.connections.legacy_upgrade' => $connection]);
        DB::setDefaultConnection('legacy_upgrade');
        config(['session.connection' => 'legacy_upgrade']);
        $this->assertSame($name, DB::selectOne('SELECT current_database() AS name')->name);
        $this->fixtureDirectory = sys_get_temp_dir().'/'.$name;
        File::makeDirectory($this->fixtureDirectory);
        foreach ($this->originalAuthMigrations() as $filename => $source) {
            File::put($this->fixtureDirectory.'/'.$filename, $source);
        }

        $paths = [$this->fixtureDirectory];
        foreach ([
            '0001_01_01_000001_create_cache_table.php',
            '0001_01_01_000002_create_jobs_table.php',
            '2026_11_06_000001_create_sesame_tables.php',
            '2026_11_06_000002_create_crm_harness_tables.php',
            '2026_11_06_000003_move_sesame_onto_managed_resources.php',
        ] as $filename) {
            $paths[] = database_path('migrations/'.$filename);
        }
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'legacy_upgrade', '--force' => true,
            '--path' => $paths, '--realpath' => true,
        ]), Artisan::output());
        $this->assertFalse(Schema::hasColumn('users', 'auth_session_version'));
        $this->assertFalse(Schema::hasTable('bfc_authority'));
        $this->mockConsoleOutput = false;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixtureDirectory !== null) {
                File::deleteDirectory($this->fixtureDirectory);
            }
            if ($this->database !== null) {
                DB::disconnect('legacy_upgrade');
                DB::purge('legacy_upgrade');
                DB::setDefaultConnection($this->originalConnection);
                // Exact random name owned by this test; never migrate:fresh or
                // drop schemas in sesame_test/sesame_local, including archives.
                $this->assertMatchesRegularExpression('/^sesame_auth_upgrade_[a-f0-9]{16}$/D', $this->database);
                DB::connection('legacy_upgrade_admin')->statement('DROP DATABASE "'.$this->database.'" WITH (FORCE)');
            }
            DB::purge('legacy_upgrade_admin');
        } finally {
            parent::tearDown();
        }
    }

    public function test_full_upgrade_preserves_data_and_retires_legacy_credentials(): void
    {
        $this->seedLegacyUsers();
        $this->seedSimulator();
        $simulator = $this->snapshot('sesame_%');
        $legacy = $this->rows('users');
        $passkeys = $this->rows('passkeys');
        $sessions = $this->rows('sessions');
        $resets = $this->rows('password_reset_tokens');

        config(['session.driver' => 'database']);
        resolve('session')->forgetDrivers();
        $handler = resolve('session')->driver()->getHandler();
        $this->assertSame(serialize(['legacy' => true]), $handler->read('legacy-session'));
        $this->assertTrue(Hash::check('legacy-reset-token', $resets[0]['token']));
        config(['session.driver' => 'array']);
        resolve('session')->forgetDrivers();

        $this->migrate();

        $this->assertSame($simulator, $this->snapshot('sesame_%'));
        $this->assertSame($legacy, $this->rows('sesame_legacy_auth.users'));
        $this->assertSame($passkeys, $this->rows('sesame_legacy_auth.passkeys'));
        $this->assertSame($sessions, $this->rows('sesame_legacy_auth.sessions'));
        $this->assertSame($resets, $this->rows('sesame_legacy_auth.password_reset_tokens'));
        $this->assertFalse(Schema::hasTable('public.passkeys'));
        $this->assertSame([], $this->rows('sessions'));
        $this->assertSame([], $this->rows('password_reset_tokens'));
        $this->assertSame([7, 43, 91, 125], DB::table('users')->orderBy('id')->pluck('id')->all());

        foreach (DB::table('sesame_legacy_auth.users')->get() as $old) {
            $new = DB::table('users')->find($old->id);
            foreach (['id', 'name', 'email', 'email_verified_at', 'password', 'created_at', 'updated_at'] as $column) {
                $this->assertSame($old->$column, $new->$column, $column);
            }
            $this->assertSame(strtolower($old->email), $new->normalized_email);
            $this->assertTrue(Hash::check('legacy-password', $new->password));
            $this->assertSame('member', $new->role);
            $this->assertNull($new->owner_slot);
            $this->assertNull($new->remember_token);
            $this->assertSame(1, $new->auth_session_version);
            $this->assertSame($old->id === 7 ? 'active' : 'deactivated', $new->status);
            if ($old->id === 7) {
                $this->assertNull($new->deactivated_at);
            } else {
                $this->assertNotNull($new->deactivated_at);
            }
        }
        $this->assertSame(0, DB::table('users')->where('role', 'owner')->count());
        $this->assertSame(0, DB::table('ownership')->count());

        // Migrations must be registered by the package and run by the real
        // command, not merely by calling either app migration's up() method.
        foreach (File::glob(base_path('vendor/artisan-build/built-for-cloud/database/migrations/*.php')) as $migration) {
            $this->assertSame(1, DB::table('migrations')->where('migration', basename($migration, '.php'))->count(), $migration);
        }
        $logged = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        $this->assertLessThan(array_search('0001_01_01_000001_create_users_table', $logged), array_search('0000_01_01_000000_prepare_built_for_cloud_auth', $logged));
        $this->assertSame('2026_11_09_000001_import_legacy_human_accounts', end($logged));
        $beforeRepeat = $this->snapshot();
        $this->migrate();
        $this->assertSame($beforeRepeat, $this->snapshot());

        $id = DB::table('users')->insertGetId(['name' => 'New member', 'email' => 'new@example.test']);
        $this->assertSame(126, $id);
        $this->assertSame('sesame_legacy_auth.users_id_seq', DB::selectOne("SELECT pg_get_serial_sequence('sesame_legacy_auth.users', 'id') AS name")->name);
        $this->assertSame('public.users_id_seq', DB::selectOne("SELECT pg_get_serial_sequence('users', 'id') AS name")->name);
        $foreignKey = DB::selectOne("SELECT confrelid::regclass::text AS target FROM pg_constraint WHERE conrelid = 'sesame_legacy_auth.passkeys'::regclass AND contype = 'f'");
        $this->assertSame('sesame_legacy_auth.users', $foreignKey->target);

        // The same password must still work for an ordinary member and must
        // NOT work for either MFA enrollment (even unconfirmed) or passkeys.
        foreach ([43, 91, 125] as $protectedId) {
            $this->post(route('bfc.login.store'), ['email' => User::findOrFail($protectedId)->email, 'password' => 'legacy-password'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }
        $this->post(route('bfc.login.store'), ['email' => 'Plain@Example.test', 'password' => 'legacy-password'])
            ->assertRedirect(route('bfc.dashboard'));
        $this->assertAuthenticatedAs(User::findOrFail(7));
        Auth::logout();

        // Exercise actual database-session lookup with the old session ID,
        // not just a count of the new sessions table.
        config(['session.driver' => 'database']);
        resolve('session')->forgetDrivers();
        $this->assertSame('', resolve('session')->driver()->getHandler()->read('legacy-session'));
        $this->get(route('bfc.password.reset', ['token' => 'legacy-reset-token']))->assertNotFound();
        $this->get('/reset-password/legacy-reset-token?email=Plain%40Example.test')->assertNotFound();
        $this->assertSame($resets, $this->rows('sesame_legacy_auth.password_reset_tokens'));

        // Positive control: a newly issued token reaches the real handoff
        // route. A blanket 404 or disabled recovery feature cannot pass this.
        DB::table('password_reset_tokens')->insert([
            'email' => 'Plain@Example.test', 'token' => hash('sha256', 'new-reset-token'), 'created_at' => now(),
        ]);
        $this->get(route('bfc.password.reset', ['token' => 'new-reset-token']))
            ->assertRedirect(route('bfc.password.reset.form'));
    }

    public function test_case_insensitive_duplicates_abort_before_any_schema_or_data_changes(): void
    {
        $this->seedLegacyUsers();
        $this->seedSimulator();
        DB::table('users')->insert([
            'id' => 200, 'name' => 'Collision', 'email' => 'plain@example.test', 'password' => Hash::make('other-password'),
        ]);
        $before = $this->snapshot();
        $tables = Schema::getTableListing();
        $this->assertMigrationRefused('Resolve case-insensitive duplicate user emails');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($tables, Schema::getTableListing());
        $this->assertNull(DB::selectOne("SELECT to_regnamespace('sesame_legacy_auth')::text AS name")->name);
        $this->assertFalse(Schema::hasColumn('users', 'auth_session_version'));
        $this->assertFalse(Schema::hasTable('bfc_authority'));

        // Recovery is a data correction followed by the SAME migrate command.
        DB::table('users')->where('id', 200)->update(['email' => 'distinct@example.test']);
        $this->migrate();
        $this->assertSame(5, DB::table('users')->count());
        $this->assertSame('distinct@example.test', DB::table('users')->find(200)->email);
    }

    public function test_empty_legacy_installation_upgrades_and_starts_sequence_at_one(): void
    {
        $this->migrate();
        $this->assertSame([], $this->rows('users'));
        $this->assertSame([], $this->rows('sesame_legacy_auth.users'));
        $this->assertSame(1, DB::table('users')->insertGetId(['name' => 'First', 'email' => 'first@example.test']));
        $before = $this->snapshot();
        $this->migrate();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_mixed_auth_schema_is_rejected_without_moving_legacy_tables(): void
    {
        $this->seedLegacyUsers();
        DB::statement('CREATE TABLE bfc_authority (sentinel text)');
        DB::table('bfc_authority')->insert(['sentinel' => 'do-not-touch']);
        $before = $this->snapshot();
        $this->assertMigrationRefused('Mixed legacy and Built for Cloud auth schemas');
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(DB::selectOne("SELECT to_regnamespace('sesame_legacy_auth')::text AS name")->name);
    }

    public function test_late_import_uses_generated_canonical_email_and_rejects_new_case_collisions(): void
    {
        $this->seedLegacyUsers();
        $this->migrate();
        $this->assertSame('plain@example.test', User::findOrFail(7)->normalized_email);
        $this->assertSame(7, User::query()->where('normalized_email', 'plain@example.test')->sole()->id);
        $column = DB::selectOne("SELECT is_generated, generation_expression FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'normalized_email'");
        $this->assertSame('ALWAYS', $column->is_generated);
        $this->assertStringContainsString('lower', $column->generation_expression);
        $before = $this->snapshot();
        try {
            DB::transaction(fn () => DB::table('users')->insert([
                'id' => 999, 'name' => 'Collision', 'email' => 'PLAIN@example.test',
            ]));
            $this->fail('Canonical email uniqueness must reject a case-only collision.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
            $this->assertStringContainsString('users_normalized_email_unique', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_repeated_import_failure_rolls_back_rows_inserted_before_the_collision(): void
    {
        $this->seedLegacyUsers();
        $this->seedSimulator();
        $this->migrate();
        // Force just the import to be pending again, with its first ID absent
        // but a later ID present. It will insert ID 7, then collide at ID 43.
        DB::table('users')->where('id', 7)->delete();
        DB::table('migrations')->where('migration', '2026_11_09_000001_import_legacy_human_accounts')->delete();
        $before = $this->snapshot();
        try {
            $this->migrate();
            $this->fail('Reimporting existing IDs must fail rather than overwrite them.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
            $this->assertStringContainsString('users_pkey', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(DB::table('users')->where('id', 7)->exists());
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_11_09_000001_import_legacy_human_accounts')->count());
    }

    public function test_real_artisan_process_also_runs_non_test_package_data_migrations(): void
    {
        $this->seedLegacyUsers();
        $this->seedSimulator();
        $simulator = $this->snapshot('sesame_%');
        $connection = config('database.connections.legacy_upgrade');
        $process = new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], base_path(), [
            // APP_ENV=testing skips the package's initial ownership-claim
            // migration. Cover the actual non-test public command as well.
            'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'LOG_CHANNEL' => 'stderr',
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_DATABASE' => $this->database,
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->mustRun();
        $this->assertSame($simulator, $this->snapshot('sesame_%'));
        $this->assertSame(4, DB::table('users')->where('role', 'member')->count());
        $this->assertSame(0, DB::table('users')->where('role', 'owner')->count());
        $this->assertSame(3, DB::table('users')->where('status', 'deactivated')->count());
        $this->assertSame(1, DB::table('ownership_claims')->whereNull('consumed_at')->count());
        $beforeRepeat = $this->snapshot();
        $process->mustRun();
        $this->assertSame($beforeRepeat, $this->snapshot());
    }

    public function test_rollback_refuses_to_undo_the_consequential_auth_transition(): void
    {
        $this->seedLegacyUsers();
        $this->migrate();
        $before = $this->snapshot();
        try {
            Artisan::call('migrate:rollback', ['--database' => 'legacy_upgrade', '--force' => true, '--step' => 1]);
            $this->fail('The auth migration must refuse rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function migrate(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'legacy_upgrade', '--force' => true]), Artisan::output());
    }

    private function assertMigrationRefused(string $reason): void
    {
        try {
            $this->migrate();
            $this->fail('Expected migrate to reject unsafe legacy data.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
    }

    private function seedLegacyUsers(): void
    {
        foreach ([7 => 'Plain@Example.test', 43 => 'mfa@example.test', 91 => 'passkey@example.test', 125 => 'unconfirmed@example.test'] as $id => $email) {
            DB::table('users')->insert([
                'id' => $id, 'name' => 'Legacy '.$id, 'email' => $email,
                'password' => Hash::make('legacy-password'), 'remember_token' => 'old-remember-'.$id,
                'email_verified_at' => $id === 91 ? null : '2025-06-01 12:00:00',
                'created_at' => '2025-01-01 10:11:12', 'updated_at' => '2025-06-01 12:00:00',
                'two_factor_secret' => in_array($id, [43, 125], true) ? 'encrypted-secret-'.$id : null,
                'two_factor_recovery_codes' => $id === 43 ? 'encrypted-recovery-codes' : null,
                'two_factor_confirmed_at' => $id === 43 ? '2025-06-01 12:00:00' : null,
            ]);
        }
        DB::table('passkeys')->insert([
            'user_id' => 91, 'name' => 'Security key', 'credential_id' => 'legacy-key',
            'credential' => '{"publicKey":"preserve-me","counter":42}', 'last_used_at' => '2025-06-01 12:00:00',
        ]);
        DB::table('sessions')->insert([
            'id' => 'legacy-session', 'user_id' => 7, 'payload' => base64_encode(serialize(['legacy' => true])),
            'last_activity' => time(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => 'Plain@Example.test', 'token' => Hash::make('legacy-reset-token'), 'created_at' => now(),
        ]);
    }

    private function seedSimulator(): void
    {
        $account = '11111111-1111-4111-8111-111111111111';
        $run = '22222222-2222-4222-8222-222222222222';
        $event = '33333333-3333-4333-8333-333333333333';
        DB::table('sesame_accounts')->insert([
            'id' => $account, 'name' => 'Existing staging account', 'access_key_id' => 'SESAMEEXISTING',
            'truth_token_hash' => str_repeat('a', 64), 'aws_account_number' => '123456789012',
            'topic_arn' => 'arn:aws:sns:us-east-1:123456789012:sesame', 'private_key' => 'encrypted-key-preserved',
            'certificate' => 'certificate-preserved', 'current_run_id' => $run,
        ]);
        DB::table('sesame_runs')->insert([
            'id' => $run, 'account_id' => $account, 'seed' => 987654321, 'preset' => 'healthy',
            'started_at' => now(), 'summary' => '{"accepted":1}',
        ]);
        DB::table('sesame_messages')->insert([
            'message_id' => 'original-message', 'account_id' => $account, 'run_id' => $run,
            'accepted_at' => now(), 'destination' => '["recipient@example.test"]', 'tags' => '{}',
            'headers' => '[]', 'common_headers' => '{}', 'raw_bytes' => 1024, 'outcomes' => '{"delivery":true}',
        ]);
        DB::table('sesame_events')->insert([
            'id' => $event, 'sns_message_id' => $event, 'account_id' => $account, 'run_id' => $run,
            'message_id' => 'original-message', 'type' => 'Delivery', 'due_at' => now(), 'created_at' => now(),
            'payload' => '{"eventType":"Delivery"}', 'attempts' => 1, 'last_error' => 'retry-me',
            'lease_token' => $event, 'lease_until' => now()->addMinute(),
        ]);
        DB::table('sesame_delivery_attempts')->insert([
            'event_id' => $event, 'attempt' => 1, 'started_at' => now(), 'duration_ms' => 12.5, 'http_status' => 503,
        ]);
        DB::table('sesame_send_refusals')->insert(['account_id' => $account, 'run_id' => $run, 'kind' => 'throttled', 'at' => now(), 'id' => $event]);
        DB::table('sesame_buffers')->insert([
            'id' => $event, 'host' => 'retired-instance', 'opened_at' => now(), 'last_seen_at' => now(),
            'drained_through_seq' => 123, 'drained_sends' => 100,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table): array
    {
        return array_map(fn (object $row): array => (array) $row, DB::table($table)->orderByRaw('1')->get()->all());
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(string $pattern = '%'): array
    {
        $snapshot = [];
        foreach (DB::select("SELECT schemaname, tablename FROM pg_tables WHERE schemaname IN ('public', 'sesame_legacy_auth') AND tablename LIKE ? ORDER BY schemaname, tablename", [$pattern]) as $table) {
            $name = $table->schemaname.'.'.$table->tablename;
            $snapshot[$name] = $this->rows($name);
        }

        return $snapshot;
    }

    /**
     * Exact original auth migration sources from git show HEAD:<path> at
     * b1b376ca7675b6a69fc658460337609dc0102168. Embedded so the regression
     * survives deletion of those files, a new HEAD, and shallow CI checkouts.
     *
     * @return array<string, string>
     */
    private function originalAuthMigrations(): array
    {
        return [
            '0001_01_01_000000_create_users_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
PHP,
            '2024_01_01_000000_create_passkeys_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
PHP,
            '2025_08_14_170933_add_two_factor_columns_to_users_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->after('password')->nullable();
            $table->text('two_factor_recovery_codes')->after('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
PHP,
        ];
    }
}
