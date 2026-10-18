<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `payroll_simulations.simulation_type` CHECK constraint listed short names
 * (bonus, new_hire, overtime, allowance) but PayrollSimulationService actually
 * stores the descriptive names (bonus_payment, new_hiring_cost, overtime_cost,
 * allowance_change). That mismatch made 4 of the 7 simulation types fail with a
 * 23514 check violation. Align the constraint to the values the service stores.
 */
return new class extends Migration
{
    private array $types = [
        'salary_increase',
        'bonus_payment',
        'new_hiring_cost',
        'overtime_cost',
        'allowance_change',
        'union_increase',
        'annual_budget',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE payroll_simulations DROP CONSTRAINT IF EXISTS payroll_simulations_simulation_type_check');
        $list = "'" . implode("','", $this->types) . "'";
        DB::statement("ALTER TABLE payroll_simulations ADD CONSTRAINT payroll_simulations_simulation_type_check CHECK (simulation_type::text = ANY (ARRAY[{$list}]::text[]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payroll_simulations DROP CONSTRAINT IF EXISTS payroll_simulations_simulation_type_check');
        $old = "'salary_increase','bonus','new_hire','overtime','allowance','union_increase','annual_budget'";
        DB::statement("ALTER TABLE payroll_simulations ADD CONSTRAINT payroll_simulations_simulation_type_check CHECK (simulation_type::text = ANY (ARRAY[{$old}]::text[]))");
    }
};
