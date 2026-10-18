<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('status');
            $table->boolean('is_master_admin')->default(false)->after('is_platform_admin');
            $table->foreignId('platform_admin_granted_by')->nullable()->constrained('users')->nullOnDelete()->after('is_master_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['platform_admin_granted_by']);
            $table->dropColumn(['is_platform_admin', 'is_master_admin', 'platform_admin_granted_by']);
        });
    }
};
