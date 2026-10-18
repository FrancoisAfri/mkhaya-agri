<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the bureau engine the audit trail the in-house one already had.
 *
 * `payroll_audit_logs` existed and was written to by PayrollService alone. The bureau engine — the
 * one that runs *other companies'* payrolls, generates their EMP201s and releases their payment
 * files — recorded nothing at all. Who processed a client's run, who unlocked it, who released the
 * money: none of it was answerable. For a service bureau that is not a missing feature, it is the
 * thing the client's auditor asks for first.
 *
 * One table for both engines rather than a second trail beside the first, for the same reason the
 * incentive ledger is shared: a compliance question is asked about a company, not about which of our
 * two engines happened to run it, and two tables means every report has to remember to union them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_audit_logs', function (Blueprint $table) {
            // Nulled rather than cascaded on delete: removing a run must not erase the record that
            // somebody released a payment from it.
            $table->foreignId('bureau_pay_run_id')->nullable()->after('run_id')
                ->constrained('bureau_pay_runs')->nullOnDelete();

            // The client the action was taken against. Kept even when the run is gone, because
            // "show me everything done to this client's payroll" is the question a bureau is
            // actually asked, and it must survive a deleted run.
            $table->unsignedBigInteger('bureau_company_id')->nullable()->after('bureau_pay_run_id');

            $table->index(['bureau_company_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['bureau_company_id', 'created_at']);
            $table->dropIndex(['action', 'created_at']);
            $table->dropConstrainedForeignId('bureau_pay_run_id');
            $table->dropColumn('bureau_company_id');
        });
    }
};
