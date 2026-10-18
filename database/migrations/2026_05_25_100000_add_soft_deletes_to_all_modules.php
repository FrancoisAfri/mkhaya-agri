<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds deleted_at (soft delete) column to every module table that was missing it.
 * Only runs if the column does not already exist (safe to re-run).
 */
return new class extends Migration
{
    private array $tables = [
        // ── HR – Leave ────────────────────────────────────────────────────────
        'leave_types',
        'leave_application',

        // ── HR – Payroll ──────────────────────────────────────────────────────
        'payslips',
        'payroll_corrections',
        'payroll_anomalies',

        // ── HR – Recruitment ──────────────────────────────────────────────────
        'recruitment_applications',
        'candidates',

        // ── HR – Onboarding ───────────────────────────────────────────────────
        'onboarding_plans',
        'onboarding_tasks',
        'onboarding_documents',

        // ── Finance – Claims ──────────────────────────────────────────────────
        'claims',
        'claim_items',
        'claim_categories',

        // ── Finance – Petty Cash ──────────────────────────────────────────────
        'petty_cash_requests',
        'petty_cash_floats',

        // ── Finance – Staff Loan ──────────────────────────────────────────────
        'staff_loan_types',
        'staff_loan_approvals',

        // ── Policies ──────────────────────────────────────────────────────────
        'policy_exceptions',
        'policy_versions',
        'policy_violations',

        // ── Disciplinary ──────────────────────────────────────────────────────
        'disciplinary_warnings',
        'disciplinary_hearings',
        'disciplinary_appeals',

        // ── Assets ────────────────────────────────────────────────────────────
        'asset_assignments',
        'asset_maintenances',
        'asset_categories',
        'asset_documents',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (!Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
