<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SARS retirement / severance lump-sum tax table (the R550k-tax-free table used
 * for retrenchment severance + retirement lump sums) and the withdrawal table
 * (resignation). Added per tax year; seeded for the existing years.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sa_tax_years', function (Blueprint $table) {
            $table->json('lump_sum_severance')->nullable();   // retirement & severance benefits
            $table->json('lump_sum_withdrawal')->nullable();  // withdrawal (resignation)
        });

        // [lower bound, marginal rate, cumulative tax at lower bound]
        $severance = [
            [0,       0.00, 0],
            [550000,  0.18, 0],
            [770000,  0.27, 39600],
            [1155000, 0.36, 143550],
        ];
        $withdrawal = [
            [0,       0.00, 0],
            [27500,   0.18, 0],
            [726000,  0.27, 125730],
            [1089000, 0.36, 223740],
        ];

        DB::table('sa_tax_years')->update([
            'lump_sum_severance'  => json_encode($severance),
            'lump_sum_withdrawal' => json_encode($withdrawal),
        ]);
    }

    public function down(): void
    {
        Schema::table('sa_tax_years', fn (Blueprint $t) => $t->dropColumn(['lump_sum_severance', 'lump_sum_withdrawal']));
    }
};
