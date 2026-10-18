<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add two_factor_secret to users for TwoFactor middleware
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
        });

        // Add force_change and expires_at to password_securities for SecurityCheck middleware
        Schema::table('password_securities', function (Blueprint $table) {
            $table->boolean('force_change')->default(false)->after('password_expiry_at');
            $table->timestamp('expires_at')->nullable()->after('force_change');
        });
    }

    public function down(): void
    {
        Schema::table('password_securities', function (Blueprint $table) {
            $table->dropColumn(['force_change', 'expires_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_secret');
        });
    }
};
