<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the users.password_changed_at column that the password-expiry policy
 * (CompanyIdentity.password_expiring_month) and the SecurityCheck middleware
 * rely on. It was referenced by the User model / PasswordChangeController but
 * no migration ever created it — so password changes errored and expiry never
 * tracked. Existing rows are backfilled to "now" so the policy starts cleanly
 * from deploy instead of retroactively forcing everyone to reset at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('password_changed_at')->nullable()->after('password');
            });

            DB::table('users')->whereNull('password_changed_at')->update([
                'password_changed_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('password_changed_at');
            });
        }
    }
};
