<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmMailbox;
use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Support\Mail\ImapClient;
use Illuminate\Support\Facades\Log;

/**
 * Fetches new (unseen) messages from a monitored mailbox. Reuses the HelpDesk
 * socket-based ImapClient, which speaks IMAP over stream sockets and therefore
 * does NOT require the php-imap extension — so this works on stock PHP.
 */
class CrmMailboxService
{
    public function __construct(
        private readonly CrmInboundLeadService $inbound,
        private readonly CrmInboundComplaintService $complaintInbound,
    ) {}

    private function client(CrmMailbox $mailbox): ImapClient
    {
        return new ImapClient(
            host:       $mailbox->host,
            port:       (int) $mailbox->port,
            encryption: $mailbox->encryption,
            username:   $mailbox->username,
            password:   (string) $mailbox->plainPassword(),
            folder:     $mailbox->folder ?: 'INBOX',
        );
    }

    /** Connectivity test — connect, count, disconnect. */
    public function test(CrmMailbox $mailbox): array
    {
        try {
            $client = $this->client($mailbox);
            $client->connect();
            $client->disconnect();
            return ['success' => true, 'message' => 'Connected to ' . $mailbox->host . ' successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Poll every active mailbox, capturing unseen emails into leads.
     *
     * @return array{mailboxes:int, processed:int, leads:int, spam:int, errors:array<int,string>}
     */
    public function pollAll(): array
    {
        $summary = ['mailboxes' => 0, 'processed' => 0, 'leads' => 0, 'complaints' => 0, 'spam' => 0, 'errors' => []];

        // Iterates every company's mailboxes (this runs unscoped in console). Each
        // mailbox is polled AS its owning company so leads/complaints/dedupe/
        // notifications are correctly tenant-scoped (RLS + CompanyScope).
        $tenant = app(\App\Modules\Tenancy\Services\TenantContext::class);

        CrmMailbox::where('active', true)->get()->each(function (CrmMailbox $mailbox) use (&$summary, $tenant) {
            $summary['mailboxes']++;
            try {
                $result = $tenant->run($mailbox->company_id, fn () => $this->poll($mailbox));
                $summary['processed']  += $result['processed'];
                $summary['leads']      += $result['leads'];
                $summary['complaints'] += $result['complaints'] ?? 0;
                $summary['spam']       += $result['spam'];
            } catch (\Throwable $e) {
                $msg = "[{$mailbox->email}] " . $e->getMessage();
                $summary['errors'][] = $msg;
                $mailbox->update(['last_status' => 'Error: ' . $e->getMessage(), 'last_checked_at' => now()]);
                Log::warning('CRM mailbox poll failed', ['mailbox' => $mailbox->id, 'error' => $e->getMessage()]);
            }
        });

        return $summary;
    }

    /**
     * Poll a single mailbox.
     *
     * @return array{processed:int, leads:int, spam:int, outcomes:array}
     */
    public function poll(CrmMailbox $mailbox): array
    {
        $client = $this->client($mailbox);
        $client->connect();
        $emails = $client->fetchUnseen();
        $client->disconnect();

        $isComplaint = $mailbox->purpose === 'complaint';
        $leads = 0; $spam = 0; $complaints = 0; $outcomes = [];

        foreach ($emails as $email) {
            $payload = [
                'uid'       => $email['seq_id'] ?? 0,
                'from'      => strtolower($email['from_email'] ?? ''),
                'from_name' => $email['from_name'] ?? '',
                'subject'   => $email['subject'] ?? '',
                'body'      => $email['body'] ?? '',
                'date'      => $email['date'] ?? null,
            ];

            $logCtx = [
                'mailbox' => $mailbox->email ?: ('#' . $mailbox->id),
                'from'    => $payload['from'] ?: '(none)',
                'subject' => \Illuminate\Support\Str::limit($payload['subject'], 80),
            ];

            // A reply from someone in an active sequence is recorded as a reply
            // (auto-ingestion) and not treated as a new lead/complaint.
            if ($this->matchSequenceReply($payload)) {
                Log::info('crm:mailbox triage', $logCtx + ['action' => 'sequence_reply', 'reason' => 'Reply from an active sequence enrollment.']);
                $outcomes[] = ['action' => 'sequence_reply'];
                continue;
            }

            // Route each message to the matching intake based on the mailbox's purpose.
            $outcome = $isComplaint
                ? $this->complaintInbound->process($mailbox, $payload)
                : $this->inbound->process($mailbox, $payload);

            // Per-email triage decision + reason — so it's clear WHY a message
            // did or didn't become a lead/complaint (e.g. AI flagged as spam).
            Log::info('crm:mailbox triage', $logCtx + [
                'action' => $outcome['action'] ?? 'unknown',
                'reason' => $outcome['reason'] ?? null,
                'id'     => $outcome['lead_id'] ?? $outcome['complaint_id'] ?? null,
            ]);

            if ($outcome['action'] === 'lead_created')      $leads++;
            if ($outcome['action'] === 'complaint_created') $complaints++;
            if ($outcome['action'] === 'spam')              $spam++;
            $outcomes[] = $outcome;
        }

        $captured = $isComplaint ? $complaints.' complaint(s)' : $leads.' lead(s) · '.$spam.' spam';
        $mailbox->update([
            'last_checked_at' => now(),
            'last_status'     => count($emails) . ' fetched · ' . $captured,
        ]);

        return ['processed' => count($emails), 'leads' => $leads, 'complaints' => $complaints, 'spam' => $spam, 'outcomes' => $outcomes];
    }

    /**
     * If the message's from-address matches a sequence enrollment, record it as a reply
     * (AI-classify + stop-on-reply + lead activity). Returns true if handled.
     *
     * Not only active enrolments: people answer the last step after the cadence has finished,
     * and answer again after their first reply. Those used to fall through to lead capture and
     * became duplicate "new leads" while the sequence showed nobody had replied. A finished
     * enrolment counts for 60 days after its last send; after that a message is new business.
     */
    private function matchSequenceReply(array $payload): bool
    {
        $from = $payload['from'] ?? '';
        if ($from === '') {
            return false;
        }

        $enrollment = CrmSequenceEnrollment::with('sequence')
            ->where('email', $from)
            ->where(fn ($q) => $q->where('status', 'active')
                ->orWhere(fn ($w) => $w->whereIn('status', ['completed', 'replied', 'stopped'])
                    ->where('last_sent_at', '>=', now()->subDays(60))))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->latest('last_sent_at')->first();
        if (! $enrollment || ! $enrollment->sequence) {
            return false;
        }

        app(CrmSequenceService::class)->recordReply($enrollment, $payload['body'] ?: $payload['subject']);

        return true;
    }
}
