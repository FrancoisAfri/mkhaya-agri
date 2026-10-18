<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A pay run's salaries are released once.
 *
 * `DisbursementService::submitRun()` checked that the run was finalised and then sent the batch. It
 * never asked whether this run had already been sent, no idempotency key went to the provider, and
 * `bureau_disbursements` carried a foreign key on `bureau_pay_run_id` but no unique constraint. So
 * pressing the button twice — or retrying after a timeout on a request that had in fact
 * succeeded — paid every employee in the run a second time.
 *
 * The service refuses a second send now. This index is the backstop, because the service check is
 * a read followed by a write and two concurrent requests can both pass it. Only the database can
 * settle that race, and it is the last thing standing between a double click and a double payroll.
 *
 * ── Why it is partial ────────────────────────────────────────────────────────
 *
 * A disbursement that genuinely failed has to be retryable: the provider rejected the batch, no
 * money moved, and the operator must be able to fix the cause and send again. So the constraint
 * binds only rows that are not `failed`. Every other state — submitted, processing, paid — means a
 * batch is with the provider and must never be joined by a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bureau_disbursements')) {
            return;
        }

        /*
         * Existing duplicates are kept but stood down to 'failed' so the index can be created.
         *
         * Deleting them would erase the evidence of a double payment, which is the opposite of what
         * anybody wants here — the audit trail is how it gets reconciled with the bank. The oldest
         * live row per run keeps its status; later ones are marked so they no longer claim to be
         * in flight, and the warning below says where to look.
         */
        $duplicates = DB::table('bureau_disbursements')
            ->select('bureau_pay_run_id', DB::raw('count(*) as n'))
            ->where('status', '<>', 'failed')
            ->groupBy('bureau_pay_run_id')
            ->havingRaw('count(*) > 1')
            ->pluck('n', 'bureau_pay_run_id');

        foreach ($duplicates as $runId => $count) {
            $keep = DB::table('bureau_disbursements')
                ->where('bureau_pay_run_id', $runId)->where('status', '<>', 'failed')
                ->orderBy('id')->value('id');

            DB::table('bureau_disbursements')
                ->where('bureau_pay_run_id', $runId)->where('status', '<>', 'failed')
                ->where('id', '<>', $keep)
                ->update(['status' => 'failed']);

            logger()->warning('pay run had more than one live disbursement — later ones stood down', [
                'bureau_pay_run_id' => $runId,
                'count' => $count,
                'kept' => $keep,
                'action' => 'reconcile against the bank statement: money may have moved twice',
            ]);
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS bureau_disbursements_one_live_per_run'
            . " ON bureau_disbursements (bureau_pay_run_id) WHERE status <> 'failed'",
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('bureau_disbursements')) {
            DB::statement('DROP INDEX IF EXISTS bureau_disbursements_one_live_per_run');
        }
    }
};
