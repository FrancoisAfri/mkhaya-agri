<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Core\SMS\Services\SmsService;
use App\Core\User\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Notifications and escalations (§9).
 *
 * All eleven §9 notices are the same shape — an event, who hears about it, on which channel, how
 * far ahead, and who is told when it is still unresolved after a configured time — so one
 * configurable rule table serves all of them and `sweep()` evaluates each event against the tenant's
 * own rules.
 *
 * Two design points are what make this survivable in production:
 *
 *  - **Dedupe keys.** Without them a "contract expiring in 30 days" rule mails the same person every
 *    morning for a month, and people learn to filter procurement mail — which defeats §9 far more
 *    thoroughly than sending nothing. A key identifies (rule, subject, escalation level, period), so
 *    a daily sweep is idempotent, and `repeat_every_days` opts a rule back in deliberately.
 *
 *  - **Recipients resolve at send time.** The right person is usually defined by their relationship
 *    to the record — the assigned officer, the department head, the budget owner — not by name. A
 *    rule naming a person breaks the first time they change roles, and nobody notices until an
 *    approval sits for three weeks.
 *
 * Delivery borrows the platform's mail and SMS services. A failed send is logged as FAILED rather
 * than dropped, so an undelivered statutory notice looks undelivered.
 */
class NotificationService
{
    public const EVENTS = [
        'PENDING_APPROVAL',
        'EVALUATION_DEADLINE',
        'OVERDUE_EVALUATION',
        'PROCUREMENT_DELAY',
        'DEVIATION_DECISION',
        'MISSING_DOCUMENT',
        'EXPIRING_DOCUMENT',
        'EXPIRING_CONTRACT',
        'BUDGET_PROBLEM',
        'COMPLIANCE_FAILURE',
        'TASK_ASSIGNED',
        'COI_DECLARED',
        'CAPACITY_ALERT',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLedger $audit,
    ) {}

    /**
     * Run every active rule for a tenant. Returns how many notices were actually sent — a sweep
     * that sends nothing because everything is already up to date is the normal case.
     */
    public function sweep(int $companyId): int
    {
        $rules = DB::table('procgov_notification_rules')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get();

        $sent = 0;

        foreach ($rules as $rule) {
            try {
                $sent += match ($rule->event) {
                    'PENDING_APPROVAL' => $this->sweepPendingApprovals($companyId, $rule),
                    'EVALUATION_DEADLINE' => $this->sweepEvaluationDeadlines($companyId, $rule, false),
                    'OVERDUE_EVALUATION' => $this->sweepEvaluationDeadlines($companyId, $rule, true),
                    'PROCUREMENT_DELAY' => $this->sweepProcurementDelays($companyId, $rule),
                    'EXPIRING_DOCUMENT' => $this->sweepExpiringDocuments($companyId, $rule),
                    'EXPIRING_CONTRACT' => $this->sweepExpiringContracts($companyId, $rule),
                    'COMPLIANCE_FAILURE' => $this->sweepComplianceFailures($companyId, $rule),
                    'CAPACITY_ALERT' => $this->sweepCapacityAlerts($companyId, $rule),
                    default => 0,
                };
            } catch (\Throwable $e) {
                // One misconfigured rule must not stop the other ten from running.
                report($e);
            }
        }

        return $sent;
    }

    /**
     * Escalate anything that has sat unresolved past its configured window (§9's last paragraph).
     * Escalation is a separate notice at a higher level, not a louder copy of the original: the
     * recipient list is different, and the log records which level it was.
     */
    public function escalate(int $companyId): int
    {
        $escalationDays = (int) $this->settings->get($companyId)->escalation_days;
        $cutoff = Carbon::now()->subDays($escalationDays);
        $escalated = 0;

        $stale = DB::table('procgov_approval_steps as s')
            ->join('procgov_approval_requests as r', 'r.id', '=', 's.request_id')
            ->where('s.company_id', $companyId)
            ->where('s.status', 'ACTIVE')
            ->whereNull('s.escalated_at')
            ->where(function ($q) use ($cutoff) {
                $q->where('s.due_at', '<', Carbon::now())
                    ->orWhere('s.activated_at', '<', $cutoff);
            })
            /*
             * `escalate_to_type` is deliberately not selected here, and its absence is the whole
             * point of this comment.
             *
             * It is a column of `procgov_chain_steps` — the reusable chain *template* an
             * administrator configures — not of `procgov_approval_steps`, which is the instance
             * raised against one procurement. Selecting it from the instance table made every
             * nightly run of this sweep die on an undefined column, for every tenant, since the day
             * the command was scheduled. Nothing read the value: escalationRecipients() routes to
             * the department head and falls back to the administrator role.
             *
             * The configured target is therefore still ignored — see escalationRecipients().
             */
            ->get([
                's.id', 's.name', 's.due_at', 's.activated_at',
                'r.id as request_id', 'r.subject_type', 'r.subject_id', 'r.subject_reference',
                'r.subject_label', 'r.record_id', 'r.requested_by', 'r.chain_id',
            ]);

        foreach ($stale as $step) {
            $recipients = $this->escalationRecipients($companyId, $step);

            if ($recipients->isEmpty()) {
                continue;
            }

            $waiting = $step->activated_at
                ? Carbon::parse($step->activated_at)->diffInDays(Carbon::now())
                : $escalationDays;

            $subject = "Escalation: {$step->name} outstanding on ".($step->subject_reference ?: 'a procurement item');
            $body = sprintf(
                'The approval step "%s" for %s has been outstanding for %d day(s) and has passed the '
                ."%d-day escalation threshold.\n\nItem: %s\nReference: %s",
                $step->name,
                $step->subject_label ?: $step->subject_type,
                $waiting,
                $escalationDays,
                $step->subject_label ?: '—',
                $step->subject_reference ?: '—',
            );

            $delivered = $this->deliver($companyId, null, 'PENDING_APPROVAL', [
                'subject_type' => $step->subject_type,
                'subject_id' => (int) $step->subject_id,
                'subject_reference' => $step->subject_reference,
            ], $recipients, $subject, $body, ['database', 'mail'], escalationLevel: 1);

            if ($delivered > 0) {
                DB::table('procgov_approval_steps')->where('id', $step->id)->update([
                    'escalated_at' => Carbon::now(),
                    'status' => 'ESCALATED',
                    'updated_at' => Carbon::now(),
                ]);

                $this->audit->record($companyId, [
                    'action' => 'approval_escalated',
                    'capability' => 'governance',
                    'entity_type' => $step->subject_type,
                    'entity_id' => (int) $step->subject_id,
                    'entity_reference' => $step->subject_reference,
                    'record_id' => $step->record_id,
                    'actor_type' => 'system',
                    'summary' => "Escalated an approval outstanding for {$waiting} day(s) on "
                        .($step->subject_reference ?: $step->subject_type),
                ]);

                $escalated++;
            }
        }

        return $escalated;
    }

    /**
     * Send a one-off notice for an event that happens rather than accumulates — a conflict being
     * declared, a deviation being decided, work being assigned.
     */
    public function notify(int $companyId, string $event, array $subject, string $title, string $body): int
    {
        $rules = DB::table('procgov_notification_rules')
            ->where('company_id', $companyId)
            ->where('event', $event)
            ->where('is_active', true)
            ->get();

        $sent = 0;

        foreach ($rules as $rule) {
            $recipients = $this->resolveRecipients($companyId, $rule, $subject);
            $sent += $this->deliver(
                $companyId,
                $rule,
                $event,
                $subject,
                $recipients,
                $title,
                $body,
                $this->channels($rule),
            );
        }

        return $sent;
    }

    // ── Per-event sweeps ─────────────────────────────────────────────────────────────────────────

    private function sweepPendingApprovals(int $companyId, object $rule): int
    {
        $steps = DB::table('procgov_approval_steps as s')
            ->join('procgov_approval_requests as r', 'r.id', '=', 's.request_id')
            ->where('s.company_id', $companyId)
            ->where('s.status', 'ACTIVE')
            ->get([
                's.id', 's.name', 's.approver_id', 's.approver_type', 's.approver_ref', 's.due_at',
                'r.id as request_id', 'r.subject_type', 'r.subject_id', 'r.subject_reference',
                'r.subject_label', 'r.record_id',
            ]);

        $sent = 0;

        foreach ($steps as $step) {
            $recipients = app(ApprovalRouter::class)->currentApprovers((int) $step->request_id);

            if ($recipients->isEmpty()) {
                continue;
            }

            $sent += $this->deliver($companyId, $rule, 'PENDING_APPROVAL', [
                'subject_type' => $step->subject_type,
                'subject_id' => (int) $step->subject_id,
                'subject_reference' => $step->subject_reference,
            ], $recipients,
                "Approval required: {$step->name}",
                sprintf(
                    "You have an approval awaiting your decision.\n\nStep: %s\nItem: %s\nReference: %s\nDue: %s",
                    $step->name,
                    $step->subject_label ?: $step->subject_type,
                    $step->subject_reference ?: '—',
                    $step->due_at ? Carbon::parse($step->due_at)->toDayDateTimeString() : 'not set',
                ),
                $this->channels($rule),
            );
        }

        return $sent;
    }

    private function sweepEvaluationDeadlines(int $companyId, object $rule, bool $overdueOnly): int
    {
        $leadDays = (int) $rule->lead_days;
        $today = Carbon::today();

        $reviews = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'EVALUATION')
            ->whereNotNull('evaluation_deadline')
            ->when(
                $overdueOnly,
                fn ($q) => $q->where('evaluation_deadline', '<', $today),
                fn ($q) => $q->whereBetween('evaluation_deadline', [$today, $today->copy()->addDays(max(1, $leadDays))]),
            )
            ->get(['id', 'reference', 'title', 'evaluation_deadline', 'officer_id', 'record_id']);

        $sent = 0;

        foreach ($reviews as $review) {
            // Only chase the evaluators who have not finished. Copying everyone, including the
            // people who submitted a week ago, is how a reminder becomes noise.
            $outstanding = DB::table('procgov_review_evaluators')
                ->where('review_id', $review->id)
                ->where('is_voting', true)
                ->whereNotIn('status', ['SUBMITTED', 'RECUSED', 'REMOVED'])
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id);

            $recipients = $outstanding->merge(
                $this->resolveRecipients($companyId, $rule, [
                    'officer_id' => $review->officer_id,
                    'record_id' => $review->record_id,
                ])
            )->unique();

            if ($recipients->isEmpty()) {
                continue;
            }

            $sent += $this->deliver($companyId, $rule, $overdueOnly ? 'OVERDUE_EVALUATION' : 'EVALUATION_DEADLINE', [
                'subject_type' => 'procgov_tender_reviews',
                'subject_id' => (int) $review->id,
                'subject_reference' => $review->reference,
            ], $recipients,
                $overdueOnly
                    ? "Overdue evaluation: {$review->reference}"
                    : "Evaluation deadline approaching: {$review->reference}",
                sprintf(
                    "%s\n\nTender: %s\nReference: %s\nEvaluation deadline: %s",
                    $overdueOnly
                        ? 'This tender evaluation has passed its deadline and is not complete.'
                        : 'This tender evaluation is due shortly.',
                    $review->title,
                    $review->reference,
                    Carbon::parse($review->evaluation_deadline)->toFormattedDateString(),
                ),
                $this->channels($rule),
            );
        }

        return $sent;
    }

    private function sweepProcurementDelays(int $companyId, object $rule): int
    {
        $records = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'ACTIVE')
            ->whereNotNull('target_completion_at')
            ->where('target_completion_at', '<', Carbon::now())
            ->limit(200)
            ->get(['id', 'reference', 'title', 'stage', 'officer_id', 'company_unit_id', 'target_completion_at']);

        $sent = 0;

        foreach ($records as $record) {
            $recipients = $this->resolveRecipients($companyId, $rule, [
                'officer_id' => $record->officer_id,
                'company_unit_id' => $record->company_unit_id,
                'record_id' => $record->id,
            ]);

            $sent += $this->deliver($companyId, $rule, 'PROCUREMENT_DELAY', [
                'subject_type' => 'procgov_records',
                'subject_id' => (int) $record->id,
                'subject_reference' => $record->reference,
            ], $recipients,
                "Procurement delayed: {$record->reference}",
                sprintf(
                    '%s is past its target completion date of %s and is still at %s stage.',
                    $record->title,
                    Carbon::parse($record->target_completion_at)->toFormattedDateString(),
                    $record->stage,
                ),
                $this->channels($rule),
            );
        }

        return $sent;
    }

    private function sweepExpiringDocuments(int $companyId, object $rule): int
    {
        $leadDays = max(1, (int) $rule->lead_days ?: 30);

        $documents = DB::table('procgov_documents')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [Carbon::today(), Carbon::today()->addDays($leadDays)])
            ->limit(200)
            ->get(['id', 'reference', 'title', 'expires_at', 'owner_id']);

        $sent = 0;

        foreach ($documents as $document) {
            $recipients = $this->resolveRecipients($companyId, $rule, ['officer_id' => $document->owner_id]);

            $sent += $this->deliver($companyId, $rule, 'EXPIRING_DOCUMENT', [
                'subject_type' => 'procgov_documents',
                'subject_id' => (int) $document->id,
                'subject_reference' => $document->reference,
            ], $recipients,
                "Document expiring: {$document->title}",
                sprintf(
                    '"%s" expires on %s. A current version is required before it can support any '
                    .'further procurement activity.',
                    $document->title,
                    Carbon::parse($document->expires_at)->toFormattedDateString(),
                ),
                $this->channels($rule),
            );
        }

        return $sent;
    }

    private function sweepExpiringContracts(int $companyId, object $rule): int
    {
        $leadDays = max(1, (int) $rule->lead_days ?: 60);

        // Read through the record links rather than the Contract Management tables directly, so
        // this works identically whether or not that module is installed.
        $links = DB::table('procgov_record_links as l')
            ->join('procgov_records as r', 'r.id', '=', 'l.record_id')
            ->where('l.company_id', $companyId)
            ->where('l.link_role', 'contract')
            ->whereNotNull('l.meta')
            ->limit(500)
            ->get(['l.id', 'l.entity_reference', 'l.label', 'l.meta', 'r.id as record_id',
                'r.reference', 'r.officer_id', 'r.company_unit_id']);

        $sent = 0;
        $window = Carbon::today()->addDays($leadDays);

        foreach ($links as $link) {
            $meta = json_decode((string) $link->meta, true) ?: [];
            $endDate = $meta['end_date'] ?? null;

            if (! $endDate) {
                continue;
            }

            $end = Carbon::parse($endDate);

            if (! $end->between(Carbon::today(), $window)) {
                continue;
            }

            $recipients = $this->resolveRecipients($companyId, $rule, [
                'officer_id' => $link->officer_id,
                'company_unit_id' => $link->company_unit_id,
                'record_id' => $link->record_id,
            ]);

            $sent += $this->deliver($companyId, $rule, 'EXPIRING_CONTRACT', [
                'subject_type' => 'procgov_record_links',
                'subject_id' => (int) $link->id,
                'subject_reference' => $link->entity_reference,
            ], $recipients,
                'Contract expiring: '.($link->label ?: $link->entity_reference),
                sprintf(
                    'The contract on procurement %s expires on %s. Renewal or re-procurement should '
                    .'be planned now to avoid a gap in supply.',
                    $link->reference,
                    $end->toFormattedDateString(),
                ),
                $this->channels($rule),
            );
        }

        return $sent;
    }

    private function sweepComplianceFailures(int $companyId, object $rule): int
    {
        $checks = DB::table('procgov_compliance_checks')
            ->where('company_id', $companyId)
            ->where('result', 'FAIL')
            ->where('checked_at', '>=', Carbon::now()->subDay())
            ->limit(100)
            ->get(['id', 'subject_type', 'subject_id', 'subject_reference', 'failures', 'record_id']);

        $sent = 0;

        foreach ($checks as $check) {
            $recipients = $this->resolveRecipients($companyId, $rule, ['record_id' => $check->record_id]);

            $sent += $this->deliver($companyId, $rule, 'COMPLIANCE_FAILURE', [
                'subject_type' => $check->subject_type,
                'subject_id' => (int) $check->subject_id,
                'subject_reference' => $check->subject_reference,
            ], $recipients,
                'Compliance failure: '.($check->subject_reference ?: $check->subject_type),
                "A compliance check failed with {$check->failures} blocking finding(s). "
                .'The item cannot proceed until they are resolved.',
                $this->channels($rule),
            );
        }

        return $sent;
    }

    private function sweepCapacityAlerts(int $companyId, object $rule): int
    {
        $alerts = DB::table('procgov_capacity_alerts')
            ->where('company_id', $companyId)
            ->where('status', 'OPEN')
            ->where('detected_at', '>=', Carbon::now()->subDay())
            ->get(['id', 'officer_id', 'narrative', 'severity']);

        $sent = 0;

        foreach ($alerts as $alert) {
            $recipients = $this->resolveRecipients($companyId, $rule, []);

            $sent += $this->deliver($companyId, $rule, 'CAPACITY_ALERT', [
                'subject_type' => 'procgov_capacity_alerts',
                'subject_id' => (int) $alert->id,
                'subject_reference' => null,
            ], $recipients,
                'Procurement officer capacity alert',
                $alert->narrative."\n\nConsider redistributing work to keep procurement moving.",
                $this->channels($rule),
            );
        }

        return $sent;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @return Collection<int,int> user ids */
    private function resolveRecipients(int $companyId, ?object $rule, array $context): Collection
    {
        if (! $rule) {
            return collect();
        }

        $config = json_decode((string) $rule->recipients, true) ?: [];
        $ids = collect($config['users'] ?? [])->map(fn ($id) => (int) $id);

        foreach ($config['roles'] ?? [] as $roleName) {
            $ids = $ids->merge($this->usersWithRole($companyId, (string) $roleName));
        }

        foreach ($config['dynamic'] ?? [] as $token) {
            $ids = $ids->merge(match ($token) {
                'officer' => collect(array_filter([$context['officer_id'] ?? null])),
                'unit_manager' => $this->unitManager($companyId, $context['company_unit_id'] ?? null),
                'requester' => collect(array_filter([$context['requested_by'] ?? null])),
                default => collect(),
            });
        }

        return $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Escalation goes up, not sideways: the department head, then whoever holds the escalate role.
     *
     * Not yet honoured: the `escalate_to_type` / `escalate_to_id` an administrator can set per step
     * on a chain template. Settings validates and stores them, and nothing reads them — so a tenant
     * who nominates a specific escalation authority still gets the default routing below. Wiring it
     * up means joining the instance step back to its template via `r.chain_id` and `s.step_no`.
     */
    private function escalationRecipients(int $companyId, object $step): Collection
    {
        $unitId = DB::table('procgov_records')
            ->where('id', $step->record_id)
            ->value('company_unit_id');

        $recipients = $this->unitManager($companyId, $unitId);

        if ($recipients->isEmpty()) {
            $recipients = $this->usersWithRole($companyId, 'administrator');
        }

        // Never escalate back to the person who raised it.
        return $recipients->reject(fn ($id) => (int) $id === (int) $step->requested_by)->values();
    }

    private function usersWithRole(int $companyId, string $roleName): Collection
    {
        return DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->join('users as u', 'u.id', '=', 'mhr.model_id')
            ->where('mhr.model_type', User::class)
            ->whereRaw('lower(r.name) = ?', [mb_strtolower($roleName)])
            ->where('u.company_id', $companyId)
            ->whereNull('u.deleted_at')
            ->pluck('u.id')
            ->map(fn ($id) => (int) $id);
    }

    private function unitManager(int $companyId, ?int $unitId): Collection
    {
        if (! $unitId) {
            return collect();
        }

        $managerId = DB::table('company_units')
            ->where('id', $unitId)
            ->where('company_id', $companyId)
            ->value('user_id');

        return collect(array_filter([$managerId ? (int) $managerId : null]));
    }

    private function channels(?object $rule): array
    {
        $channels = $rule ? (json_decode((string) $rule->channels, true) ?: []) : [];

        return $channels ?: ['database', 'mail'];
    }

    /**
     * Deliver to each recipient on each channel, deduped. Returns how many notices were sent.
     */
    private function deliver(
        int $companyId,
        ?object $rule,
        string $event,
        array $subject,
        Collection $recipients,
        string $title,
        string $body,
        array $channels,
        int $escalationLevel = 0,
    ): int {
        if ($recipients->isEmpty()) {
            return 0;
        }

        $users = DB::table('users')
            ->whereIn('id', $recipients->all())
            ->whereNull('deleted_at')
            ->get(['id', 'email']);

        $period = $rule && $rule->repeat_every_days
            ? (int) floor(Carbon::now()->timestamp / (max(1, (int) $rule->repeat_every_days) * 86400))
            : 0;

        $sent = 0;

        foreach ($users as $user) {
            foreach ($channels as $channel) {
                $dedupeKey = $this->dedupeKey($rule, $event, $subject, (int) $user->id, $channel, $escalationLevel, $period);

                $already = DB::table('procgov_notification_log')
                    ->where('company_id', $companyId)
                    ->where('dedupe_key', $dedupeKey)
                    ->exists();

                if ($already) {
                    continue;
                }

                [$status, $failure] = $this->send($channel, $user, $title, $body);

                DB::table('procgov_notification_log')->insert([
                    'company_id' => $companyId,
                    'rule_id' => $rule?->id,
                    'event' => $event,
                    'subject_type' => $subject['subject_type'] ?? null,
                    'subject_id' => $subject['subject_id'] ?? null,
                    'subject_reference' => $subject['subject_reference'] ?? null,
                    'recipient_id' => $user->id,
                    'recipient_address' => $user->email,
                    'channel' => $channel,
                    'escalation_level' => $escalationLevel,
                    'status' => $status,
                    'failure_reason' => $failure,
                    'dedupe_key' => $dedupeKey,
                    'sent_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                if ($status === 'SENT') {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** @return array{0:string, 1:string|null} status, failure reason */
    private function send(string $channel, object $user, string $title, string $body): array
    {
        try {
            return match ($channel) {
                'mail' => (function () use ($user, $title, $body) {
                    if (empty($user->email)) {
                        return ['SKIPPED', 'no email address'];
                    }

                    Mail::raw($body, fn ($mail) => $mail->to($user->email)->subject($title));

                    return ['SENT', null];
                })(),

                // The in-app notice is the log row itself: it is queryable per user and per subject,
                // which is what the in-app inbox reads.
                'database' => ['SENT', null],

                'sms' => (function () use ($user, $title, $body) {
                    $phone = DB::table('hr_people')->where('user_id', $user->id)->value('cell_number');

                    if (! $phone) {
                        return ['SKIPPED', 'no mobile number'];
                    }

                    // send() is static on the platform's SMS service.
                    SmsService::send($phone, Str::limit("{$title}: {$body}", 300, ''), 'procurement_governance');

                    return ['SENT', null];
                })(),

                default => ['SKIPPED', "unsupported channel {$channel}"],
            };
        } catch (\Throwable $e) {
            Log::warning('Procurement governance notification failed.', [
                'channel' => $channel,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ['FAILED', Str::limit($e->getMessage(), 495, '')];
        }
    }

    private function dedupeKey(
        ?object $rule,
        string $event,
        array $subject,
        int $userId,
        string $channel,
        int $escalationLevel,
        int $period,
    ): string {
        return Str::limit(implode(':', [
            $rule?->id ?? 'adhoc',
            $event,
            $subject['subject_type'] ?? '-',
            $subject['subject_id'] ?? '-',
            $userId,
            $channel,
            $escalationLevel,
            $period,
        ]), 185, '');
    }
}
