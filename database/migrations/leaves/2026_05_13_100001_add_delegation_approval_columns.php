<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_delegations', function (Blueprint $table) {
            // 'approved' default keeps existing rows working without re-approval
            $table->string('approval_status', 20)->default('approved')->after('is_active');
            $table->text('decline_reason')->nullable()->after('approval_status');
        });

        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->boolean('require_delegation_approval')->default(false)->after('allow_delegation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leave_delegations', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'decline_reason']);
        });

        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->dropColumn('require_delegation_approval');
        });
    }
};
