<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ExpenseSink;
use App\Modules\Operations\ProjectManagement\Services\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where an approved expense goes when Accounting is not installed (PM-1411).
 *
 * It stays in PM: recorded, countable towards actual cost, and exportable. Nothing is lost, and
 * nothing is invented — no journal is posted, because there is no ledger to post to, and
 * `postsToLedger()` says so rather than letting a screen imply the books have been updated.
 *
 * The event is published either way, so activating Accounting later gives the adapter a complete
 * history to work from.
 */
class LocalExpenseSink implements ExpenseSink
{
    public function __construct(private readonly Outbox $outbox)
    {
    }

    public function post(array $expense): string
    {
        $reference = 'PM-EXP-' . Str::upper(Str::random(8));

        DB::transaction(function () use ($expense, $reference) {
            $this->outbox->publish('pm.expense.recorded', 'expense', $expense['uuid'] ?? null, [
                'reference' => $reference,
                'expense'   => $expense,
                'posted_to' => 'local',
            ]);
        });

        return $reference;
    }

    public function postsToLedger(): bool
    {
        return false;
    }
}
