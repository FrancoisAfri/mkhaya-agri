<?php

namespace App\Modules\CRM\Services;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmMailbox;
use App\Modules\CRM\Models\CrmSuppression;
use App\Shared\Notifications\GenericInAppNotification;
use Illuminate\Support\Str;

/**
 * Turns a captured inbound email into a CRM lead. Each message is run through
 * AI spam/legitimacy filtering (when configured) and, if it looks like a real
 * lead, a CrmLead is created and the assigned rep + any aligned roles are
 * notified in-app. Returns a per-message outcome for logging.
 */
class CrmInboundLeadService
{
    public function __construct(
        private readonly CrmAiService $ai,
        private readonly CrmNotifier $notifier,
        private readonly CrmLeadEngagementService $engagement,
    ) {}

    /**
     * @param  array{uid:int, from:string, from_name:string, subject:string, body:string, date:?string}  $email
     * @return array{action:string, reason:string, lead_id?:int}
     */
    public function process(CrmMailbox $mailbox, array $email): array
    {
        $from    = trim($email['from'] ?? '');
        $subject = trim($email['subject'] ?? '');
        $body    = trim($email['body'] ?? '');

        if ($from === '' || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['action' => 'skipped', 'reason' => 'No valid sender address.'];
        }

        // Never ingest our own / system addresses or suppressed senders.
        if (Str::contains($from, ['mailer-daemon', 'no-reply', 'noreply', 'postmaster', 'do-not-reply'])) {
            return ['action' => 'skipped', 'reason' => 'Automated / system sender.'];
        }
        if (CrmSuppression::isSuppressed('email', $from)) {
            return ['action' => 'skipped', 'reason' => 'Sender is on the suppression list.'];
        }

        // Heuristic spam pre-filter (works even without AI).
        if ($this->looksLikeSpam($subject, $body)) {
            return ['action' => 'spam', 'reason' => 'Matched spam heuristics.'];
        }

        // AI legitimacy / lead assessment (optional).
        $fields   = [];
        $aiReason = 'AI disabled — accepted by heuristics.';
        if ($mailbox->ai_filter) {
            $assessment = $this->ai->assessInboundEmail($from, $subject, $body);
            if ($assessment['available'] ?? false) {
                if (! empty($assessment['is_spam'])) {
                    return ['action' => 'spam', 'reason' => $assessment['reason'] ?: 'AI flagged as spam.'];
                }
                if (! ($assessment['is_lead'] ?? false)) {
                    return ['action' => 'not_lead', 'reason' => $assessment['reason'] ?: 'AI judged not a sales lead.'];
                }
                $fields   = $assessment['fields'] ?? [];
                $aiReason = $assessment['reason'] ?: 'AI confirmed a legitimate lead.';
            }
        }

        // De-dupe: skip if we already have an open lead for this sender.
        $existing = CrmLead::where('email', $from)
            ->whereNotIn('status', ['converted', 'unqualified'])
            ->first();
        if ($existing) {
            return ['action' => 'duplicate', 'reason' => 'Open lead already exists.', 'lead_id' => $existing->id];
        }

        $lead = $this->createLead($mailbox, $from, $email['from_name'] ?? '', $subject, $body, $fields, $aiReason);
        $this->notify($mailbox, $lead);

        // Auto-acknowledge the prospect by email + WhatsApp (best-effort).
        $this->engagement->engageNewLead($lead->loadMissing('owner.person'));

        return ['action' => 'lead_created', 'reason' => $aiReason, 'lead_id' => $lead->id];
    }

    private function createLead(CrmMailbox $mailbox, string $from, string $fromName, string $subject, string $body, array $fields, string $reason): CrmLead
    {
        [$first, $last] = $this->splitName($fields, $fromName, $from);

        return CrmLead::create([
            'uuid'         => (string) Str::uuid(),
            'first_name'   => $first,
            'last_name'    => $last,
            'company'      => $fields['company'] ?? null,
            'email'        => $fields['email'] ?? $from,
            'phone'        => $fields['phone'] ?? null,
            'source'       => 'mailbox:' . $mailbox->email,
            'status'       => 'new',
            'owner_id'     => $mailbox->default_owner_id,
            'notes'        => "Captured from {$mailbox->email}.\nSubject: {$subject}\n\n"
                              . mb_substr($body, 0, 2000) . "\n\n— AI: {$reason}",
            'created_by'   => $mailbox->created_by,
        ]);
    }

    /** Notify the assigned rep and everyone in the configured aligned roles. */
    private function notify(CrmMailbox $mailbox, CrmLead $lead): void
    {
        $recipients = [];
        if ($mailbox->default_owner_id) {
            $recipients[] = (int) $mailbox->default_owner_id;
        }

        foreach ((array) $mailbox->notify_roles as $role) {
            foreach ($this->usersWithRole((string) $role) as $userId) {
                $recipients[] = $userId;
            }
        }

        $title = 'New lead from mailbox: ' . $lead->full_name;
        $body  = trim(($lead->company ? $lead->company . ' — ' : '') . $lead->email);
        $link  = '/crm/leads?lead=' . $lead->id;

        $recipients = array_unique($recipients);

        foreach ($recipients as $userId) {
            // CRM collaboration bell.
            $this->notifier->notify($userId, 'lead', $title, $body, $link, 'lead', $lead->id);
        }

        // Also surface it in the global notification bell (native Notifiable) so
        // any action regarding the user reaches them, per the universal rule.
        User::whereIn('id', $recipients)->get()->each(function (User $user) use ($title, $body, $link) {
            try {
                $user->notify(new GenericInAppNotification(title: $title, message: $body, url: $link, type: 'lead'));
            } catch (\Throwable) {
                // Notification delivery must never break lead capture.
            }
        });
    }

    /** @return array<int,int> */
    private function usersWithRole(string $role): array
    {
        try {
            return User::role($role)->pluck('id')->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function splitName(array $fields, string $fromName, string $from): array
    {
        if (! empty($fields['first_name']) || ! empty($fields['last_name'])) {
            return [$fields['first_name'] ?? '', $fields['last_name'] ?? ''];
        }
        $name = trim($fromName) ?: Str::before($from, '@');
        $name = str_replace(['.', '_'], ' ', $name);
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = ucfirst($parts[0] ?? 'Unknown');
        $last  = ucfirst(implode(' ', array_slice($parts, 1)));
        return [$first, $last];
    }

    private function looksLikeSpam(string $subject, string $body): bool
    {
        $haystack = mb_strtolower($subject . ' ' . $body);
        $signals = ['viagra', 'lottery', 'you have won', 'crypto investment', 'forex signals',
            'nigerian prince', 'click here to claim', 'wire transfer fee', 'bitcoin doubler', 'sex'];
        foreach ($signals as $s) {
            if (str_contains($haystack, $s)) return true;
        }
        return false;
    }
}
