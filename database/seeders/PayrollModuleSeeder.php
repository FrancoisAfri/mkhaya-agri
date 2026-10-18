<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Payroll Module Seeder — Human Resources Category
 *
 * Orchestrates all payroll seed data in dependency order:
 *   1. PayrollAnomalyRulesSeeder  — 9 firewall detection rules (Phase 2 config)
 *   2. PayrollPolicyRulesSeeder   — 6 policy engine rules (block/escalate/warn)
 *   3. PayrollPhase1Seeder        — settings, periods, runs, payslips, approvals, audit logs
 *   4. PayrollPhase2Seeder        — demo anomalies, risk scores, run risk level update
 */
class PayrollModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PayrollAnomalyRulesSeeder::class,  // Phase 2 rules must exist before anomaly data
            PayrollPolicyRulesSeeder::class,   // Policy engine rules for submit/release guards
            PayrollPhase1Seeder::class,        // Core data: settings → periods → runs → payslips
            PayrollPhase2Seeder::class,        // Firewall data: anomalies → risk scores
            PayrollRibbonSeeder::class,        // Navigation ribbons for all payroll pages
        ]);
    }
}
