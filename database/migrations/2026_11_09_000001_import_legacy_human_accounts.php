<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sesame_legacy_auth.users')) {
            return;
        }

        $hasPasskeys = Schema::hasTable('sesame_legacy_auth.passkeys');

        foreach (DB::table('sesame_legacy_auth.users')->orderBy('id')->cursor() as $user) {
            // The package does not implement Fortify's MFA/passkeys. Never
            // silently downgrade an enrolled user's authentication to password
            // alone. Keep the original credentials in the archive for review.
            $protected = ! empty($user->two_factor_secret)
                || ($hasPasskeys && DB::table('sesame_legacy_auth.passkeys')->where('user_id', $user->id)->exists());

            DB::table('users')->insert([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'password' => $user->password,
                'role' => 'member',
                'status' => $protected ? 'deactivated' : 'active',
                'deactivated_at' => $protected ? now() : null,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]);
        }

        // Explicit IDs do not advance PostgreSQL's identity sequence.
        DB::statement("SELECT setval(pg_get_serial_sequence('users', 'id'), coalesce(max(id), 1), max(id) IS NOT NULL) FROM users");
    }

    public function down(): void
    {
        throw new RuntimeException('The auth transition is forward-only. Restore a pre-upgrade backup with the previous release.');
    }
};
