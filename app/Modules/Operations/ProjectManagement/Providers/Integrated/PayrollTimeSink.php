<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\TimeSink;
use App\Modules\Operations\ProjectManagement\Models\OutboxMessage;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;
use Illuminate\Support\Str;

/**
 * Where approved project hours go when Payroll is installed (`hr.labour_rates`).
 *
 * It publishes rather than writes. Payroll runs on periods, approvals and a release step of its
 * own, and a project module reaching into `payroll_earnings_lines` to add an hour would be writing
 * into the middle of somebody else's process — past their validation, their approval chain and
 * their audit trail.
 *
 * So the hours are emitted as a domain event on PM's transactional outbox (PM-ADR-008), which is
 * at-least-once and deduplicated on the message uuid. Payroll consumes it when and how its own
 * rules allow.
 *
 * `feedsPayroll()` is therefore *true* — the hours do reach payroll — and the screens that say
 * "these hours will be paid" are telling the truth for the first time.
 */
class PayrollTimeSink implements TimeSink
{
    public function post(array $entry): string
    {
        $reference = 'PM-TIME-' . Str::upper(Str::random(8));

        OutboxMessage::create([
            'uuid'         => (string) Str::uuid(),
            'event_type'   => 'pm.time.approved',
            'subject_type' => 'time_entry',
            'subject_uuid' => $entry['uuid'] ?? null,
            'payload'      => [
                'reference' => $reference,
                'entry'     => $entry,
                'posted_to' => 'payroll',
            ],
        ]);

        return $reference;
    }

    /**
     * True — and it has to be earned rather than asserted.
     *
     * If Payroll's tables are not there, the event would have no consumer and a screen promising
     * that hours reach payroll would be misleading. So the answer is the state of the world, not a
     * constant.
     */
    public function feedsPayroll(): bool
    {
        return ModuleTable::ready('payroll_runs', ['id']) && ModuleTable::ready('payslips', ['employee_id']);
    }
}
