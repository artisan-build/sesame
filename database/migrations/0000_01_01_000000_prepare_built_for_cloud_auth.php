<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Before the package's canonical users migration, on both fresh and upgraded
// databases. Sesame's supported persistent store is PostgreSQL.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'auth_session_version')) {
            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Existing Sesame installations must be upgraded on PostgreSQL.');
        }

        if (Schema::hasTable('bfc_authority') || Schema::hasTable('sesame_legacy_auth.users')) {
            throw new RuntimeException('Mixed legacy and Built for Cloud auth schemas require operator review.');
        }

        if (DB::table('users')->selectRaw('lower(email)')->groupByRaw('lower(email)')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Resolve case-insensitive duplicate user emails before upgrading Sesame.');
        }

        // Moving schemas preserves rows, sequences, indexes and passkey foreign
        // keys without colliding with the package's canonical table/index names.
        // Sessions and reset links are retained for recovery, never reactivated.
        DB::statement('CREATE SCHEMA sesame_legacy_auth');
        foreach (['users', 'passkeys', 'sessions', 'password_reset_tokens'] as $table) {
            if (Schema::hasTable($table)) {
                DB::statement('ALTER TABLE '.DB::getQueryGrammar()->wrapTable($table).' SET SCHEMA sesame_legacy_auth');
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The auth transition is forward-only. Restore a pre-upgrade backup with the previous release.');
    }
};
