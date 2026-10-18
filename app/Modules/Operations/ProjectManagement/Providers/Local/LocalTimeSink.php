<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\TimeSink;
use App\Modules\Operations\ProjectManagement\Services\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where approved hours go when Payroll is not installed (PM-1320).
 *
 * They stay in PM and count towards actual cost. They do not reach a payslip, and `feedsPayroll()`
 * returns false so nothing tells a supervisor that approving a timesheet will pay anyone.
 */
class LocalTimeSink implements TimeSink
{
    public function __construct(private readonly Outbox $outbox)
    {
    }

    public function post(array $entry): string
    {
        $reference = 'PM-TIME-' . Str::upper(Str::random(8));

        DB::transaction(function () use ($entry, $reference) {
            $this->outbox->publish('pm.time.approved', 'timesheet', $entry['uuid'] ?? null, [
                'reference' => $reference,
                'entry'     => $entry,
                'posted_to' => 'local',
            ]);
        });

        return $reference;
    }

    public function feedsPayroll(): bool
    {
        return false;
    }
}
