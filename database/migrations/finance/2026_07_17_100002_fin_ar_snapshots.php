<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily accounts-receivable snapshot per company. Powers the DSO/CEI trend and the ageing-trend
 * chart on the Debtors Manager (metrics that need period-over-period beginning/ending balances,
 * which cannot be reconstructed from live data alone). Written once a day by finance:send-reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fin_ar_snapshots')) {
            return;
        }
        Schema::create('fin_ar_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->date('as_of')->index();
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('overdue', 15, 2)->default(0);
            $table->decimal('current', 15, 2)->default(0);
            $table->decimal('b_1_30', 15, 2)->default(0);
            $table->decimal('b_31_60', 15, 2)->default(0);
            $table->decimal('b_61_90', 15, 2)->default(0);
            $table->decimal('b_91_120', 15, 2)->default(0);
            $table->decimal('b_120_plus', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_ar_snapshots');
    }
};
