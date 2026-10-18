<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Compliance\ReasonCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Telling a contractor what has changed about their standing (CON-19).
 *
 * Nine things a contractor has to be told, and before this they were told one of them: a
 * document approaching expiry, by email, from the weekday reminder sweep. Everything else
 * happened silently. A safety file was rejected and the contractor found out when somebody
 * telephoned; a gate closed and they found out when a crew of twelve was turned away at 06:00.
 *
 * Two kinds of event, and they need different machinery.
 *
 * **The ones time causes** — a document approaching expiry, one that has lapsed, an induction
 * that was never done, a competency running out — nobody triggers. They are swept, and the
 * guard against repetition is the dedupe key: a ladder rung for a document fires once per
 * expiry cycle, so a renewal with a new date starts a fresh ladder rather than being suppressed
 * by the old one.
 *
 * **The ones a person causes** — a file approved or refused, a gate opened or closed — fire at
 * the moment of the change, from inside the service that makes it. Sweeping for those would
 * mean a contractor learning on Tuesday morning that they were blocked on Monday afternoon,
 * which is a shift lost for the sake of a cron schedule.
 *
 * Every one of them is queued, never sent inline. A notification sent inside a database
 * transaction that then rolls back is a message about something that did not happen, and the
 * existing dispatch queue already solves delivery, retries and dedupe.
 */
class ContractorNotifier
{
    /**
     * The nine, declared rather than scattered.
     *
     * `ContractorNotificationTest` asserts every one of these is reachable from production
     * code. A catalogue that lists an event nothing sends is worse than no catalogue: it reads
     * as a promise the system has made.
     *
     * @var array<string, array{severity:string, to_contractor:bool}>
     */
    public const EVENTS = [
        // Time causes these four.
        'sheq.contractor.document_expiring' => ['severity' => 'NORMAL', 'to_contractor' => true],
        'sheq.contractor.document_expired' => ['severity' => 'HIGH', 'to_contractor' => true],
        'sheq.contractor.induction_required' => ['severity' => 'NORMAL', 'to_contractor' => true],
        'sheq.contractor.competency_expiring' => ['severity' => 'NORMAL', 'to_contractor' => true],

        // A person causes these five, and they fire the moment it happens.
        'sheq.contractor.safety_file_approved' => ['severity' => 'NORMAL', 'to_contractor' => true],
        'sheq.contractor.safety_file_rejected' => ['severity' => 'HIGH', 'to_contractor' => true],
        'sheq.contractor.blocked' => ['severity' => 'CRITICAL', 'to_contractor' => true],
        'sheq.contractor.unblocked' => ['severity' => 'NORMAL', 'to_contractor' => true],
        'sheq.contractor.worker_blocked' => ['severity' => 'HIGH', 'to_contractor' => true],
    ];

    /** Channels the transport can actually deliver. PUSH and VOICE are accepted and never sent. */
    public const CHANNELS = ['EMAIL', 'SMS', 'WHATSAPP'];

    /**
     * A document is drawing to its expiry.
     *
     * Worded for the rung it is at, because "expires in 90 days" and "expires today" ask for
     * two different things from the reader.
     */
    public function documentExpiring(int $companyId, object $contractor, object $document,
        int $gate, int $daysLeft): int
    {
        $label = $this->label($document->document_type);
        $site = $this->siteName($companyId, $document->site_id ?? null);

        $when = $daysLeft <= 0
            ? 'expires today'
            : 'expires in '.$daysLeft.' day'.($daysLeft === 1 ? '' : 's')
                .' — on '.$document->expires_on;

        $body = [
            $contractor->name.',',
            '',
            'Your '.$label.($site ? ' for '.$site : '').' '.$when.'.',
        ];

        if ($document->is_mandatory ?? false) {
            $body[] = '';
            $body[] = 'This document is mandatory: once it lapses your people are refused entry '
                .'at the gate until a current one is on file. Send the renewal before then.';
        }

        return $this->queue($companyId, 'sheq.contractor.document_expiring', $contractor, [
            // The gate AND the expiry: the gate so each rung is its own message, the expiry so
            // a renewal starts a fresh ladder instead of being suppressed by the old one.
            'dedupe_key' => 'doc:expiring:'.$document->id.':'.$gate.':'.$document->expires_on,
            'title' => $label.' '.$when,
            'body' => implode("\n", $body),
            'severity' => (($document->is_mandatory ?? false) && $gate <= 7) ? 'HIGH' : 'NORMAL',
        ]);
    }

    /**
     * A document has lapsed.
     *
     * Distinct from the last rung of the warning ladder, and not a duplicate of it. "Expires
     * today" is a request; "has expired, and your people are being refused" is a different
     * message with a different consequence, and the day it becomes true is the day it has to
     * be sent.
     */
    public function documentExpired(int $companyId, object $contractor, object $document): int
    {
        $label = $this->label($document->document_type);
        $site = $this->siteName($companyId, $document->site_id ?? null);

        $body = [
            $contractor->name.',',
            '',
            'Your '.$label.($site ? ' for '.$site : '').' expired on '.$document->expires_on.'.',
        ];

        if ($document->is_mandatory ?? false) {
            $body[] = '';
            $body[] = 'Your people are being refused entry until a current one is on file.';
        }

        $body[] = '';
        $body[] = 'Send the renewal through your supplier portal and it will be reviewed.';

        return $this->queue($companyId, 'sheq.contractor.document_expired', $contractor, [
            // Once per expiry date, so a renewal that lapses again later is announced again.
            'dedupe_key' => 'doc:expired:'.$document->id.':'.$document->expires_on,
            'title' => $label.' has expired',
            'body' => implode("\n", $body),
            'severity' => ($document->is_mandatory ?? false) ? 'HIGH' : 'NORMAL',
        ]);
    }

    /** The safety file has been accepted. */
    public function safetyFileApproved(int $companyId, object $contractor,
        ?string $validUntil): int
    {
        $body = [
            $contractor->name.',',
            '',
            'Your safety file has been approved.',
        ];

        if ($validUntil !== null) {
            $body[] = '';
            $body[] = 'It is valid until '.$validUntil.'. We will remind you before then.';
        }

        return $this->queue($companyId, 'sheq.contractor.safety_file_approved', $contractor, [
            // Per version: a resubmitted file approved again is a new fact worth sending.
            'dedupe_key' => 'file:approved:'.$contractor->id.':'.($validUntil ?? 'open'),
            'title' => 'Safety file approved',
            'body' => implode("\n", $body),
            'severity' => 'NORMAL',
        ]);
    }

    /**
     * The safety file has been refused, or changes asked for.
     *
     * The reviewer's own words travel with it. "Rejected" on its own starts a telephone call
     * rather than a resubmission, which is the whole reason the workflow demands a reason.
     *
     * @param  list<string>  $changes
     */
    public function safetyFileRejected(int $companyId, object $contractor, string $state,
        ?string $reason, array $changes = []): int
    {
        $body = [
            $contractor->name.',',
            '',
            $state === SafetyFileWorkflow::BLOCKED
                ? 'Your safety file has been refused.'
                : 'Your safety file needs changes before it can be approved.',
        ];

        if ($reason !== null && trim($reason) !== '') {
            $body[] = '';
            $body[] = $reason;
        }

        if ($changes !== []) {
            $body[] = '';

            foreach ($changes as $change) {
                $body[] = '- '.$change;
            }
        }

        $body[] = '';
        $body[] = 'Make the changes and resubmit through your supplier portal.';

        return $this->queue($companyId, 'sheq.contractor.safety_file_rejected', $contractor, [
            // Keyed on the reason as well as the state: a second refusal for a different
            // reason is a different message, and suppressing it would leave a contractor
            // fixing the wrong thing.
            'dedupe_key' => 'file:rejected:'.$contractor->id.':'.$state.':'
                .substr(sha1((string) $reason), 0, 12),
            'title' => $state === SafetyFileWorkflow::BLOCKED
                ? 'Safety file refused' : 'Safety file needs changes',
            'body' => implode("\n", $body),
            'severity' => 'HIGH',
        ]);
    }

    /**
     * The gate has closed.
     *
     * The most consequential message in the set, and the one whose absence costs the most: a
     * contractor who is not told arrives with a crew at 06:00 and loses the shift.
     *
     * @param  list<string>  $reasons  the exact reasons, not their codes
     */
    /**
     * @param  list<string|array<string,mixed>>  $reasons  Findings from the compliance engine,
     *   or plain strings. Findings are what AUTO-3 needs: a flattened string has lost the code
     *   the remedy is looked up by and the evidence the decision was made on.
     */
    public function blocked(int $companyId, object $contractor, array $reasons): int
    {
        $body = [
            $contractor->name.',',
            '',
            'Your people are now being refused entry to site.',
        ];

        /*
         * AUTO-3: the reason, the evidence, and the required corrective action.
         *
         * All three, because a notice with only the first is a notice that generates a phone
         * call. "Your letter of good standing has expired" tells somebody they have a problem;
         * adding the date it expired and "upload the renewed letter — the old one stays on the
         * record as history" tells them how to stop having it, without ringing anybody.
         *
         * Plain strings are still accepted so an existing caller is not broken; they simply
         * arrive without the evidence and the remedy, which is what they had before.
         */
        $normalised = array_map(fn ($reason) => is_array($reason) ? $reason : ['detail' => $reason],
            $reasons);

        if ($normalised !== []) {
            $body[] = '';
            $body[] = 'Because:';

            foreach ($normalised as $reason) {
                $body[] = '';
                $body[] = '- '.($reason['detail'] ?? 'A requirement is not met.');

                foreach ($this->evidenceLines($reason) as $line) {
                    $body[] = '    '.$line;
                }

                if (! empty($reason['code'])) {
                    $body[] = '    To fix it: '.ReasonCode::remedyFor($reason['code']);
                }
            }
        }

        $body[] = '';
        $body[] = 'Your supplier portal shows what is outstanding and lets you send it.';

        $keys = array_map(
            fn (array $r) => ($r['code'] ?? '').':'.($r['detail'] ?? ''), $normalised);

        return $this->queue($companyId, 'sheq.contractor.blocked', $contractor, [
            /*
             * Keyed on the reasons, not only on the contractor.
             *
             * A contractor blocked for a lapsed COID, unblocked, then blocked a month later
             * for a lapsed insurance is two different facts and both have to arrive. Keying on
             * the contractor alone would send the first and silently swallow the second.
             */
            'dedupe_key' => 'blocked:'.$contractor->id.':'
                .substr(sha1(implode('|', $keys)), 0, 12),
            'title' => 'Site access suspended',
            'body' => implode("\n", $body),
            'severity' => 'CRITICAL',
        ]);
    }

    /**
     * The evidence behind one finding, as lines somebody can read (AUTO-3).
     *
     * Whitelisted rather than dumped: a finding carries whatever the check that produced it had
     * to hand, and some of that is internal ids and some of it is a worker's medical restriction.
     * This message goes to the contractor, so only the fields that identify the thing to fix are
     * named — and nothing clinical, which is why `restrictions` is not on this list even though
     * the medical check has it.
     */
    private function evidenceLines(array $finding): array
    {
        $lines = [];

        foreach ([
            'document_title' => 'Document',
            'document_type' => 'Type',
            'credential_title' => 'Competency',
            'competency' => 'Competency',
            'worker_name' => 'Worker',
            'site_name' => 'Site',
            'activity' => 'Activity',
            'reference' => 'Reference',
            'expires_on' => 'Expired on',
            'expires_at' => 'Expired on',
            'expired_on' => 'Expired on',
            'required_by' => 'Required by',
            'rejection_reason' => 'Refused because',
        ] as $field => $heading) {
            if (! empty($finding[$field]) && is_scalar($finding[$field])) {
                $lines[] = $heading.': '.$finding[$field];
            }
        }

        return $lines;
    }

    /** The gate has opened again. */
    public function unblocked(int $companyId, object $contractor, string $status): int
    {
        $body = [
            $contractor->name.',',
            '',
            'Your people may come on site again.',
            '',
            $status === ContractorComplianceStatus::COMPLIANT
                ? 'Your file is complete.'
                : 'Your file is still short in places — your supplier portal shows what, and '
                    .'leaving it will close the gate again.',
        ];

        return $this->queue($companyId, 'sheq.contractor.unblocked', $contractor, [
            'dedupe_key' => 'unblocked:'.$contractor->id.':'.Carbon::now()->toDateString(),
            'title' => 'Site access restored',
            'body' => implode("\n", $body),
            'severity' => 'NORMAL',
        ]);
    }

    /**
     * One named person is being refused, rather than the whole firm.
     *
     * Named, because "one of your workers" sends a contractor to check all forty.
     */
    public function workerBlocked(int $companyId, object $contractor, string $workerName,
        int $workerId, array $reasons): int
    {
        $body = [
            $contractor->name.',',
            '',
            $workerName.' is being refused entry to site.',
        ];

        if ($reasons !== []) {
            $body[] = '';
            $body[] = 'Because:';

            foreach ($reasons as $reason) {
                $body[] = '- '.$reason;
            }
        }

        $body[] = '';
        $body[] = 'Your other people are unaffected.';

        return $this->queue($companyId, 'sheq.contractor.worker_blocked', $contractor, [
            'dedupe_key' => 'worker:blocked:'.$workerId.':'
                .substr(sha1(implode('|', $reasons)), 0, 12),
            'title' => $workerName.' refused entry',
            'body' => implode("\n", $body),
            'severity' => 'HIGH',
        ]);
    }

    /**
     * People on the roster who have never been inducted, or whose induction has lapsed.
     *
     * One message for the lot rather than one per person. A contractor with fifteen uninducted
     * workers does not need fifteen emails; they need one list.
     *
     * @param  list<array{id:int, name:string, expired_on:?string}>  $workers
     */
    public function inductionRequired(int $companyId, object $contractor, array $workers): int
    {
        if ($workers === []) {
            return 0;
        }

        $body = [
            $contractor->name.',',
            '',
            count($workers) === 1
                ? 'One of your people needs a site induction before they can work:'
                : count($workers).' of your people need a site induction before they can work:',
            '',
        ];

        foreach ($workers as $worker) {
            $body[] = '- '.$worker['name']
                .($worker['expired_on'] !== null
                    ? ' (induction expired '.$worker['expired_on'].')'
                    : ' (never inducted)');
        }

        return $this->queue($companyId, 'sheq.contractor.induction_required', $contractor, [
            /*
             * Keyed on who is on the list, so adding a sixteenth person sends a new message and
             * inducting one of the fifteen does not. Keyed on the week as well, so a list that
             * does not change still nags once a week rather than once ever.
             */
            'dedupe_key' => 'induction:'.$contractor->id.':'
                .Carbon::now()->isoFormat('GGGG-[W]WW').':'
                .substr(sha1(implode('|', array_column($workers, 'id'))), 0, 12),
            'title' => count($workers).' of your people need a site induction',
            'body' => implode("\n", $body),
            'severity' => 'NORMAL',
        ]);
    }

    /**
     * A worker's competency is running out, or has.
     *
     * One message per credential, because each is a separate certificate with its own renewal
     * and its own provider.
     */
    public function competencyExpiring(int $companyId, object $contractor, string $workerName,
        object $credential, int $daysLeft): int
    {
        $when = $daysLeft < 0
            ? 'expired on '.$credential->expires_on
            : ($daysLeft === 0
                ? 'expires today'
                : 'expires in '.$daysLeft.' day'.($daysLeft === 1 ? '' : 's')
                    .' — on '.$credential->expires_on);

        $body = [
            $contractor->name.',',
            '',
            $workerName."'s ".$credential->title.' '.$when.'.',
            '',
            $daysLeft < 0
                ? 'They are being refused the work it covers until a current one is on file.'
                : 'Send the renewal before then, or they will be refused the work it covers.',
        ];

        return $this->queue($companyId, 'sheq.contractor.competency_expiring', $contractor, [
            'dedupe_key' => 'competency:'.$credential->id.':'.$credential->expires_on
                .':'.($daysLeft < 0 ? 'expired' : 'warning'),
            'title' => $workerName."'s ".$credential->title.' '.$when,
            'body' => implode("\n", $body),
            'severity' => $daysLeft < 0 ? 'HIGH' : 'NORMAL',
        ]);
    }

    /**
     * Put one message on the queue, on every channel this tenant has chosen.
     *
     * `insertOrIgnore` against the dispatch dedupe index is the whole repeat guard: the unique
     * key is (company, subject type, subject id, channel, dedupe key), so a message exists at
     * most once per channel however many times a sweep runs or a transaction is retried.
     *
     * Nothing is sent here. `NotificationSender::drain()` does that, which is what makes this
     * safe to call from inside a transaction that might still roll back.
     *
     * @param  array{dedupe_key:string, title:string, body:string, severity:string}  $message
     * @return int how many dispatch rows were actually created
     */
    private function queue(int $companyId, string $eventType, object $contractor,
        array $message): int
    {
        $address = $contractor->contact_email ?? null;
        $phone = $contractor->contact_phone ?? null;

        $channels = $this->channelsFor($companyId, $address, $phone);

        if ($channels === []) {
            /*
             * Nobody to send to. Deliberately silent rather than queued to an invented
             * recipient: the file still shows the expiry and the register still shows the
             * block, and a dispatch addressed to nothing would read in the log as a message
             * that was sent.
             */
            return 0;
        }

        $queued = 0;
        $now = Carbon::now();

        foreach ($channels as $channel) {
            $queued += DB::table('sheq_notification_dispatches')->insertOrIgnore([
                'company_id' => $companyId,
                'uuid' => (string) Str::uuid(),
                'rule_id' => null,
                // Not 'RULE': no rule matched and none was consulted. These are the system's
                // own obligations to the contractor, not a routing decision.
                'trigger_reason' => 'REMINDER',
                'event_type' => $eventType,
                'subject_type' => 'CONTRACTOR',
                'subject_id' => (int) $contractor->id,
                'subject_reference' => $contractor->name,
                'channel' => $channel,
                'recipient_kind' => 'CONTACT',
                'recipient_user_id' => null,
                'recipient_label' => $contractor->name,
                'recipient_address' => $channel === 'EMAIL' ? $address : $phone,
                'recipient_role' => null,
                'dedupe_key' => $message['dedupe_key'],
                'severity' => $message['severity'],
                'payload' => json_encode([
                    'title' => $message['title'],
                    'body' => $message['body'],
                ]),
                'send_after' => $now,
                'status' => 'PENDING',
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $queued;
    }

    /**
     * Which channels this tenant sends contractor notices on.
     *
     * Email only by default, because that is what the module did before and switching a host to
     * WhatsApp without being asked would start sending to numbers nobody checked. A channel
     * with no address is dropped rather than queued: a WhatsApp dispatch to a null number sits
     * in the queue failing five times and then looks, in the log, like an attempt.
     *
     * @return list<string>
     */
    private function channelsFor(int $companyId, ?string $email, ?string $phone): array
    {
        $stored = DB::table('sheq_settings')->where('company_id', $companyId)
            ->value('contractor_notification_channels');

        $chosen = json_decode((string) $stored, true);

        /*
         * An absent setting and an empty one are different answers.
         *
         * NULL means nobody has been asked, and the honest default is what the module did
         * before: email. `[]` means a host went to the screen and switched everything off,
         * which is a decision — treating it as "not set" and sending email anyway would be the
         * system quietly overruling them, and they would never find out it had.
         */
        if (! is_array($chosen)) {
            $chosen = ['EMAIL'];
        }

        $usable = [];

        foreach ($chosen as $channel) {
            $channel = strtoupper(trim((string) $channel));

            if (! in_array($channel, self::CHANNELS, true)) {
                continue;
            }

            $address = $channel === 'EMAIL' ? $email : $phone;

            if ($address !== null && trim($address) !== '') {
                $usable[] = $channel;
            }
        }

        return array_values(array_unique($usable));
    }

    private function label(string $type): string
    {
        return ContractorService::documentTypes()[$type]
            ?? ucfirst(strtolower(str_replace('_', ' ', $type)));
    }

    private function siteName(int $companyId, ?int $siteId): ?string
    {
        return $siteId === null ? null : DB::table('sheq_sites')
            ->where('company_id', $companyId)->where('id', $siteId)->value('name');
    }
}
