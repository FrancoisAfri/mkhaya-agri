<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentExpiryReminder;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Notifications\DocumentExpiryNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Chasing documents before they lapse, and marking them when they do.
 *
 * The whole value is in being early. A tax clearance that expired yesterday is a tender that cannot
 * be submitted today, and nobody finds that out by browsing a folder — they find out when somebody
 * asks for the certificate.
 *
 * Reminders are recorded as they are sent, one row per document per threshold per expiry date. That
 * is what stops the daily sweep re-sending the same warning every morning, and a notification
 * people have learned to ignore is worse than none at all. Matching on the expiry date rather than
 * just the document means a renewed document starts its reminders again with no reset step.
 */
class ExpiryService
{
    /** The thresholds from the requirement; 0 is the day itself. */
    public const DEFAULT_THRESHOLDS = [90, 60, 30, 14, 7, 0];

    /**
     * One company's sweep.
     *
     * @return array{reminded:int, expired:int, notified:int}
     */
    public function sweep(?\DateTimeInterface $asOf = null): array
    {
        $today = ($asOf ? \Illuminate\Support\Carbon::instance($asOf) : now())->startOfDay();
        $settings = DocumentSetting::current();

        $totals = ['reminded' => 0, 'expired' => 0, 'notified' => 0];

        // Marked first, so a document expiring today is recorded as expired before the reminder
        // for it goes out — otherwise the email says "expires today" about something the system
        // already considers lapsed.
        $totals['expired'] = $this->markExpired($today);

        if (! $settings->expiry_reminders_enabled) {
            return $totals;
        }

        foreach ($this->thresholds($settings) as $days) {
            $sent = $this->remindAt($days, $today, $settings);
            $totals['reminded'] += $sent['documents'];
            $totals['notified'] += $sent['recipients'];
        }

        return $totals;
    }

    /**
     * Nudges approvers whose deadline has passed or is about to.
     *
     * Part of the same daily sweep rather than a second cron: they run at the same time, for the
     * same reason, and one command means one place to check when somebody asks why nobody was
     * reminded.
     *
     * Reminded at most once a day. An approval nag that arrives hourly is one people filter.
     *
     * @return int assignments reminded
     */
    public function remindApprovers(): int
    {
        $due = \App\Modules\Operations\DocumentManagement\Models\DocumentApprovalAssignment::query()
            ->where('status', \App\Modules\Operations\DocumentManagement\Models\DocumentApprovalAssignment::PENDING)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDay())
            ->where(fn ($q) => $q->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<', now()->startOfDay()))
            ->with('request.document')
            ->get();

        $sent = 0;

        foreach ($due as $assignment) {
            // Only the step actually in progress. Reminding somebody about a step three stages away
            // is noise they can do nothing about.
            if ($assignment->position !== $assignment->request?->current_position) {
                continue;
            }

            $recipient = $assignment->responsibleId()
                ? \App\Core\User\Models\User::find($assignment->responsibleId())
                : null;

            if ($recipient) {
                try {
                    Notification::send($recipient, new \App\Modules\Operations\DocumentManagement\Notifications\ApprovalRequestNotification(
                        $assignment->request, $assignment,
                    ));
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('Approval reminder failed.', ['error' => $e->getMessage()]);
                }
            }

            $assignment->forceFill(['last_reminded_at' => now()])->save();
        }

        return $sent;
    }

    /**
     * Documents whose expiry date has passed and that nobody has marked yet.
     *
     * `expired_at` records when it was noticed rather than when it happened, which is the honest
     * thing to store: a sweep that did not run for a week noticed late, and pretending otherwise
     * would make the audit trail lie.
     */
    private function markExpired(\Illuminate\Support\Carbon $today): int
    {
        $documents = Document::query()
            ->whereNotNull('expiry_date')
            ->whereNull('expired_at')
            ->whereDate('expiry_date', '<', $today)
            ->where('status', Document::STATUS_ACTIVE)
            ->get();

        foreach ($documents as $document) {
            $document->forceFill(['expired_at' => now()])->save();

            app(DocumentService::class)->record($document, DocumentEvent::EXPIRED, null, [
                'expiry_date' => $document->expiry_date?->toDateString(),
            ]);
        }

        return $documents->count();
    }

    /**
     * Sends the warning for one threshold.
     *
     * @return array{documents:int, recipients:int}
     */
    private function remindAt(int $days, \Illuminate\Support\Carbon $today, DocumentSetting $settings): array
    {
        $target = $today->copy()->addDays($days)->toDateString();

        $documents = Document::query()
            ->whereDate('expiry_date', $target)
            ->where('status', Document::STATUS_ACTIVE)
            ->with(['category', 'folder'])
            ->get();

        $sentDocuments = 0;
        $sentRecipients = 0;

        foreach ($documents as $document) {
            // Already told about this document, at this threshold, for this expiry date.
            $already = DocumentExpiryReminder::where('document_id', $document->id)
                ->where('threshold_days', $days)
                ->whereDate('expiry_date', $document->expiry_date)
                ->exists();

            if ($already) {
                continue;
            }

            $recipients = $this->recipientsFor($document, $settings);

            if ($recipients->isNotEmpty()) {
                try {
                    Notification::send($recipients, new DocumentExpiryNotification($document, $days));
                } catch (\Throwable $e) {
                    // A mail server refusing one message must not stop the sweep: the rest of the
                    // estate still needs chasing, and the reminder is recorded as attempted so it
                    // is not retried every morning forever.
                    Log::warning('Document expiry notification failed.', [
                        'document' => $document->uuid, 'error' => $e->getMessage(),
                    ]);
                }
            }

            DocumentExpiryReminder::create([
                'document_id' => $document->id,
                'threshold_days' => $days,
                'expiry_date' => $document->expiry_date,
                'sent_at' => now(),
                'recipients' => $recipients->count(),
            ]);

            app(DocumentService::class)->record($document, DocumentEvent::EXPIRY_REMINDED, null, [
                'days' => $days,
                'recipients' => $recipients->count(),
            ]);

            $sentDocuments++;
            $sentRecipients += $recipients->count();
        }

        return ['documents' => $sentDocuments, 'recipients' => $sentRecipients];
    }

    /**
     * Who to tell.
     *
     * The owner first, because they are the person accountable for it. Then whoever holds the roles
     * the organisation nominated — the point of naming roles rather than people is that the person
     * chasing a lapsed insurance certificate is whoever holds that job this month.
     *
     * Deduplicated, so somebody who is both the owner and in the role is not told twice.
     */
    private function recipientsFor(Document $document, DocumentSetting $settings): \Illuminate\Support\Collection
    {
        $users = collect();

        if ($document->owner_id) {
            $owner = User::find($document->owner_id);
            if ($owner) {
                $users->push($owner);
            }
        }

        $roles = $settings->expiry_notify_roles ?? [];

        if ($roles !== []) {
            $users = $users->merge(User::whereHas('roles', fn ($q) => $q->whereIn('name', $roles))->get());
        }

        return $users->unique('id')->values();
    }

    /** @return list<int> */
    public function thresholds(?DocumentSetting $settings = null): array
    {
        $settings ??= DocumentSetting::current();
        $configured = $settings->expiry_reminder_days;

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_THRESHOLDS;
        }

        // Sorted furthest-out first and deduplicated, so the sweep walks them in the order somebody
        // would expect to be warned and a duplicated entry does not double a notification.
        $days = array_values(array_unique(array_map('intval', $configured)));
        rsort($days);

        return array_values(array_filter($days, fn (int $d) => $d >= 0));
    }

    /**
     * What is coming up, for a dashboard.
     *
     * @return array{expired:int, within_7:int, within_30:int, within_90:int}
     */
    public function summary(): array
    {
        return [
            'expired' => Document::expired()->where('status', Document::STATUS_ACTIVE)->count(),
            'within_7' => Document::expiringWithin(7)->count(),
            'within_30' => Document::expiringWithin(30)->count(),
            'within_90' => Document::expiringWithin(90)->count(),
        ];
    }
}
