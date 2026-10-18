<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Accounting\Scopes\EntityScope;
use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxPeriodEvent;
use App\Modules\Finance\Tax\Models\TaxReconciliation;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use App\Modules\Finance\Tax\Models\TaxType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tax periods (§9): generated from each registration's own filing frequency, with filing and
 * payment due dates from the tax type's rules, and a controlled lifecycle.
 *
 * Periods are independent of fiscal periods (TAX-PER-010): closing a month in the ledger does not
 * close a VAT period, and the reverse.
 */
class TaxPeriodService
{
    /** Allowed moves. Anything else is refused; reopening has its own method. */
    private const TRANSITIONS = [
        'open' => ['in_preparation'],
        'in_preparation' => ['under_review', 'open'],
        'under_review' => ['approved', 'in_preparation'],
        'approved' => ['submitted', 'in_preparation'],
        'submitted' => ['accepted', 'rejected'],
        'rejected' => ['in_preparation'],
        'accepted' => ['closed'],
        'closed' => ['locked'],
        'locked' => [],
    ];

    public function __construct(private readonly TaxAudit $audit)
    {
    }

    /**
     * Create the periods a registration should have, from its effective date to $until (default:
     * the end of next month). Existing periods are left alone; nothing is generated for a
     * registration whose applicability has not been confirmed (TAX-REG-003).
     */
    public function generate(TaxRegistration $registration, ?Carbon $until = null): int
    {
        if (! $registration->applicability_confirmed || $registration->status !== 'active') {
            return 0;
        }

        $type = $registration->taxType;
        $until ??= now()->addMonthNoOverflow()->endOfMonth();
        $stop = $registration->effective_to && $registration->effective_to->lt($until) ? $registration->effective_to->copy() : $until;
        $created = 0;

        foreach ($this->windows($registration, $registration->effective_from->copy(), $stop) as [$start, $end]) {
            $exists = TaxPeriod::query()->withoutGlobalScopes()->where('registration_id', $registration->id)
                ->whereDate('start_date', $start->toDateString())->exists();
            if ($exists) {
                continue;
            }

            DB::transaction(function () use ($registration, $type, $start, $end, &$created) {
                $filing = $this->dueDate($type->filing_due_rule ?? [], $end);
                $payment = ($type->payment_due_rule['rule'] ?? null) === 'same_as_filing' ? $filing : $this->dueDate($type->payment_due_rule ?? [], $end);

                $period = TaxPeriod::create([
                    'entity_id' => $registration->entity_id,
                    'company_id' => $registration->company_id,
                    'registration_id' => $registration->id,
                    'tax_type_id' => $type->id,
                    'label' => $this->label($start, $end),
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'filing_due_date' => $filing?->toDateString(),
                    'payment_due_date' => $payment?->toDateString(),
                    'status' => 'open',
                ]);
                $this->event($period, null, 'open', 'Generated from the ' . str_replace('_', ' ', $registration->filing_frequency) . ' filing frequency.');
                app(ComplianceCalendarService::class)->forPeriod($period);
                $created++;
            });
        }

        if ($created) {
            $this->audit->record('periods.generated', $registration, ['tax_type' => $type->code, 'after' => ['created' => $created]]);
        }

        return $created;
    }

    /**
     * The [start, end] windows of a frequency between two dates. Period ends fall on the months the
     * frequency fixes — even months for Category B VAT, odd for A, the year end and every N months
     * from it for four-, six-monthly and annual — and the first window starts on the registration
     * date if that is mid-period.
     *
     * @return list<array{0:Carbon,1:Carbon}>
     */
    public function windows(TaxRegistration $registration, Carbon $from, Carbon $until): array
    {
        [$months, $anchor] = match ($registration->filing_frequency) {
            'monthly' => [1, 1],
            'bi_monthly_even' => [2, 2],
            'bi_monthly_odd' => [2, 1],
            'four_monthly' => [4, $registration->year_end_month ?? 2],
            'six_monthly' => [6, $registration->year_end_month ?? 2],
            'annual' => [12, $registration->year_end_month ?? 2],
            default => throw new TaxControlException('Unknown filing frequency: ' . $registration->filing_frequency),
        };

        // First period end on or after $from: the next month whose distance from the anchor is a multiple of $months.
        $end = $from->copy()->startOfMonth();
        while ((($end->month - $anchor) % $months + $months) % $months !== 0) {
            $end->addMonthNoOverflow();
        }
        $end = $end->endOfMonth();

        $windows = [];
        while (true) {
            $start = $end->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();
            if ($start->gt($until)) {
                break;
            }
            $windows[] = [$start->lt($from) ? $from->copy()->startOfDay() : $start, $end->copy()->startOfDay()];
            $end = $end->copy()->addMonthsNoOverflow($months)->endOfMonth();
        }

        return $windows;
    }

    /**
     * A due date from a tax type's rule. Weekends are handled; public holidays are not — they are
     * not in the system, so a deadline that lands on one has to be corrected on the calendar, which
     * is why calendar deadlines are editable with a reason (§14).
     */
    public function dueDate(array $rule, Carbon $periodEnd): ?Carbon
    {
        $date = match ($rule['rule'] ?? null) {
            'day_of_following_month' => ($rule['efiling'] ?? null) === 'last_business_day'
                ? $this->lastBusinessDay($periodEnd->copy()->addMonthNoOverflow()->endOfMonth())
                : $periodEnd->copy()->addMonthNoOverflow()->startOfMonth()->day(min((int) $rule['day'], $periodEnd->copy()->addMonthNoOverflow()->daysInMonth)),
            'months_after_period_end' => $periodEnd->copy()->addMonthsNoOverflow((int) $rule['months'])->endOfMonth(),
            'last_day_of_period' => $periodEnd->copy()->endOfMonth(),
            default => null,
        };

        if ($date && ($rule['shift'] ?? null) === 'previous_business_day') {
            while ($date->isWeekend()) {
                $date->subDay();
            }
        }

        return $date?->startOfDay();
    }

    /**
     * Move a period through its lifecycle, with the controls TAX-PER-005 asks for.
     */
    public function transition(TaxPeriod $period, string $to, ?string $reason = null, array $evidence = []): TaxPeriod
    {
        $from = $period->status;
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new TaxControlException("A period that is " . str_replace('_', ' ', $from) . ' cannot move to ' . str_replace('_', ' ', $to) . '.');
        }

        $this->guard($period, $to, $reason, $evidence);

        return DB::transaction(function () use ($period, $from, $to, $reason, $evidence) {
            $period->status = $to;
            if ($to === 'locked') {
                $period->locked_at = now();
                $period->locked_by = Auth::id();
            }
            $period->save();
            $this->event($period, $from, $to, $reason, $evidence);
            $this->audit->record('period.' . $to, $period, ['period_id' => $period->id, 'tax_type' => $period->taxType?->code,
                'reason' => $reason, 'before' => ['status' => $from], 'after' => ['status' => $to] + ($evidence ? ['evidence' => $evidence] : [])]);

            return $period;
        });
    }

    /**
     * TAX-PER-006/007/008: reopening a closed or locked period is a separate, permissioned act with
     * a reason. What was recorded stays — the register keeps its versions and the period's event
     * history keeps the earlier close — and processing resumes in preparation.
     */
    public function reopen(TaxPeriod $period, string $reason): TaxPeriod
    {
        if (! in_array($period->status, ['closed', 'locked', 'accepted', 'submitted'], true)) {
            throw new TaxControlException('Only a submitted, accepted, closed or locked period is reopened.');
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new TaxControlException('Give the reason for reopening (at least 10 characters). It is kept on the audit trail.');
        }

        return DB::transaction(function () use ($period, $reason) {
            $from = $period->status;
            $period->forceFill(['status' => 'in_preparation', 'locked_at' => null, 'locked_by' => null,
                'reopen_count' => $period->reopen_count + 1])->save();
            $this->event($period, $from, 'in_preparation', 'Reopened: ' . $reason);
            $this->audit->record('period.reopened', $period, ['period_id' => $period->id, 'tax_type' => $period->taxType?->code,
                'reason' => $reason, 'before' => ['status' => $from], 'after' => ['status' => 'in_preparation', 'reopen_count' => $period->reopen_count]]);

            return $period;
        });
    }

    /** Refuse register changes to a period that is past preparation (TAX-PER-006). */
    public function assertProcessable(TaxPeriod $period): void
    {
        if (! in_array($period->status, ['open', 'in_preparation', 'rejected'], true)) {
            throw new TaxControlException("{$period->label} is " . str_replace('_', ' ', $period->status)
                . '. Its figures cannot change unless an authorised user reopens it.');
        }
    }

    /** @return list<string> what stops this period from being approved. */
    public function blockers(TaxPeriod $period): array
    {
        $blockers = [];
        $registration = $period->registration;
        foreach (app(TaxRegistrationService::class)->missingDetails($registration) as $missing) {
            $blockers[] = "Registration needs {$missing}";
        }

        $reconType = ['VAT' => 'vat_register_to_gl', 'PAYE' => 'payroll_to_gl'][$period->taxType?->code] ?? null;
        if ($reconType) {
            $exceptions = TaxTransaction::query()->where('period_id', $period->id)->current()->where('has_exception', true)->count();
            if ($exceptions) {
                $blockers[] = "{$exceptions} register line(s) have unresolved exceptions";
            }
            $recon = TaxReconciliation::query()->where('period_id', $period->id)->where('type', $reconType)->orderByDesc('version')->first();
            $what = $reconType === 'payroll_to_gl' ? 'The payroll taxes have not been reconciled to the ledger' : 'The VAT register has not been reconciled to the control accounts';
            if (! $recon) {
                $blockers[] = $what;
            } elseif ($recon->status !== 'signed_off') {
                $blockers[] = $reconType === 'payroll_to_gl' ? 'The latest payroll reconciliation is not signed off' : 'The latest VAT reconciliation is not signed off';
            }
        }

        return $blockers;
    }

    private function guard(TaxPeriod $period, string $to, ?string $reason, array $evidence): void
    {
        if ($to === 'approved') {
            $blockers = $this->blockers($period);
            if ($blockers) {
                throw new TaxControlException('This period cannot be approved yet: ' . implode('; ', $blockers) . '.');
            }
            // Segregation of duties: whoever sent it for review does not approve it.
            $sentBy = TaxPeriodEvent::where('period_id', $period->id)->where('to_status', 'under_review')->orderByDesc('id')->value('user_id');
            if ($sentBy && $sentBy === Auth::id()) {
                $this->audit->denied('period.approve', $period, 'Self-approval refused (segregation of duties).', ['period_id' => $period->id]);
                throw new TaxControlException('You sent this period for review, so someone else has to approve it.');
            }
        }

        // TAX-SUB-010: "accepted" is recorded from evidence, never inferred from having sent something.
        if (in_array($to, ['submitted', 'accepted', 'rejected'], true) && blank($evidence['reference'] ?? null)) {
            throw new TaxControlException('Record the SARS reference or acknowledgement for this step.');
        }
        if (in_array($to, ['in_preparation', 'rejected'], true) && in_array($period->status, ['under_review', 'approved', 'submitted'], true) && blank($reason)) {
            throw new TaxControlException('Say why it is going back.');
        }
    }

    private function event(TaxPeriod $period, ?string $from, string $to, ?string $reason, array $evidence = []): void
    {
        TaxPeriodEvent::create([
            'company_id' => $period->company_id, 'period_id' => $period->id, 'from_status' => $from, 'to_status' => $to,
            'reason' => trim(($reason ?? '') . ($evidence ? ' [ref ' . ($evidence['reference'] ?? '') . ']' : '')) ?: null,
            'user_id' => Auth::id(), 'created_at' => now(),
        ]);
    }

    private function label(Carbon $start, Carbon $end): string
    {
        return $start->isSameMonth($end) ? $end->format('M Y') : $start->format('M') . '–' . $end->format('M Y');
    }

    private function lastBusinessDay(Carbon $date): Carbon
    {
        while ($date->isWeekend()) {
            $date->subDay();
        }

        return $date;
    }
}
