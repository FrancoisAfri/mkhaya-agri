<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI account-health: a churn/expansion score fused from NPS, complaints,
 * activity and deal signals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_accounts', 'health_score')) {
                $table->unsignedTinyInteger('health_score')->nullable();
            }
            if (! Schema::hasColumn('crm_accounts', 'health_status')) {
                $table->string('health_status', 12)->nullable(); // healthy|at_risk|critical|growth
            }
            if (! Schema::hasColumn('crm_accounts', 'churn_risk')) {
                $table->string('churn_risk', 8)->nullable();      // low|medium|high
            }
            if (! Schema::hasColumn('crm_accounts', 'health_summary')) {
                $table->text('health_summary')->nullable();
            }
            if (! Schema::hasColumn('crm_accounts', 'health_assessed_at')) {
                $table->timestamp('health_assessed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_accounts', function (Blueprint $table) {
            $table->dropColumn(['health_score', 'health_status', 'churn_risk', 'health_summary', 'health_assessed_at']);
        });
    }
};
