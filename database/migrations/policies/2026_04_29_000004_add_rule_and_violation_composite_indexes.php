<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_rules', function (Blueprint $table) {
            $table->index(['policy_id', 'active'], 'policy_rules_policy_active_idx');
        });

        Schema::table('policy_violations', function (Blueprint $table) {
            $table->index(['policy_id', 'user_id'], 'policy_violations_policy_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('policy_rules', function (Blueprint $table) {
            $table->dropIndex('policy_rules_policy_active_idx');
        });

        Schema::table('policy_violations', function (Blueprint $table) {
            $table->dropIndex('policy_violations_policy_user_idx');
        });
    }
};
