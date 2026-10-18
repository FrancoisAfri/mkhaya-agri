<?php

namespace App\Modules\Finance\Tax\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxComplianceDeadline;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Notifications\TaxDeadlineNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The compliance calendar (§14): filing and payment deadlines kept separately, reminders at the
 * configured intervals, escalation when a deadline passes undone, and an audited change history.
 */
class ComplianceCalendarService
{
    public const DEFAULT_REMINDER_DAYS = [14, 7, 3, 1];

    public function __construct(private readonly TaxAudit $audit)
    {
    }

    /** Filing and payment deadlines for a period, owned by the registration's preparer. Idempotent. */
    public function forPeriod(TaxPeriod $period): void
    {
        $registration = $period->registration;
        $type = $period->taxType;

        foreach (['filing' => $period->filing_due_date, 'payment' => $period->payment_due_date] as $kind => $due) {
            if (! $due || ($kind === 'payment' && ! $type->has_payment) || ($kind === 'filing' && ! $type->has_return)) {
                continue;
            }

            // A deadline that had already passed when the registration was confirmed belongs to
            // history (a registration back-dated to 2018, or imported): it is recorded, not chased.
            // Without this the next daily run escalates every one of them as overdue at once.
            $historical = $registration->confirmed_at && $due->lt($registration->confirmed_at->copy()->startOfDay());

            TaxComplianceDeadline::firstOrCreate(
                ['period_id' => $period->id, 'kind' => $kind],
                [
                    'company_id' => $period->company_id, 'entity_id' => $period->entity_id,
                    'tax_type_id' => $type->id, 'registration_id' => $registration->id,
                    'title' => "{$type->code} {$period->label} — " . ($kind === 'filing' ? 'return due' : 'payment due'),
                    'due_date' => $due, 'source' => 'rule', 'status' => $historical ? 'waived' : 'open',
                    'resolution_note' => $historical ? 'Before the registration was confirmed in Mkhaya Agri; not tracked here.' : null,
                    'owner_id' => $registration->preparer_id, 'reviewer_id' => $registration->reviewer_id,
                    'escalation_id' => $registration->escalation_id ?? $registration->reviewer_id,
                    'reminder_days' => app(\App\Modules\Finance\Tax\Support\TaxSettings::class)->get('reminder_days', $period->company_id) ?: self::DEFAULT_REMINDER_DAYS,
                ]
            );
        }
    }

    /** @param array<string, mixed> $data */
    public function createManual(array $data): TaxComplianceDeadline
    {
        return DB::transaction(function () use ($data) {
            $deadline = TaxComplianceDeadline::create([
                'entity_id' => $data['entity_id'] ?? null, 'tax_type_id' => $data['tax_type_id'] ?? null,
                'kind' => 'manual', 'title' => $data['title'], 'due_date' => $data['due_date'], 'source' => 'manual',
                'status' => 'open', 'owner_id' => $data['owner_id'] ?? null, 'reviewer_id' => $data['reviewer_id'] ?? null,
                'escalation_id' => $data['escalation_id'] ?? null,
                'reminder_days' => $data['reminder_days'] ?? self::DEFAULT_REMINDER_DAYS, 'created_by' => Auth::id(),
            ]);
            $this->audit->record('deadline.created', $deadline, ['after' => $deadline->only(['title', 'due_date', 'owner_id'])]);

            return $deadline;
        });
    }

    /** An official date changed, or the rule's date fell on a public holiday: move it, with a reason. */
    public function reschedule(TaxComplianceDeadline $deadline, Carbon|string $due, string $reason, bool $verified = false): TaxComplianceDeadline
    {
        if (trim($reason) === '') {
            throw new TaxControlException('Say why the deadline is moving — it is kept on the audit trail.');
        }

        return DB::transaction(function () use ($deadline, $due, $reason, $verified) {
            $before = ['due_date' => $deadline->due_date->toDateString(), 'source' => $deadline->source];
            $deadline->forceFill(['due_date' => Carbon::parse($due)->toDateString(), 'source' => $verified ? 'verified' : 'manual',
                'reminders_sent' => null, 'escalated_at' => null,
                'status' => $deadline->status === 'overdue' ? 'open' : $deadline->status])->save();
            $this->audit->record('deadline.rescheduled', $deadline, ['period_id' => $deadline->period_id, 'reason' => $reason,
                'before' => $before, 'after' => ['due_date' => $deadline->due_date->toDateString(), 'source' => $deadline->source]]);

            return $deadline;
        });
    }

    /** @param array<string, mixed> $assignees owner_id, reviewer_id, escalation_id */
    public function assign(TaxComplianceDeadline $deadline, array $assignees): TaxComplianceDeadline
    {
        return DB::transaction(function () use ($deadline, $assignees) {
            $fields = array_intersect_key($assignees, array_flip(['owner_id', 'reviewer_id', 'escalation_id']));
            $before = $deadline->only(array_keys($fields));
            $deadline->forceFill($fields)->save();
            $this->audit->record('deadline.assigned', $deadline, ['before' => $before, 'after' => $fields]);

            return $deadline;
        });
    }

    public function complete(TaxComplianceDeadline $deadline, string $note): TaxComplianceDeadline
    {
        return DB::transaction(function () use ($deadline, $note) {
            $before = ['status' => $deadline->status];
            $deadline->forceFill(['status' => 'done', 'completed_at' => now(), 'completed_by' => Auth::id(), 'resolution_note' => $note])->save();
            $this->audit->record('deadline.completed', $deadline, ['period_id' => $deadline->period_id, 'reason' => $note,
                'before' => $before, 'after' => ['status' => 'done', 'late' => $deadline->due_date->lt(today())]]);

            return $deadline;
        });
    }

    /**
     * §14 milestones: when the work a deadline stands for is done elsewhere — a return submitted, a
     * liability paid — the deadline is completed with a note saying how. A deadline already done
     * or waived is left as it is.
     */
    public function completeFor(int $periodId, string $kind, string $note): void
    {
        $deadline = TaxComplianceDeadline::query()->where('period_id', $periodId)->where('kind', $kind)
            ->whereIn('status', ['open', 'in_progress', 'overdue'])->first();
        if ($deadline) {
            $this->complete($deadline, $note);
        }
    }

    /**
     * The daily pass: reminders at each configured interval (once each), and on the first day
     * after the due date the deadline turns overdue and its escalation contact is told — once.
     *
     * @return array{reminders:int, escalations:int}
     */
    public function run(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $reminders = 0;
        $escalations = 0;

        $open = TaxComplianceDeadline::query()->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class)
            ->whereIn('status', ['open', 'in_progress', 'overdue'])->get();

        foreach ($open as $deadline) {
            $daysLeft = (int) $today->diffInDays($deadline->due_date, false);

            if ($daysLeft < 0) {
                if (! $deadline->escalated_at) {
                    $deadline->forceFill(['status' => 'overdue', 'escalated_at' => now()])->save();
                    $to = array_unique(array_filter([$deadline->escalation_id, $deadline->owner_id]));
                    $this->notify($to, new TaxDeadlineNotification($deadline, 'escalation', $daysLeft));
                    $this->audit->record('deadline.breached', $deadline, ['period_id' => $deadline->period_id, 'service' => 'tax:deadlines',
                        'after' => ['days_overdue' => -$daysLeft, 'notified' => $to]]);
                    app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.deadline.breached', ['deadline_id' => $deadline->id, 'company_id' => $deadline->company_id]);
                    $escalations++;
                }

                continue;
            }

            $sent = $deadline->reminders_sent ?? [];
            foreach ($deadline->reminder_days ?? self::DEFAULT_REMINDER_DAYS as $offset) {
                $offset = (int) $offset;
                if ($daysLeft <= $offset && ! isset($sent[(string) $offset])) {
                    // One reminder per pass even if several thresholds are crossed at once (a
                    // deadline created five days out should not send the 14- and 7-day reminders together).
                    foreach ($deadline->reminder_days ?? self::DEFAULT_REMINDER_DAYS as $o) {
                        if ((int) $o >= $daysLeft) {
                            $sent[(string) $o] = now()->toIso8601String();
                        }
                    }
                    $deadline->forceFill(['reminders_sent' => $sent])->save();
                    $this->notify(array_filter([$deadline->owner_id]), new TaxDeadlineNotification($deadline, 'reminder', $daysLeft));
                    app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.deadline.approaching', ['deadline_id' => $deadline->id, 'company_id' => $deadline->company_id, 'days_left' => $daysLeft]);
                    $reminders++;
                    break;
                }
            }
        }

        return ['reminders' => $reminders, 'escalations' => $escalations];
    }

    private function notify(array $userIds, TaxDeadlineNotification $notification): void
    {
        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            try {
                $user->notify($notification);
            } catch (\Throwable $e) {
                // A mail failure must not stop the database notification for everyone after them.
                report($e);
            }
        }
    }
}
