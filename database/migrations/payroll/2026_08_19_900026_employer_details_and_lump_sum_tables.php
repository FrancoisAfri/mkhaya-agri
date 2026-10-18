<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The employer's own details, and the lump-sum tables that were empty.
 *
 * Two defects rather than gaps.
 *
 * The in-house payslip had no employer to name — the view was never passed one, so the template
 * carried the literal string "MkhayaHub" and every payslip named the software vendor instead of the
 * employer. BCEA §33(1)(a) wants the employer's name and address; neither was anywhere to be found,
 * because `companies` holds no address and `payroll_settings` held no statutory references.
 *
 * And `severancePaye()` / `withdrawalPaye()` read rate tables from the tax year that were never
 * seeded. An empty table returns zero, so a retrenchment payment was taxed at nothing at all. It
 * failed silently — no error, no warning — and SARS collects the shortfall from the employer later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            // What the payslip has to state about the employer, and what SARS returns are filed
            // under. Kept beside the payroll configuration rather than on `companies`, because a
            // tenant is a customer record and this is payroll's own reference data.
            $table->string('employer_name')->nullable()->after('currency');
            $table->string('employer_trading_name')->nullable()->after('employer_name');
            $table->text('employer_address')->nullable()->after('employer_trading_name');
            $table->string('paye_reference', 30)->nullable()->after('employer_address');
            $table->string('uif_reference', 30)->nullable()->after('paye_reference');
            $table->string('sdl_reference', 30)->nullable()->after('uif_reference');
            $table->string('employer_contact')->nullable()->after('sdl_reference');
        });

        // Fall back to the tenant's own name so no payslip is nameless while somebody fills the
        // rest in. Better than "MkhayaHub", and obviously wrong enough to prompt correction if the
        // tenant record is a placeholder.
        DB::statement("
            UPDATE payroll_settings ps
               SET employer_name = c.name
              FROM companies c
             WHERE c.id = ps.company_id
               AND ps.employer_name IS NULL
        ");

        $this->seedLumpSumTables();
    }

    /**
     * The SARS lump-sum tables, on every tax year that has none.
     *
     * Two separate tables and they are not interchangeable: a retrenchment or retirement benefit is
     * taxed on the severance table, where the first R550 000 is free; a resignation withdrawal is
     * taxed on the withdrawal table, where only the first R27 500 is. Using the wrong one on a
     * retrenchment over-taxes somebody who has just lost their job.
     */
    private function seedLumpSumTables(): void
    {
        // [lower threshold, marginal rate above it, tax at the threshold]
        $severance = json_encode([
            [0, 0.00, 0],
            [550000, 0.18, 0],
            [770000, 0.27, 39600],
            [1155000, 0.36, 143550],
        ]);

        $withdrawal = json_encode([
            [0, 0.00, 0],
            [27500, 0.18, 0],
            [726000, 0.27, 125730],
            [1089000, 0.36, 223740],
        ]);

        DB::table('sa_tax_years')
            ->where(fn ($q) => $q->whereNull('lump_sum_severance')->orWhereRaw("lump_sum_severance::text IN ('[]','null','')"))
            ->update(['lump_sum_severance' => $severance]);

        DB::table('sa_tax_years')
            ->where(fn ($q) => $q->whereNull('lump_sum_withdrawal')->orWhereRaw("lump_sum_withdrawal::text IN ('[]','null','')"))
            ->update(['lump_sum_withdrawal' => $withdrawal]);
    }

    public function down(): void
    {
        Schema::table('payroll_settings', fn (Blueprint $t) => $t->dropColumn([
            'employer_name', 'employer_trading_name', 'employer_address',
            'paye_reference', 'uif_reference', 'sdl_reference', 'employer_contact',
        ]));
    }
};
