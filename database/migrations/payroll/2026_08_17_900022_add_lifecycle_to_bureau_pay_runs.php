<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The states a client's payroll actually passes through, and who moved it.
 *
 * There were three — draft, review, finalised — and finalising did four separate jobs at once:
 * approving the figures, locking the payslips, and implicitly clearing the run for payment. So
 * whoever calculated a run could also approve it, lock it and release the money, with nothing
 * recorded but a `finalised_at` timestamp and no name against the decision. For a bureau paying
 * other people's staff that is the whole control missing.
 *
 * Approval becomes its own act, with the approver and the moment they approved kept on the run —
 * which is what a client's auditor asks for, and what makes "who signed this off" answerable at all.
 * Payment and completion get their own states too, so a run that was locked but never paid is
 * distinguishable from one that was, which is exactly the gap the bureau dashboard reads.
 *
 * `reopened_at` and `reopen_reason` are for the case the requirement calls out: a completed payroll
 * that has to be changed. It is not made editable again quietly — the reason is required, it is
 * recorded, and it stays on the run afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bureau_pay_runs', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('finalised_at');

            // The signature. Nulled rather than cascaded if the user is deleted: losing the account
            // must not erase the fact that somebody approved a payment.
            $table->foreignId('approved_by')->nullable()->after('processed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->timestamp('paid_at')->nullable()->after('approved_at');
            $table->timestamp('completed_at')->nullable()->after('paid_at');

            // Kept after the fact, not cleared on the next approval — a run that was reopened once
            // is a run somebody will ask about.
            $table->timestamp('reopened_at')->nullable()->after('completed_at');
            $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            $table->string('reopen_reason')->nullable()->after('reopened_by');
        });

        // Runs finalised before approval existed have no approver, and inventing one would be a lie
        // on an audit record. They are left as they are: finalised, with a null approver, which
        // reads correctly as "approved under the old process".
        DB::table('bureau_pay_runs')
            ->whereNotNull('finalised_at')
            ->whereNull('processed_at')
            ->update(['processed_at' => DB::raw('finalised_at')]);
    }

    public function down(): void
    {
        Schema::table('bureau_pay_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn(['processed_at', 'approved_at', 'paid_at', 'completed_at', 'reopened_at', 'reopen_reason']);
        });
    }
};
