<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Services\TrainingTrackingService;
use App\Modules\Operations\Sheq\Services\CompetencyService;
use App\Modules\Operations\Sheq\Services\ContractorDocumentEngine;
use App\Modules\Operations\Sheq\Services\ContractorNotifier;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\DocumentService;
use App\Modules\Operations\Sheq\Services\InductionService;
use App\Modules\Operations\Sheq\Services\SafetyFileWorkflow;
use App\Modules\Operations\Sheq\Services\SupplierPortalBridge;
use App\Modules\Operations\Sheq\Support\Timestamps;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The reminders nobody was getting.
 *
 * SHEQ already publishes the events that time causes — `sheq:events:sweep` emits
 * `sheq.competency.expiring`, `sheq.action.overdue` and the rest into the outbox, where a webhook
 * or an LMS can consume them. That is integration traffic. It reaches no person.
 *
 * `NotificationRouter` is the only thing that addresses people, and it is driven by the tenant's
 * routing matrix, which is keyed on incident events: it can tell the safety manager about a
 * fatality, and it has no way to say "tell the man whose first-aid certificate lapses next month".
 * So three service methods written for exactly that were never called by anything:
 *
 *   - `CompetencyService::expiryNotificationsDue()` — the 90/60/30/14/7/0-day ladder. Without it a
 *     ticket lapsed in silence and the holder found out at a permit gate, having been turned away
 *     from work they were competent to do a week earlier.
 *   - `DocumentService::dueForReview()` — a procedure past its review date is still in force and
 *     still being acknowledged. Nobody was told it had gone stale.
 *   - `SupplierPortalBridge::chaseList()` / `outstandingFor()` — a supplier invited to submit
 *     evidence and then never chased simply never submits, and the assessment sits INVITED
 *     for ever.
 *
 * Reminders are written into the same dispatch queue as every other SHEQ notification and drained
 * by the same sender, so they are rate-limited, retried, auditable and visible when undelivered.
 * The dedupe key carries the ladder gate, so running this twice in a day sends nothing twice, and
 * a gate missed to an outage is picked up on the next pass rather than lost.
 */
class SendRemindersCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:reminders:send
                            {--company= : Restrict to one company id}';

    protected $description = 'Expire lapsed safety file approvals, then remind people about lapsing competencies, stale documents and outstanding evidence';

    public function handle(TenantContext $tenants, CompetencyService $competencies,
        DocumentService $documents, SupplierPortalBridge $suppliers,
        ContractorService $contractors, SafetyFileWorkflow $safetyFiles): int
    {
        $only = $this->option('company') ? (int) $this->option('company') : null;
        $totals = ['competency' => 0, 'document' => 0, 'supplier' => 0, 'contractor' => 0,
            'expired_files' => 0, 'lapsed' => 0, 'induction' => 0, 'worker_competency' => 0,
            'medical' => 0, 'refreshers' => 0];

        $run = function (int $companyId) use ($competencies, $documents, $suppliers, $contractors,
            $safetyFiles, &$totals): void {
            /*
             * Safety file approvals that have run out (CON-7).
             *
             * Time does this, not a person: an approval that stood until March stops standing in
             * April whether or not anybody opens a screen. `expireLapsed()` was written for
             * exactly that and nothing called it, so on a live installation no approval would ever
             * have expired — a contractor approved once would have read APPROVED for ever, which
             * is the opposite of what an expiring approval is for.
             *
             * Run before the reminders below so a file that lapsed overnight is already EXPIRED
             * when the morning's messages are composed.
             */
            $totals['expired_files'] += $safetyFiles->expireLapsed($companyId);

            $counts = [
                'competency' => $this->remindAboutCompetencies($companyId, $competencies),
                'document' => $this->remindAboutDocuments($companyId, $documents),
                'supplier' => $this->chaseSuppliers($companyId, $suppliers),
                'contractor' => $this->chaseContractorDocuments($companyId, $contractors),
                /*
                 * The three CON-19 notices that time causes and nobody triggers.
                 *
                 * A document that has actually lapsed is a different message from the last
                 * rung of the warning ladder — "expires today" is a request, "has expired and
                 * your people are being refused" is a consequence. An induction nobody did and
                 * a competency running out are the same shape: true on a date, told to nobody
                 * unless something sweeps for them.
                 */
                'lapsed' => $this->chaseLapsedDocuments($companyId),
                'induction' => $this->chaseInductions($companyId),
                'worker_competency' => $this->chaseWorkerCompetencies($companyId),
                /*
                 * Medicals (AUTO-1).
                 *
                 * The one item on AUTO-1's list of seven that nothing warned about. It is also
                 * the worst of the seven to be missing: an expired medical refuses access
                 * outright — `ComplianceDecisionService` treats it as a refusal that no override
                 * reaches — and a renewal needs an occupational health appointment booked weeks
                 * in advance. So the previous behaviour was that a person's medical lapsed
                 * silently and they discovered it at a boom gate on a Monday morning, with no
                 * way to fix it that day.
                 */
                'medical' => $this->chaseMedicals($companyId),
                /*
                 * Refresher enrolment (REG-FIX-7).
                 *
                 * On this command rather than its own, because it belongs beside the expiry
                 * ladder above: the same sweep that tells somebody their licence lapses in thirty
                 * days should be the one that books the course which renews it. Telling them and
                 * not booking it is the state this requirement describes.
                 */
                'refreshers' => app(TrainingTrackingService::class)
                    ->enrolRefreshers($companyId)['enrolled'],
            ];

            foreach ($counts as $key => $n) {
                $totals[$key] += $n;
            }

            if (array_sum($counts) > 0) {
                $this->components->twoColumnDetail("Company {$companyId}", sprintf(
                    '%d competency, %d document, %d supplier, %d contractor, '
                    .'%d lapsed, %d induction, %d worker competency, %d medical, '
                    .'%d refresher enrolment',
                    $counts['competency'], $counts['document'], $counts['supplier'],
                    $counts['contractor'], $counts['lapsed'], $counts['induction'],
                    $counts['worker_competency'], $counts['medical'], $counts['refreshers'],
                ));
            }
        };

        $only !== null
            ? $tenants->run($only, fn () => $run($only))
            : $this->eachTenant($tenants, fn ($id) => $id === null ? null : $run((int) $id));

        $this->components->info(sprintf(
            '%d competency, %d document, %d supplier, %d contractor and %d medical reminder(s) '
            .'queued; %d safety file approval(s) expired.',
            $totals['competency'], $totals['document'], $totals['supplier'], $totals['contractor'],
            $totals['medical'], $totals['expired_files'],
        ));

        if ($totals['refreshers'] > 0) {
            $this->components->info(sprintf(
                '%d refresher course enrolment(s) created for lapsing competencies.',
                $totals['refreshers'],
            ));
        }

        // The trait turns a per-tenant failure into a non-zero exit of its own.
        return self::SUCCESS;
    }

    /**
     * The ladder: 90, 60, 30, 14, 7 days out, and on the day.
     *
     * Addressed to the holder, because they are the one who has to book the refresher. A competency
     * that blocks work when it expires is also sent to the people who hold the register, since a
     * lapse there stops a shift rather than inconveniencing one person.
     */
    private function remindAboutCompetencies(int $companyId, CompetencyService $competencies): int
    {
        $due = $competencies->expiryNotificationsDue($companyId);

        if ($due === []) {
            return 0;
        }

        $people = DB::table('hr_people as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.company_id', $companyId)
            ->whereIn('p.id', array_column($due, 'hr_person_id'))
            ->get(['p.id', 'p.first_name', 'p.surname', 'u.id as user_id', 'u.email'])
            ->keyBy('id');

        $queued = 0;

        foreach ($due as $item) {
            $person = $people->get($item['hr_person_id']);

            // No account and no address means no way to tell them. The register still shows the
            // lapse; inventing a recipient would only hide that nobody was reachable.
            if ($person === null || $person->email === null) {
                continue;
            }

            $days = (int) $item['days_left'];
            $when = $days === 0
                ? 'expires today'
                : "expires in {$days} day".($days === 1 ? '' : 's')." — on {$item['expires_at']}";

            $body = [
                trim(($person->first_name ?? '').' '.($person->surname ?? '')).',',
                '',
                "Your {$item['title']} ({$item['competency']}) {$when}.",
            ];

            if ($item['blocks_work']) {
                $body[] = '';
                $body[] = 'This competency blocks work once it expires: you will not be able to be '
                    .'issued a permit or signed onto the task until it is renewed.';
            }

            $queued += $this->queue($companyId, [
                'event_type' => 'sheq.competency.expiring',
                'subject_type' => 'COMPETENCY_RECORD',
                'subject_id' => (int) $item['record_id'],
                'subject_reference' => $item['competency'],
                // The gate, so each rung of the ladder is a separate notification and running the
                // command twice in one day sends nothing twice.
                'dedupe_key' => 'reminder:competency:'.$days,
                'title' => "{$item['title']} {$when}",
                'body' => implode("\n", $body),
                'severity' => $item['blocks_work'] && $days <= 7 ? 'HIGH' : 'NORMAL',
                'user_id' => $person->user_id ? (int) $person->user_id : null,
                'label' => trim(($person->first_name ?? '').' '.($person->surname ?? '')),
                'address' => $person->email,
                'link' => '/sheq/competency',
            ]);
        }

        return $queued;
    }

    /**
     * A published procedure past its review date.
     *
     * To the document owner, who is the person who can review it. Weekly rather than daily is the
     * schedule's job; the dedupe key here is the review date itself, so a document reminds its
     * owner once per review cycle and again only if the date is moved.
     */
    private function remindAboutDocuments(int $companyId, DocumentService $documents): int
    {
        $stale = $documents->dueForReview($companyId);

        if ($stale === []) {
            return 0;
        }

        $owners = DB::table('users')
            ->where('company_id', $companyId)
            ->whereIn('id', array_values(array_filter(array_column($stale, 'owner_id'))))
            ->get(['id', 'email'])->keyBy('id');

        // `dueForReview` returns uuids; the dedupe constraint is keyed on the numeric subject id,
        // so without this every stale document sharing a review date would collapse into one
        // reminder and only the first owner would hear about it.
        $ids = DB::table('sheq_documents')
            ->where('company_id', $companyId)
            ->whereIn('uuid', array_column($stale, 'uuid'))
            ->pluck('id', 'uuid');

        $queued = 0;

        foreach ($stale as $document) {
            $owner = $document['owner_id'] === null ? null : $owners->get((int) $document['owner_id']);

            if ($owner === null || $owner->email === null) {
                continue;
            }

            $queued += $this->queue($companyId, [
                'event_type' => 'sheq.document.review_due',
                'subject_type' => 'DOCUMENT',
                'subject_id' => (int) ($ids[$document['uuid']] ?? 0),
                'subject_reference' => $document['doc_number'],
                'dedupe_key' => 'reminder:review:'.$document['review_due'],
                'title' => "{$document['doc_number']} is due for review",
                'body' => implode("\n", [
                    "{$document['doc_number']} — {$document['title']} (version {$document['current_version']}) "
                        ."was due for review on {$document['review_due']}.",
                    '',
                    'It is still published and still being acknowledged as current. Either review '
                        .'and reissue it, or move the review date.',
                ]),
                'severity' => 'NORMAL',
                'user_id' => (int) $owner->id,
                'label' => null,
                'address' => $owner->email,
                'link' => '/sheq/documents',
            ]);
        }

        return $queued;
    }

    /**
     * Suppliers sitting on an invitation.
     *
     * `subject_id` is the assessment's own id, so the dedupe key can count the chases: a supplier
     * who has been asked three times is a different conversation from one asked once, and the
     * dispatch rows are the record of having asked.
     */
    private function chaseSuppliers(int $companyId, SupplierPortalBridge $suppliers): int
    {
        $queued = 0;

        foreach ($suppliers->chaseList($companyId) as $assessment) {
            if (empty($assessment['contact_email'])) {
                continue;
            }

            $assessmentId = (int) DB::table('sheq_supplier_assessments')
                ->where('company_id', $companyId)->where('uuid', $assessment['uuid'])->value('id');

            $outstanding = $assessment['supplier_id'] === null
                ? ['outstanding' => []]
                : $suppliers->outstandingFor($companyId, (int) $assessment['supplier_id']);

            // Nothing outstanding and still INVITED means the evidence is in and somebody has to
            // review it — that is not the supplier's to chase.
            if ($assessment['status'] !== 'INVITED' && $outstanding['outstanding'] === []) {
                continue;
            }

            $lines = ["Your safety assessment with us is still {$this->plain($assessment['status'])}."];

            if ($outstanding['outstanding'] !== []) {
                $lines[] = '';
                $lines[] = 'Still needed:';

                foreach ($outstanding['outstanding'] as $document) {
                    $lines[] = " • {$document['label']} — {$document['reason']}";
                }
            }

            // Weekly: the week number, so a chase goes out once a week for as long as it is owed
            // rather than once ever.
            $week = Carbon::now()->format('o-\WW');

            $queued += $this->queue($companyId, [
                'event_type' => 'sheq.supplier.evidence_outstanding',
                'subject_type' => 'SUPPLIER_ASSESSMENT',
                'subject_id' => $assessmentId,
                'subject_reference' => $assessment['supplier_name'],
                'dedupe_key' => 'reminder:supplier:'.$week,
                'title' => 'Outstanding safety evidence',
                'body' => implode("\n", $lines),
                'severity' => 'NORMAL',
                'user_id' => null,
                'label' => $assessment['supplier_name'],
                'address' => $assessment['contact_email'],
                'link' => null,
            ]);
        }

        return $queued;
    }

    /**
     * Contractor paperwork that lapses within the month.
     *
     * `ContractorService::expiringDocuments()` was written for this and nothing called it. The
     * consequence is specific and it happens on a Monday morning: a contractor's letter of good
     * standing expires over the weekend, the compliance file recomputes, and a crew that has
     * driven to site is turned away at the boom. Nobody was warned, because the only place the
     * expiry appeared was the contractor's own profile, which nobody opens until there is a
     * problem.
     *
     * Addressed to the contractor, because they are the ones who have to renew it. The dedupe key
     * is the expiry date itself, so each document chases once per cycle and a renewal with a new
     * date starts a new one.
     */
    private function chaseContractorDocuments(int $companyId, ContractorService $contractors): int
    {
        $engine = app(ContractorDocumentEngine::class);
        $default = $engine->defaultWarningDays($companyId);
        $queued = 0;

        $contractorRows = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'contact_email']);

        foreach ($contractorRows as $contractor) {
            if (empty($contractor->contact_email)) {
                // Nothing to send to. The file still shows the expiry; inventing a recipient would
                // hide that nobody was reachable.
                continue;
            }

            foreach ($engine->liveDocuments($companyId, (int) $contractor->id) as $document) {
                /*
                 * The ladder, per document, with its own warning window (CON-8).
                 *
                 * `gatesDue()` returns the tightest gate the document has reached and not yet been
                 * alerted for, so a certificate filed eight days before expiry warns once at the
                 * seven-day gate rather than four times for 90, 60, 30 and 14 as well.
                 */
                foreach ($engine->gatesDue($companyId, $document) as $gate) {
                    /*
                     * Claimed before it is queued.
                     *
                     * CON-8 requires that the same gate never alerts twice, and only the unique
                     * index guarantees that: between a retried command and two workers running at
                     * once, a check-then-send would both pass. Claiming first means the worst case
                     * is a claimed gate with no message — visible, and recoverable by widening the
                     * gate — rather than a contractor chased six times in an afternoon.
                     */
                    if (! $engine->claimGate($companyId, (int) $document->id, $gate,
                        (string) $document->expires_on)) {
                        continue;
                    }

                    $state = $engine->stateOf($document, $default);

                    $queued += app(ContractorNotifier::class)->documentExpiring(
                        $companyId, $contractor, $document, $gate,
                        (int) ($state['days_left'] ?? 0));
                }
            }
        }

        return $queued;
    }

    /**
     * One dispatch row, on the queue everything else uses.
     *
     * `insertOrIgnore` against the dedupe constraint is the whole repeat guard: the unique key is
     * (company, subject type, subject id, channel, dedupe key), so a reminder for a given gate
     * exists at most once however many times this runs.
     */
    private function queue(int $companyId, array $reminder): int
    {
        $now = Carbon::now();

        return DB::table('sheq_notification_dispatches')->insertOrIgnore([
            'company_id' => $companyId,
            'uuid' => (string) Str::uuid(),
            'rule_id' => null,
            // Not 'RULE': no rule matched and none was consulted. A reminder is the system's own
            // obligation, not a routing decision, and the queue should say which it was.
            'trigger_reason' => 'REMINDER',
            'event_type' => $reminder['event_type'],
            'subject_type' => $reminder['subject_type'],
            'subject_id' => $reminder['subject_id'],
            'subject_reference' => $reminder['subject_reference'],
            'channel' => 'EMAIL',
            'recipient_kind' => $reminder['user_id'] !== null ? 'USER' : 'CONTACT',
            'recipient_user_id' => $reminder['user_id'],
            'recipient_label' => $reminder['label'],
            'recipient_address' => $reminder['address'],
            'recipient_role' => null,
            'dedupe_key' => $reminder['dedupe_key'],
            'severity' => $reminder['severity'],
            'payload' => json_encode([
                'title' => $reminder['title'],
                'body' => $reminder['body'],
                'reference' => $reminder['subject_reference'],
                'link' => $reminder['link'],
            ]),
            'send_after' => Timestamps::utc($now),
            'status' => 'PENDING',
            'created_at' => $now,
            'updated_at' => $now,
        ]) ? 1 : 0;
    }

    private function plain(string $status): string
    {
        return strtolower(str_replace('_', ' ', $status));
    }

    // ── CON-19: the notices time causes ───────────────────────────────────────────────────────

    /**
     * Documents that have actually lapsed.
     *
     * Not the same message as the last rung of the warning ladder, and not a duplicate of it.
     * "Expires today" is a request to renew; "expired on the 3rd, and your people are being
     * refused" is a consequence that has already begun. The dedupe key is the document and its
     * expiry date, so each lapse is announced once and a renewal that lapses again later is
     * announced again.
     */
    private function chaseLapsedDocuments(int $companyId): int
    {
        $notifier = app(ContractorNotifier::class);
        $today = Carbon::today();
        $queued = 0;

        /*
         * A window rather than everything ever expired. On the day this ships, a tenant with
         * three years of history would send a contractor forty notices about certificates that
         * lapsed in 2024 — which is how a notification channel gets filtered into a folder
         * nobody opens.
         */
        $since = $today->copy()->subDays(14);

        $rows = DB::table('sheq_contractor_documents as d')
            ->join('sheq_contractors as c', 'c.id', '=', 'd.contractor_id')
            ->where('d.company_id', $companyId)
            ->whereNull('d.deleted_at')->whereNull('d.superseded_at')
            ->where('d.status', ContractorDocumentEngine::ACCEPTED)
            ->whereNotNull('d.expires_on')
            ->whereBetween('d.expires_on', [$since->toDateString(), $today->toDateString()])
            ->whereNull('c.deleted_at')->where('c.is_active', true)
            ->get(['d.id', 'd.document_type', 'd.expires_on', 'd.is_mandatory', 'd.site_id',
                'c.id as contractor_id', 'c.name', 'c.contact_email', 'c.contact_phone']);

        foreach ($rows as $row) {
            // Expiry is end of day: a certificate expiring today is still valid today, so it
            // is not announced as lapsed until tomorrow.
            if (Carbon::parse($row->expires_on)->gte($today)) {
                continue;
            }

            $contractor = (object) ['id' => $row->contractor_id, 'name' => $row->name,
                'contact_email' => $row->contact_email, 'contact_phone' => $row->contact_phone];

            $document = (object) ['id' => $row->id, 'document_type' => $row->document_type,
                'expires_on' => $row->expires_on, 'is_mandatory' => $row->is_mandatory,
                'site_id' => $row->site_id];

            $queued += $notifier->documentExpired($companyId, $contractor, $document);
        }

        return $queued;
    }

    /**
     * People on a contractor's roster who cannot be inducted-checked onto site.
     *
     * One message per contractor listing everybody, not one per person: a firm with fifteen
     * uninducted workers needs a list, not fifteen emails, and fifteen emails is how the
     * sixteenth gets ignored.
     */
    private function chaseInductions(int $companyId): int
    {
        $notifier = app(ContractorNotifier::class);
        $inductions = app(InductionService::class);
        $today = Carbon::today();
        $queued = 0;

        $contractors = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)
            ->get(['id', 'name', 'contact_email', 'contact_phone']);

        foreach ($contractors as $contractor) {
            $workers = DB::table('sheq_contractor_workers')
                ->where('company_id', $companyId)->where('contractor_id', $contractor->id)
                ->whereNull('deleted_at')->where('is_active', true)
                ->get(['id', 'name', 'inducted_on', 'induction_expires']);

            $outstanding = [];

            foreach ($workers as $worker) {
                // Both sources, exactly as the gate reads them. A portal or a reminder that
                // disagreed with the boom would send contractors chasing inductions that are
                // already in order.
                $paperwork = $worker->inducted_on !== null
                    && ($worker->induction_expires === null
                        || Carbon::parse($worker->induction_expires)->gte($today));

                if ($paperwork
                    || $inductions->isCurrent($companyId, 'CONTRACTOR_WORKER', (int) $worker->id)) {
                    continue;
                }

                $outstanding[] = [
                    'id' => (int) $worker->id,
                    'name' => $worker->name,
                    'expired_on' => $worker->induction_expires,
                ];
            }

            $queued += $notifier->inductionRequired($companyId, $contractor, $outstanding);
        }

        return $queued;
    }

    /**
     * Contractor workers' competencies running out, or run out.
     *
     * `CompetencyService::expiryNotificationsDue()` already does this ladder for the host's own
     * employees, and cannot do it here: it is keyed on `hr_person_id` and a contractor's people
     * have no HR record. Their credentials live in their own table, and nothing was reading it
     * for expiry at all.
     */
    private function chaseWorkerCompetencies(int $companyId): int
    {
        $notifier = app(ContractorNotifier::class);
        $today = Carbon::today();
        $queued = 0;

        $rows = DB::table('sheq_contractor_worker_credentials as k')
            ->join('sheq_contractor_workers as w', 'w.id', '=', 'k.worker_id')
            ->join('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
            ->where('k.company_id', $companyId)
            ->whereNull('k.deleted_at')->where('k.status', ContractorDocumentEngine::ACCEPTED)
            ->whereNotNull('k.expires_on')
            ->whereNull('w.deleted_at')->where('w.is_active', true)
            ->whereNull('c.deleted_at')->where('c.is_active', true)
            /*
             * Thirty days ahead, and fourteen days behind. Ahead so there is time to book a
             * renewal; behind so a lapse is announced once rather than every day for ever, and
             * so switching this on does not dredge up three years of history.
             */
            ->whereBetween('k.expires_on', [
                $today->copy()->subDays(14)->toDateString(),
                $today->copy()->addDays(30)->toDateString(),
            ])
            ->get(['k.id', 'k.title', 'k.expires_on', 'w.name as worker_name',
                'c.id as contractor_id', 'c.name', 'c.contact_email', 'c.contact_phone']);

        foreach ($rows as $row) {
            $contractor = (object) ['id' => $row->contractor_id, 'name' => $row->name,
                'contact_email' => $row->contact_email, 'contact_phone' => $row->contact_phone];

            $credential = (object) ['id' => $row->id, 'title' => $row->title,
                'expires_on' => $row->expires_on];

            $queued += $notifier->competencyExpiring($companyId, $contractor, $row->worker_name,
                $credential, (int) $today->diffInDays(Carbon::parse($row->expires_on), false));
        }

        return $queued;
    }

    /**
     * Medicals that are running out, and ones that have run out (AUTO-1).
     *
     * The ladder is the same shape as the competency one and shorter at the top: 60, 30, 14 and 7
     * days, then the day itself. Sixty rather than ninety because an occupational health
     * appointment is bookable inside two months and a reminder three months out gets ignored;
     * seven because that is the last point at which a clinic slot is still findable.
     *
     * Fourteen days of lookback, for the same two reasons as the worker credentials: a lapse is
     * announced rather than re-announced daily for ever, and switching this on does not dredge up
     * every medical that has expired since the module was installed.
     *
     * Addressed to the person, because they are the one who attends the appointment. A fitness
     * outcome that already restricts them is said plainly in the body — somebody who was found
     * fit with conditions needs to know the renewal is also the review of those conditions.
     */
    private function chaseMedicals(int $companyId): int
    {
        $today = Carbon::today();

        $rows = DB::table('sheq_medical_records as m')
            ->join('hr_people as p', 'p.id', '=', 'm.hr_person_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('sheq_medical_types as t', 't.id', '=', 'm.medical_type_id')
            ->where('m.company_id', $companyId)
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.expires_on')
            ->where('p.status', 1)
            ->whereBetween('m.expires_on', [
                $today->copy()->subDays(14)->toDateString(),
                $today->copy()->addDays(60)->toDateString(),
            ])
            /*
             * One row per person per medical type: the newest. Without this, somebody with three
             * years of annual medicals is chased about the two that expired in 2024 as well as
             * the one expiring next month.
             */
            ->orderBy('m.hr_person_id')->orderBy('m.medical_type_id')->orderByDesc('m.expires_on')
            ->get(['m.id', 'm.hr_person_id', 'm.medical_type_id', 'm.expires_on',
                'm.fitness_outcome', 'm.restrictions', 't.name as type_name',
                'p.first_name', 'p.surname', 'u.id as user_id', 'u.email']);

        $seen = [];
        $queued = 0;

        foreach ($rows as $row) {
            $key = $row->hr_person_id.':'.($row->medical_type_id ?? 0);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            // No account and no address means no way to tell them. The register still shows the
            // lapse; inventing a recipient would only hide that nobody was reachable.
            if ($row->email === null) {
                continue;
            }

            $days = (int) $today->diffInDays(Carbon::parse($row->expires_on), false);

            // Only the rungs, so running this daily does not send a message every day. The
            // dedupe key below makes a repeat within the same rung a no-op in any case; this
            // keeps the ladder itself deliberate.
            $rung = match (true) {
                $days < 0 => 'expired',
                $days === 0 => '0',
                $days <= 7 => '7',
                $days <= 14 => '14',
                $days <= 30 => '30',
                $days <= 60 => '60',
                default => null,
            };

            if ($rung === null) {
                continue;
            }

            $name = trim(($row->first_name ?? '').' '.($row->surname ?? ''));
            $type = $row->type_name ?? 'medical examination';

            $when = match (true) {
                $days < 0 => "expired on {$row->expires_on}",
                $days === 0 => 'expires today',
                default => "expires in {$days} day".($days === 1 ? '' : 's')
                    ." — on {$row->expires_on}",
            };

            $body = [$name.',', '', "Your {$type} {$when}."];

            if ($days < 0) {
                // The consequence, stated rather than implied. An expired medical is refused
                // outright by the compliance engine and no override reaches it.
                $body[] = '';
                $body[] = 'An expired medical refuses site access on its own, and no temporary '
                    .'override can authorise it. Book the examination before your next shift.';
            } else {
                $body[] = '';
                $body[] = 'Once it expires you will be refused access to site until it is '
                    .'renewed, so the appointment needs to be booked before then.';
            }

            if (! empty($row->restrictions)) {
                $body[] = '';
                $body[] = 'Your current certificate records restrictions, which the renewal will '
                    .'also review: '.$row->restrictions;
            }

            $queued += $this->queue($companyId, [
                'event_type' => $days < 0 ? 'sheq.medical.expired' : 'sheq.medical.expiring',
                'subject_type' => 'MEDICAL_RECORD',
                'subject_id' => (int) $row->id,
                'subject_reference' => $type,
                // The rung, so each step of the ladder is its own notification and two runs in
                // one day send nothing twice (AUTO-6).
                'dedupe_key' => 'reminder:medical:'.$rung,
                'title' => "{$type} {$when}",
                'body' => implode("\n", $body),
                'severity' => $days < 0 ? 'HIGH' : ($days <= 7 ? 'HIGH' : 'NORMAL'),
                'user_id' => $row->user_id ? (int) $row->user_id : null,
                'label' => $name,
                'address' => $row->email,
                'link' => '/sheq/medical',
            ]);
        }

        return $queued;
    }
}
