<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Compliance\ComplianceDecisionService;
use App\Modules\Operations\Sheq\Support\Timestamps;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The QR pass, and what it resolves to when somebody scans it (CON-11, CON-12, CON-13).
 *
 * The code carries one thing: an opaque token. Nothing else — not an ID number, not a medical
 * outcome, not a certificate, not a line of the safety file. A QR code is printed on a card,
 * photographed and forwarded on WhatsApp, so whatever is encoded in it is published; and anything
 * encoded in it is also frozen, so a code that says "COID valid until March" keeps saying that in
 * April. The token resolves to a live check instead, which is why the same printed card reads
 * APPROVED today and DENIED tomorrow when the insurance lapses.
 *
 * The verification is deliberately answerable without a login. A guard at a boom holding their own
 * phone cannot be expected to have an account, and a check that needs one is a check that gets
 * replaced by waving people through. The token is the credential, which is why it is sixty-four
 * random characters rather than anything derivable from a visible identifier, why it can be
 * rotated when a card is lost, and why every scan is recorded.
 *
 * What a scan returns is assembled from the same services the rest of the module uses:
 * `ContractorComplianceStatus` for the contractor's standing, the worker's own credentials for
 * theirs, the induction records both the access gate and the profile read, and — for the PPE a job
 * needs — the PPE-level controls on the risk assessment in force at the site. There is no second
 * opinion about compliance anywhere in here; a gate that disagreed with the register would be
 * worse than no gate.
 */
class AccessPassService
{
    /** What a scan is recorded as, in the register the access gate already writes to. */
    private const DIRECTION = 'VERIFY';

    /**
     * Rows a worker pass shows even when they did not apply.
     *
     * The four a guard actually looks for. "Medical: nothing on record" and no medical row at all
     * look identical from two feet away on a phone in sunlight, and only one of them means this
     * system was never told. The work-scope checks are hidden when they do not apply, because a
     * list of thirteen rows of which eight say "not applicable" is a list nobody reads to the
     * end — which is how the one row that matters gets missed.
     *
     * @var list<string>
     */
    private const ALWAYS_SHOWN = ['WORKER', 'INDUCTION', 'COMPETENCY', 'MEDICAL'];

    public function __construct(
        private readonly RiskAssessmentService $assessments,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * The contractor's token, issued on first use.
     *
     * Issued lazily rather than on registration: a contractor who never comes to site never needs
     * a card, and a backfill across the estate would mint tokens nobody asked for.
     */
    public function contractorToken(int $companyId, string $contractorUuid, ?int $actorId = null): string
    {
        return $this->tokenFor($companyId, 'sheq_contractors', 'uuid', $contractorUuid,
            'CONTRACTOR', $actorId);
    }

    /** The worker's own token (CON-13). */
    public function workerToken(int $companyId, string $workerUuid, ?int $actorId = null): string
    {
        return $this->tokenFor($companyId, 'sheq_contractor_workers', 'uuid', $workerUuid,
            'CONTRACTOR_WORKER', $actorId);
    }

    /**
     * Retire the current token and issue another.
     *
     * A card is lost and a dismissed worker keeps theirs, so the old code has to stop resolving.
     * The retirement is on the record, because "my card stopped working" needs an answer.
     */
    /**
     * What the printed card says about the person carrying it.
     *
     * Only what a guard at a boom needs to match a face to the register: the photograph, the name,
     * the trade, the employer, the site and the project. Deliberately nothing more — no ID number,
     * no medical fact, no certificate. A card gets photographed and forwarded on WhatsApp, so
     * whatever is printed on it is published, and the live answer stays behind the code.
     *
     * A worker's site falls back to their employer's: most contractors' people are all at the one
     * site, and asking somebody to set it on every worker to get a card that reads correctly is
     * how the field ends up blank.
     *
     * @return array<string,mixed>
     */
    public function cardDetails(int $companyId, string $subjectType, string $uuid): array
    {
        if ($subjectType === 'CONTRACTOR') {
            $row = DB::table('sheq_contractors as c')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'c.site_id')
                ->where('c.company_id', $companyId)->where('c.uuid', $uuid)
                ->whereNull('c.deleted_at')
                ->first(['c.name', 'c.trade', 'c.registration_number', 's.name as site_name']);

            if ($row === null) {
                throw new RuntimeException("No contractor {$uuid} in this company.");
            }

            return [
                'kind' => 'CONTRACTOR',
                'name' => $row->name,
                'trade' => $this->tradeLabel($row->trade),
                'employer' => null,
                'reference' => $row->registration_number,
                'site' => $row->site_name,
                'project' => null,
                'photo_uuid' => null,
                'issued_on' => Carbon::today()->toDateString(),
            ];
        }

        $row = DB::table('sheq_contractor_workers as w')
            ->join('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
            ->leftJoin('sheq_sites as s', 's.id', '=', DB::raw('coalesce(w.site_id, c.site_id)'))
            ->where('w.company_id', $companyId)->where('w.uuid', $uuid)
            ->whereNull('w.deleted_at')
            ->first([
                'w.id', 'w.name', 'w.trade', 'w.project_id', 'w.induction_expires',
                'c.name as employer', 's.name as site_name',
            ]);

        if ($row === null) {
            throw new RuntimeException("No contractor worker {$uuid} in this company.");
        }

        return [
            'kind' => 'CONTRACTOR_WORKER',
            'name' => $row->name,
            'trade' => $this->tradeLabel($row->trade),
            'employer' => $row->employer,
            'reference' => null,
            'site' => $row->site_name,
            'project' => $this->projectName($companyId, $row->project_id),
            'photo_uuid' => DB::table('sheq_files')
                ->where('company_id', $companyId)
                ->where('subject_type', 'CONTRACTOR_WORKER')->where('subject_id', $row->id)
                ->where('category', 'PHOTO')->whereNull('deleted_at')
                ->orderByDesc('id')->value('uuid'),
            /*
             * Printed because a card with no end date is a card nobody ever takes back. It is the
             * induction expiry, not a promise of access: the code is what answers that, and an
             * in-date card can still be refused at the boom.
             */
            'valid_until' => $row->induction_expires,
            'issued_on' => Carbon::today()->toDateString(),
        ];
    }

    /**
     * The project's name from Project Management, which is the system of record for projects.
     *
     * Absent tables rather than an absent row is the normal case here: Project Management is an
     * entitlement and an installation that never licensed it has no `pm_projects` at all, so this
     * has to answer null rather than throw on a card that simply has no project.
     */
    private function projectName(int $companyId, ?int $projectId): ?string
    {
        if ($projectId === null || ! Schema::hasTable('pm_projects')) {
            return null;
        }

        return DB::table('pm_projects')
            ->where('company_id', $companyId)->where('id', $projectId)
            ->value('name');
    }

    /** SCAFFOLDING reads as Scaffolding on something a person carries. */
    private function tradeLabel(?string $trade): ?string
    {
        return $trade === null ? null
            : ucfirst(strtolower(str_replace('_', ' ', $trade)));
    }

    public function rotate(int $companyId, string $subjectType, string $uuid, ?int $actorId = null): string
    {
        $table = $this->tableFor($subjectType);

        DB::table($table)->where('company_id', $companyId)->where('uuid', $uuid)
            ->update(['qr_token' => null, 'qr_rotated_at' => now(), 'updated_at' => now()]);

        $this->audit($companyId, $actorId, 'access_pass.rotated', $subjectType, $uuid,
            'The previous card stops resolving from now on.');

        return $subjectType === 'CONTRACTOR'
            ? $this->contractorToken($companyId, $uuid, $actorId)
            : $this->workerToken($companyId, $uuid, $actorId);
    }

    /**
     * What the scanned code resolves to, right now (CON-12, CON-13).
     *
     * Runs inside the token's own tenant: the request arrives with no company resolved — there is
     * no session — so the token is looked up across tenants once and everything after it runs
     * stamped, which is what keeps row-level security doing its job on an unauthenticated path.
     *
     * @return array{found:bool, allowed?:bool, subject_type?:string, name?:string,
     *               contractor?:string, site?:?string, activity?:?string,
     *               checks?:list<array<string,mixed>>, reasons?:list<string>, scanned_at?:string}
     */
    public function verify(string $token, ?int $siteId = null, ?string $activity = null,
        ?string $device = null, ?string $direction = null, array $context = []): array
    {
        $found = $this->resolve($token);

        if ($found === null) {
            /*
             * An unknown token is reported as unknown rather than as denied.
             *
             * They are different facts and a guard needs to tell them apart: a denial is "this
             * person may not come in", and an unknown code is "this card is not one of ours, or it
             * has been replaced". Answering "denied" to a rotated card sends somebody to argue
             * about their compliance instead of being issued a new card.
             */
            $this->recordPassScan($token, null, $siteId, null, 'UNKNOWN_PASS', [], [],
                $activity, $device, $context);

            return ['found' => false];
        }

        return $this->tenants->run($found['company_id'], function () use (
            $found, $token, $siteId, $activity, $device, $direction, $context
        ) {
            $result = $found['subject_type'] === 'CONTRACTOR'
                ? $this->verifyContractor($found, $siteId, $activity)
                : $this->verifyWorker($found, $siteId, $activity);

            $this->recordScan($found, $result, $siteId, $device, $activity, $direction);

            $this->recordPassScan($token, $found, $siteId, $result,
                $result['outcome'] ?? ($result['allowed'] ? 'ALLOWED' : 'DENIED'),
                $result['codes'] ?? [], $result['reasons'] ?? [],
                $activity, $device, $context);

            /*
             * The host's name travels with the verdict.
             *
             * The controller used to re-resolve the token to find it, which was a third lookup on
             * a public page and a second piece of code that knew how to turn a token into a
             * tenant. One of those is enough.
             */
            return $result + ['company' => DB::table('companies')
                ->where('id', $found['company_id'])->value('name')];
        });
    }

    // ── The checks ────────────────────────────────────────────────────────────────────────────

    /**
     * A contractor's pass: is this company allowed to have people here.
     *
     * @return array<string,mixed>
     */
    private function verifyContractor(array $found, ?int $siteId, ?string $activity): array
    {
        $decision = app(ComplianceDecisionService::class)
            ->forContractor($found['company_id'], (int) $found['id'], $siteId);

        /*
         * The safety file is shown first, by name, because it is the thing a host actually
         * assesses a contractor on — and the one row whose state a guard is most often asked
         * about at a boom.
         */
        $file = collect($decision['checks'])->firstWhere('key', 'SAFETY_FILE');

        $checks = [$this->check('SAFETY_FILE',
            ($file['state'] ?? null) === ComplianceDecisionService::PASS,
            $file['note'] ?? '')];

        foreach ($this->documentChecks($found['company_id'], (int) $found['id']) as $check) {
            $checks[] = $check;
        }

        if ($siteId !== null) {
            $site = collect($decision['checks'])->firstWhere('key', 'SITE_APPROVAL');

            $checks[] = $this->check('SITE_APPROVAL',
                ($site['state'] ?? null) === ComplianceDecisionService::PASS,
                $site['note'] ?? '');
        }

        return [
            'found' => true,
            'allowed' => $decision['allowed'],
            'outcome' => $decision['outcome'],
            'subject_type' => 'CONTRACTOR',
            'name' => $found['name'],
            'contractor' => $found['name'],
            'status' => $decision['outcome'],
            'codes' => $decision['codes'],
            'site' => $this->siteName($found['company_id'], $siteId),
            'activity' => $activity,
            'checks' => $checks,
            'reasons' => $decision['reasons'],
            // Reported alongside: a host who chose not to block on something still needs to know
            // it is wrong, and hiding it would make the switch mean "stop checking".
            'advisory' => array_values(array_filter(
                array_column($decision['advisories'], 'detail'))),
            'ppe' => $this->ppeFor($found['company_id'], $siteId, $activity),
            'scanned_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * A worker's pass: may this person, working for this contractor, do this work here (CON-13).
     *
     * The contractor's own standing is part of it. A compliant worker whose employer has been
     * blocked does not get in — the refusal is about the company they arrive with, and a worker
     * pass that ignored that would be a way around the contractor gate.
     *
     * @return array<string,mixed>
     */
    private function verifyWorker(array $found, ?int $siteId, ?string $activity): array
    {
        $companyId = $found['company_id'];

        $worker = DB::table('sheq_contractor_workers as w')
            ->join('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
            ->where('w.company_id', $companyId)->where('w.id', $found['id'])
            // The employer must still exist as a live record. A contractor removed from the
            // register leaves their workers' rows behind, and those cards must stop working.
            ->whereNull('c.deleted_at')
            ->first(['w.id', 'w.name', 'w.trade', 'w.is_active', 'w.inducted_on', 'w.contractor_id',
                'w.induction_expires', 'c.uuid as contractor_uuid', 'c.name as contractor_name',
                'c.is_active as contractor_active']);

        /*
         * Refused rather than crashed.
         *
         * This page is public and unauthenticated: a guard at a boom sees whatever it returns. A
         * worker whose employer has been removed from the register would otherwise reach
         * `mayWork()`, which refuses to find a deleted contractor and throws — a 500 on a phone at
         * a gate, which reads as "the system is broken" and ends with people being waved through.
         * A refusal that names the reason is the right answer and the safe one.
         */
        if ($worker === null || ! $worker->contractor_active) {
            return [
                'found' => true,
                'allowed' => false,
                'subject_type' => 'CONTRACTOR_WORKER',
                'name' => $found['name'],
                'contractor' => null,
                'status' => 'DENIED',
                'site' => $this->siteName($companyId, $siteId),
                'activity' => $activity,
                'checks' => [$this->check('CONTRACTOR', false,
                    'This worker\'s employer is no longer an active contractor here.')],
                'reasons' => [sprintf(
                    '%s is on the roster of a contractor that is no longer registered here.',
                    $found['name'],
                )],
                'advisory' => [],
                'ppe' => [],
                'scanned_at' => Carbon::now()->toDateTimeString(),
            ];
        }

        /*
         * The verdict comes from the central engine (COM-1).
         *
         * Everything between here and the return used to be this page's own implementation of
         * compliance: the employer's standing, induction from either source, competency, medical,
         * activity eligibility. The access gate had its own, and the register's status column had
         * a third. All three read the same registers, in different orders, with different ideas
         * of what a lapsed site approval meant — so a worker could be refused at the boom, show
         * compliant in the register, and scan green on this very page.
         *
         * What is still this page's job is saying it well: the named document checks CON-12 asks
         * for, the PPE list, the trade next to the name. Presentation, not judgement.
         */
        $decision = app(ComplianceDecisionService::class)
            ->forWorker($companyId, (int) $worker->id, $siteId, $activity);

        $checks = [];

        foreach ($decision['checks'] as $check) {
            /*
             * Only the checks that bear on this person. A pass is scanned at a boom on a phone,
             * and a list of thirteen rows of which eight say "not applicable" is a list nobody
             * reads to the end — which is how the one row that matters gets missed.
             */
            if ($check['state'] === ComplianceDecisionService::NOT_APPLICABLE
                && ! in_array($check['key'], self::ALWAYS_SHOWN, true)) {
                continue;
            }

            if ($check['key'] === 'DOCUMENTS') {
                /*
                 * Replaced by the named checks below rather than shown as one row. CON-12 asks
                 * for COID and insurance by name, because "documents: 2 problems" sends a guard
                 * to telephone somebody and "COID expired 14 March" does not.
                 */
                continue;
            }

            $checks[] = $this->check(
                // IDENTITY is what this page has always called the person, and a guard reads the
                // label. The engine's name for the same check is WORKER.
                $check['key'] === 'WORKER' ? 'IDENTITY' : $check['key'],
                $check['state'] === ComplianceDecisionService::PASS,
                $check['key'] === 'WORKER' && $check['state'] === ComplianceDecisionService::PASS
                    ? $worker->name.($worker->trade ? ' · '.$worker->trade : '')
                    : $check['note'],
            );
        }

        foreach ($this->documentChecks($companyId, (int) $worker->contractor_id) as $check) {
            $checks[] = $check;
        }

        $ppe = $this->ppeFor($companyId, $siteId, $activity);

        /*
         * PPE is reported, not asserted.
         *
         * What the work requires comes from the PPE-level controls on the risk assessment in
         * force, and from the activity's own list — real data, and the only authoritative
         * statement of it. Whether this particular person is wearing it is not something any
         * database knows: a contractor issues their own PPE and this system holds no issue record
         * for their people. So the pass tells the guard what to look for rather than pretending
         * to have checked it, which is the honest division of labour between a screen and
         * somebody standing at a boom.
         */
        if ($ppe !== []) {
            $checks[] = $this->check('PPE', true,
                'Required: '.implode(', ', $ppe).'. Check it on the person.');
        }

        return [
            'found' => true,
            'allowed' => $decision['allowed'],
            // Three answers, not two (COM-3). A check that could not be run is neither a pass nor
            // a refusal, and a guard handed "DENIED" for it turns away a crew over a database
            // that was briefly unreachable.
            'outcome' => $decision['outcome'],
            'subject_type' => 'CONTRACTOR_WORKER',
            'name' => $worker->name,
            'contractor' => $worker->contractor_name,
            'status' => $decision['outcome'],
            'codes' => $decision['codes'],
            'site' => $this->siteName($companyId, $siteId),
            // The catalogue's name for it where there is one, so a verdict reads "Working at
            // height" rather than echoing whatever code the reader happened to send.
            'activity' => $activity === null ? null
                : (app(ActivityService::class)->find($companyId, $activity)->name ?? $activity),
            'checks' => $checks,
            'reasons' => array_values(array_unique($decision['reasons'])),
            'advisory' => array_values(array_unique(
                array_filter(array_column($decision['advisories'], 'detail')))),
            'ppe' => $ppe,
            'scanned_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return list<array<string,mixed>> the headline document checks CON-12 shows by name */
    private function documentChecks(int $companyId, int $contractorId): array
    {
        $today = Carbon::today();

        $documents = DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->whereNull('superseded_at')
            ->where('status', ContractorDocumentEngine::ACCEPTED)
            ->get(['document_type', 'expires_on']);

        $checks = [];

        foreach (['COID' => ['COIDA_LETTER'],
            'INSURANCE' => ['PUBLIC_LIABILITY', 'PROOF_OF_INSURANCE']] as $label => $types) {
            $live = $documents->filter(fn ($d) => in_array($d->document_type, $types, true))
                ->filter(fn ($d) => $d->expires_on === null
                    || Carbon::parse($d->expires_on)->gte($today));

            $held = $live->isNotEmpty();
            $soonest = $live->filter(fn ($d) => $d->expires_on !== null)
                ->sortBy('expires_on')->first();

            $checks[] = $this->check($label, $held, match (true) {
                ! $held => 'Not on file, or lapsed.',
                $soonest !== null => 'Valid to '.$soonest->expires_on.'.',
                default => 'Valid.',
            });
        }

        return $checks;
    }

    /**
     * Has THIS site approved this contractor (CON-14)?
     *
     * The decision, not the paperwork. This used to look for an accepted document of type
     * SITE_SPECIFIC, which is evidence somebody supplied rather than a decision anybody took —
     * and worse, a document with a null `site_id` satisfied it at every site, so one upload
     * cleared the whole estate. Approval at one site is never approval at the next.
     *
     * The conditions of entry are printed on the verdict. "Escorted at all times" written down
     * where only the approver sees it is a condition nobody enforces at the boom.
     */
    private function siteApprovalCheck(int $companyId, int $contractorId, int $siteId): array
    {
        $decision = app(SiteApprovalService::class)->statusFor($companyId, $contractorId, $siteId);

        $note = match ($decision['status']) {
            SiteApprovalService::APPROVED => $decision['conditions'] === null
                ? ($decision['valid_until'] === null
                    ? 'Approved for this site.'
                    : 'Approved for this site until '.$decision['valid_until'].'.')
                : 'Approved, conditions: '.$decision['conditions'],
            SiteApprovalService::BLOCKED => 'Blocked at this site: '
                .($decision['reason'] ?: 'no reason recorded.'),
            SiteApprovalService::EXPIRED => 'Approval for this site lapsed on '
                .$decision['valid_until'].'.',
            default => 'This site has not approved this contractor.',
        };

        return $this->check('SITE_APPROVAL',
            $decision['status'] === SiteApprovalService::APPROVED, $note);
    }




    /**
     * The PPE this work requires, from the risk assessment in force.
     *
     * The PPE-level controls on the approved assessment for the site are the authoritative
     * statement of what the work needs — written by whoever assessed it, approved by whoever signs
     * it off. Reading a separate PPE list would be a second answer to the same question, and the
     * assessment is the one with the authority behind it.
     *
     * @return list<string>
     */
    public function ppeFor(int $companyId, ?int $siteId, ?string $activity): array
    {
        $assessment = $this->assessments->activeForSite($companyId, $siteId);

        if ($assessment === null) {
            return [];
        }

        return DB::table('sheq_controls as c')
            ->join('sheq_risk_lines as l', 'l.id', '=', 'c.risk_line_id')
            ->where('c.company_id', $companyId)
            ->where('l.risk_assessment_id', $assessment->id)
            ->where('c.hierarchy_level', 'PPE')
            ->whereNull('c.deleted_at')->whereNull('l.deleted_at')
            // Where an activity is named, only the lines about that work.
            ->when($activity !== null,
                fn ($q) => $q->whereRaw('upper(l.activity) = ?', [strtoupper($activity)]))
            ->distinct()
            ->pluck('c.description')
            ->filter()->values()->all();
    }

    // ── Internals ─────────────────────────────────────────────────────────────────────────────

    private function check(string $key, bool $ok, string $note): array
    {
        return ['key' => $key, 'ok' => $ok, 'note' => $note];
    }


    /**
     * Look the token up across tenants, once.
     *
     * The request has no session, so no company is resolved. The RLS policy admits an unstamped
     * connection — which is what makes this lookup possible at all — and everything after it runs
     * inside the tenant the token belongs to.
     *
     * @return array<string,mixed>|null
     */
    private function resolve(string $token): ?array
    {
        if (strlen($token) < 32) {
            // Too short to be one of ours. Rejected before it touches the database, so a scanner
            // sending rubbish cannot be used to probe.
            return null;
        }

        $contractor = DB::table('sheq_contractors')
            ->where('qr_token', $token)->whereNull('deleted_at')
            ->first(['id', 'uuid', 'company_id', 'name']);

        if ($contractor !== null) {
            return (array) $contractor + ['subject_type' => 'CONTRACTOR'];
        }

        $worker = DB::table('sheq_contractor_workers')
            ->where('qr_token', $token)->whereNull('deleted_at')
            ->first(['id', 'uuid', 'company_id', 'name']);

        return $worker === null ? null : (array) $worker + ['subject_type' => 'CONTRACTOR_WORKER'];
    }

    private function tokenFor(int $companyId, string $table, string $column, string $uuid,
        string $subjectType, ?int $actorId): string
    {
        $row = DB::table($table)->where('company_id', $companyId)->where($column, $uuid)
            ->whereNull('deleted_at')->first(['id', 'qr_token']);

        if ($row === null) {
            throw new RuntimeException("No {$subjectType} {$uuid} in this company.");
        }

        if (! empty($row->qr_token)) {
            return (string) $row->qr_token;
        }

        // 64 characters from the framework's CSPRNG. A token anybody could compute from a visible
        // identifier would not be a credential.
        $token = Str::random(64);

        DB::table($table)->where('id', $row->id)->update([
            'qr_token' => $token,
            'qr_issued_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'access_pass.issued', $subjectType, $uuid, null);

        return $token;
    }

    private function tableFor(string $subjectType): string
    {
        return match ($subjectType) {
            'CONTRACTOR' => 'sheq_contractors',
            'CONTRACTOR_WORKER' => 'sheq_contractor_workers',
            default => throw new RuntimeException("'{$subjectType}' does not carry an access pass."),
        };
    }

    private function siteName(int $companyId, ?int $siteId): ?string
    {
        return $siteId === null ? null : DB::table('sheq_sites')
            ->where('company_id', $companyId)->where('id', $siteId)->value('name');
    }

    /**
     * Every scan, in the register the access gate already writes to.
     *
     * Reused rather than a second log: "who was on site when it happened" is answered from one
     * place, and a scan at a boom is the same kind of event as a turnstile read. Recorded whether
     * it was allowed or refused — the refusals are what tell a safety manager the backlog is real.
     */
    /**
     * Write the scan, and move the register with it where the scanner said to (CON-16).
     *
     * A scan is a look-up by default. Somebody checking whether a contractor's file is in order
     * is not an arrival, and treating every read as one would put people on the roll-call who
     * are standing in an office two kilometres away.
     *
     * A scanner at a boom says so, with `direction=ENTRY` or `EXIT` on the URL it opens. Then
     * the same read is the check-in, which is what a gate with a phone and no turnstile
     * actually has — and the method is recorded as QR so an audit can tell it from a card.
     */
    /**
     * Record the scan itself (CON-23).
     *
     * Every scan, including the ones that match nobody — which were previously the only kind that
     * left no trace at all, and the only kind worth investigating. A code that matches nothing is
     * a replaced card, a card from another site, a photograph of somebody else's pass, or
     * somebody standing at a boom trying things.
     *
     * Never fails the scan. A guard holding a phone at a gate must get an answer even if the
     * audit insert cannot be written; refusing to answer because the logging failed would make
     * the logging the thing that stops people working.
     *
     * @param  array<string,mixed>|null  $found
     * @param  array<string,mixed>|null  $result
     * @param  list<string>  $codes
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $context
     */
    private function recordPassScan(string $token, ?array $found, ?int $siteId, ?array $result,
        string $outcome, array $codes, array $reasons, ?string $activity, ?string $device,
        array $context): void
    {
        try {
            /*
             * Where the code matched nobody, the company follows from the site the reader said it
             * was at — which readers always send, so in practice an unknown code at a gate still
             * reaches the host who needs to see it. Only a scan with no site AND no match is left
             * unattributed, and that is the honest answer rather than a guess.
             */
            $companyId = $found['company_id'] ?? ($siteId === null ? null
                : DB::table('sheq_sites')->where('id', $siteId)->value('company_id'));

            DB::table('sheq_pass_scans')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId === null ? null : (int) $companyId,
                // Hashed, never the token. An access log holding live credentials turns a
                // database read into a working card.
                'token_hash' => hash('sha256', trim($token)),
                'site_id' => $siteId,
                'subject_type' => $found['subject_type'] ?? null,
                'subject_id' => $found['id'] ?? null,
                'contractor_id' => $this->scanContractorId($found),
                'person_name' => $result['name'] ?? ($found['name'] ?? null),
                'outcome' => $outcome,
                'codes' => json_encode(array_values($codes)),
                'reasons' => json_encode(array_values($reasons)),
                'activity' => $activity === null ? null : strtoupper(trim($activity)),
                'device_ref' => $device,
                'scanned_by' => $context['user_id'] ?? null,
                'ip_address' => $context['ip'] ?? null,
                'scanned_at' => Timestamps::utc(Carbon::now()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** The employer behind a scanned subject, where there is one. */
    private function scanContractorId(?array $found): ?int
    {
        if ($found === null) {
            return null;
        }

        if ($found['subject_type'] === 'CONTRACTOR') {
            return (int) $found['id'];
        }

        $id = DB::table('sheq_contractor_workers')
            ->where('company_id', $found['company_id'])->where('id', $found['id'])
            ->value('contractor_id');

        return $id === null ? null : (int) $id;
    }

    private function recordScan(array $found, array $result, ?int $siteId, ?string $device,
        ?string $activity = null, ?string $direction = null): void
    {
        $direction = strtoupper(trim((string) $direction));

        $movement = in_array($direction, array_merge(
            SiteAttendanceService::IN, SiteAttendanceService::OUT), true);

        $contractorId = $found['subject_type'] === 'CONTRACTOR_WORKER'
            ? DB::table('sheq_contractor_workers')->where('company_id', $found['company_id'])
                ->where('id', $found['id'])->value('contractor_id')
            : $found['id'];

        app(SiteAttendanceService::class)->record($found['company_id'], [
            'site_id' => $siteId,
            'subject_type' => $found['subject_type'],
            'subject_id' => $found['id'],
            'contractor_id' => $contractorId === null ? null : (int) $contractorId,
            // Never the token: an access log holding the credential would turn a database read
            // into a working card.
            'identifier' => null,
            'person_name' => $result['name'] ?? null,
            'contractor_name' => $result['contractor'] ?? null,
            'direction' => $movement ? $direction : self::DIRECTION,
            'method' => 'QR',
            'activity' => $activity,
            'allowed' => (bool) ($result['allowed'] ?? false),
            'reasons' => $result['reasons'] ?? [],
            'was_monitor_only' => false,
            'device_ref' => $device,
            'occurred_at' => Timestamps::utc(Carbon::now()),
        ]);
    }

    private function audit(int $companyId, ?int $actorId, string $action, string $subjectType,
        string $uuid, ?string $reason): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => $subjectType === 'CONTRACTOR' ? 'Contractor' : 'ContractorWorker',
            'subject_id' => 0,
            'after' => json_encode(['uuid' => $uuid]),
            'reason' => $reason,
        ]);
    }
}
