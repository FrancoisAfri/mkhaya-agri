<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-110 to WMS-114: the warehouse Profit Protection Engine runs on Manufacturing's loss findings
 * and investigations (MFG-012) rather than beside them -- one place where a loss is detected,
 * explained, investigated, verified, corrected and measured.
 *
 * A finding learns its priority, and the rule as it stood when the finding was raised: thresholds
 * change, and an investigation must be able to show what it was judged against at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mfg_loss_findings', function (Blueprint $t) {
            $t->string('priority', 8)->nullable(); // LOW|MEDIUM|HIGH|URGENT
            $t->jsonb('rule_snapshot')->nullable();
            $t->string('origin', 8)->default('RULE'); // RULE|MANUAL
            $t->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mfg_loss_findings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('warehouse_id');
            $t->dropColumn(['priority', 'rule_snapshot', 'origin']);
        });
    }
};
