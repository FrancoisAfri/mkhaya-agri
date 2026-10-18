<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Compliance\SubcontractorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What a contractor can see and do about their own compliance (CON-18).
 *
 * There is deliberately no separate SHEQ login. Supplier Management already onboards these
 * companies, already issues them a `portal_token` and already has them signed in; a second
 * portal would mean one contractor holding two credentials for the same customer, forgetting
 * one of them, and phoning somebody. So this is a page inside the portal they already use, and
 * the token that opens it is the one they already have.
 *
 * Two rules the whole thing is built on.
 *
 * **They can see everything that is being held against them.** A contractor refused at a boom
 * and told only "your file is not in order" has no way to fix it, and the usual outcome is a
 * phone call to somebody who also cannot see the detail. Every missing, rejected and expiring
 * document is named, with the reason it was rejected in the reviewer's own words.
 *
 * **They can supply, and nothing more.** An upload arrives as SUPPLIED, never ACCEPTED. That is
 * the single most important line in this file: `ContractorService::attachDocument()` defaults to
 * ACCEPTED, because the internal path is a safety officer filing a document they have just
 * looked at. The same default reached from the portal would let a contractor clear their own
 * gate by uploading a blank page.
 */
class ContractorPortalService
{
    /** Documents expiring within this many days are worth showing as a warning. */
    private const EXPIRING_WINDOW = 30;

    /**
     * Everything the portal shows, for the contractor linked to this supplier.
     *
     * Null where the supplier has no contractor record — plenty of suppliers deliver stationery
     * and never set foot on a site, and showing them an empty safety page would invite a call
     * asking what they have done wrong.
     *
     * @return array<string,mixed>|null
     */
    public function forSupplier(int $companyId, int $supplierId): ?array
    {
        $contractor = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('supplier_id', $supplierId)
            ->whereNull('deleted_at')
            ->first(['id', 'uuid', 'name', 'trade', 'access_status', 'site_id']);

        if ($contractor === null) {
            return null;
        }

        $contractors = app(ContractorService::class);
        $file = $contractors->complianceFile($companyId, $contractor->uuid);

        $documents = $this->documents($companyId, (int) $contractor->id);
        $required = $contractors->configuredRequiredTypes($companyId, $contractor->site_id);

        return [
            'contractor' => [
                'uuid' => $contractor->uuid,
                'name' => $contractor->name,
                'trade' => $contractor->trade,
                /*
                 * The gate's own answer, in their words. "BLOCKED" with no explanation is what
                 * a contractor hears at a boom; this page exists so the next thing they see is
                 * the list of what to do about it.
                 */
                'status' => $file['status'],
            ],
            'score' => $this->score($companyId, $contractor, $file, $required, $documents),
            'missing' => $this->missing($file, $documents, $required),
            'rejected' => $documents->where('status', ContractorDocumentEngine::REJECTED)
                ->map(fn ($d) => [
                    'uuid' => $d->uuid,
                    'type' => $d->document_type,
                    'label' => $this->label($d->document_type),
                    'title' => $d->title,
                    // The reviewer's own words. "Rejected" with no reason sends the same wrong
                    // document back a second time.
                    'reason' => $d->supersede_reason ?: null,
                ])->values()->all(),
            'expiring' => $documents
                ->where('status', ContractorDocumentEngine::ACCEPTED)
                ->filter(fn ($d) => $d->expires_on !== null
                    && Carbon::parse($d->expires_on)->between(
                        Carbon::today(), Carbon::today()->addDays(self::EXPIRING_WINDOW)))
                ->map(fn ($d) => [
                    'uuid' => $d->uuid,
                    'type' => $d->document_type,
                    'label' => $this->label($d->document_type),
                    'expires_on' => $d->expires_on,
                    'days_left' => (int) Carbon::today()->diffInDays(
                        Carbon::parse($d->expires_on), false),
                ])->sortBy('days_left')->values()->all(),
            'accepted' => $documents
                ->where('status', ContractorDocumentEngine::ACCEPTED)
                ->map(fn ($d) => [
                    'uuid' => $d->uuid,
                    'type' => $d->document_type,
                    'label' => $this->label($d->document_type),
                    'expires_on' => $d->expires_on,
                ])->values()->all(),
            'awaiting_review' => $documents->where('status', ContractorDocumentEngine::SUPPLIED)
                ->map(fn ($d) => [
                    'uuid' => $d->uuid,
                    'type' => $d->document_type,
                    'label' => $this->label($d->document_type),
                    'supplied_on' => $d->created_at,
                ])->values()->all(),
            'safety_file' => $this->safetyFile($companyId, (int) $contractor->id),
            'workers' => $this->workers($companyId, (int) $contractor->id),
            'sites' => $this->sites($companyId, (int) $contractor->id),
            'actions' => $this->actions($companyId, (int) $contractor->id),
            /*
             * The vocabulary, so an upload can say what it is. Only the types this host
             * actually demands plus the ones already on file: offering all twenty-eight invites
             * a contractor to upload things nobody asked for, which then sit unreviewed.
             */
            'documentTypes' => $this->offerableTypes($required, $documents),
            'required' => $required,
            /*
             * The firms this contractor appointed, where it appointed any (CON-25).
             *
             * Through `SubcontractorService::visibleTo` rather than a query written here, because
             * that method is the permission boundary: it returns only what the host has decided
             * this principal may see about other companies, and a second road to the same data is
             * the road that forgets. Null where they are not a principal, so the portal shows the
             * section only to somebody it concerns.
             */
            'subcontractors' => app(SubcontractorService::class)
                ->children($companyId, (int) $contractor->id) === []
                ? null
                : app(SubcontractorService::class)->visibleTo($companyId, (int) $contractor->id),
        ];
    }

    /**
     * Accept a document from the contractor.
     *
     * SUPPLIED, always. The internal path defaults to ACCEPTED because a safety officer filing a
     * certificate has just looked at it; the same default here would let a contractor clear
     * their own gate with a blank page. CON-18 says they cannot approve their own documents and
     * this is where that is true or not.
     *
     * @param  array<string,mixed>  $input
     */
    public function supplyDocument(int $companyId, int $supplierId, array $input): int
    {
        $contractor = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('supplier_id', $supplierId)
            ->whereNull('deleted_at')->first(['uuid']);

        if ($contractor === null) {
            throw new RuntimeException('This supplier is not registered as a contractor here.');
        }

        return app(ContractorService::class)->attachDocument($companyId, $contractor->uuid, [
            'document_type' => strtoupper((string) $input['document_type']),
            'title' => $input['title'] ?? null,
            'reference' => $input['reference'] ?? null,
            'issuing_body' => $input['issuing_body'] ?? null,
            'issued_on' => $input['issued_on'] ?? null,
            'expires_on' => $input['expires_on'] ?? null,
            'file_path' => $input['file_path'] ?? null,
            // Never ACCEPTED from this side. The whole portal turns on this one value.
            'status' => ContractorDocumentEngine::SUPPLIED,
        ], null);
    }

    /**
     * One number, and it has to be one a contractor can argue with.
     *
     * A percentage nobody can explain is worse than none: it invites "why is it 73" and there is
     * no answer. So it is a plain count of the things this host actually requires — one point
     * per required document that is accepted and in date, one for the safety file being
     * approved, one for every worker whose induction is current — and the parts are returned
     * alongside it so the page can show the arithmetic.
     *
     * @return array<string,mixed>
     */
    private function score(int $companyId, object $contractor, array $file, array $required,
        $documents): array
    {
        $parts = [];

        foreach ($required as $type) {
            $held = $documents->first(fn ($d) => $d->document_type === $type
                && $d->status === ContractorDocumentEngine::ACCEPTED
                && ($d->expires_on === null
                    || Carbon::parse($d->expires_on)->gte(Carbon::today())));

            $parts[] = [
                'label' => $this->label($type),
                'ok' => $held !== null,
                'kind' => 'DOCUMENT',
            ];
        }

        $state = $this->safetyFile($companyId, (int) $contractor->id);

        if ($state['required']) {
            $parts[] = [
                'label' => $state['label'],
                'ok' => $state['state'] === SafetyFileWorkflow::APPROVED,
                'kind' => 'SAFETY_FILE',
            ];
        }

        $workers = $this->workers($companyId, (int) $contractor->id);

        foreach ($workers as $worker) {
            $parts[] = [
                'label' => $worker['name'],
                'ok' => $worker['inducted'],
                'kind' => 'WORKER',
            ];
        }

        $total = count($parts);
        $met = count(array_filter($parts, fn ($p) => $p['ok']));

        return [
            /*
             * A hundred per cent with nothing required is honest rather than flattering: it
             * means this host has not asked them for anything, which the page says in words
             * beside the figure.
             */
            'percent' => $total === 0 ? 100 : (int) round($met / $total * 100),
            'met' => $met,
            'total' => $total,
            'parts' => $parts,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function missing(array $file, $documents, array $required): array
    {
        $out = [];

        foreach ($required as $type) {
            $live = $documents->first(fn ($d) => $d->document_type === $type
                && $d->status === ContractorDocumentEngine::ACCEPTED
                && ($d->expires_on === null
                    || Carbon::parse($d->expires_on)->gte(Carbon::today())));

            if ($live !== null) {
                continue;
            }

            $supplied = $documents->first(fn ($d) => $d->document_type === $type
                && $d->status === ContractorDocumentEngine::SUPPLIED);

            $expired = $documents->first(fn ($d) => $d->document_type === $type
                && $d->status === ContractorDocumentEngine::ACCEPTED
                && $d->expires_on !== null
                && Carbon::parse($d->expires_on)->lt(Carbon::today()));

            /*
             * Three different things, and a contractor needs them kept apart: one they have
             * never sent, one they have sent and nobody has looked at, and one that was fine
             * and ran out. Calling all three "missing" sends them to re-upload a document that
             * is sitting in somebody's review queue.
             */
            $out[] = [
                'type' => $type,
                'label' => $this->label($type),
                'state' => match (true) {
                    $supplied !== null => 'AWAITING_REVIEW',
                    $expired !== null => 'EXPIRED',
                    default => 'NEVER_SUPPLIED',
                },
                'expired_on' => $expired->expires_on ?? null,
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function safetyFile(int $companyId, int $contractorId): array
    {
        $submission = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->orderByDesc('version')
            ->first(['state', 'valid_until', 'decision_reason', 'requested_changes',
                'submitted_at', 'reviewed_at']);

        $sections = DB::table('sheq_safety_file_sections')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('is_active', true)->count();

        $state = $submission->state ?? null;

        if ($state === SafetyFileWorkflow::APPROVED && ($submission->valid_until ?? null) !== null
            && Carbon::parse($submission->valid_until)->isBefore(Carbon::today())) {
            $state = SafetyFileWorkflow::EXPIRED;
        }

        return [
            // Not every host runs a safety file. Saying "required: no" is better than showing a
            // contractor a section that will never apply to them.
            'required' => $sections > 0,
            'state' => $state,
            'label' => 'Safety file',
            'valid_until' => $submission->valid_until ?? null,
            /*
             * Why it was refused, and what to change — in the reviewer's words. A contractor
             * told only "rejected" resubmits the same file.
             */
            'decision_reason' => $submission->decision_reason ?? null,
            'requested_changes' => isset($submission->requested_changes)
                ? (json_decode((string) $submission->requested_changes, true) ?: [])
                : [],
            'submitted_at' => $submission->submitted_at ?? null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function workers(int $companyId, int $contractorId): array
    {
        $today = Carbon::today();

        $workers = DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'trade', 'inducted_on', 'induction_expires']);

        if ($workers->isEmpty()) {
            return [];
        }

        $inductions = app(InductionService::class);

        $credentials = DB::table('sheq_contractor_worker_credentials')
            ->where('company_id', $companyId)->whereIn('worker_id', $workers->pluck('id'))
            ->whereNull('deleted_at')
            ->get(['worker_id', 'kind', 'title', 'status', 'expires_on'])
            ->groupBy('worker_id');

        return $workers->map(function ($worker) use ($companyId, $today, $inductions, $credentials) {
            // Both sources, exactly as the gate reads them: imported paperwork dates and an
            // induction sat in this system. A portal that disagreed with the boom would send
            // contractors chasing certificates that are already in order.
            $paperwork = $worker->inducted_on !== null
                && ($worker->induction_expires === null
                    || Carbon::parse($worker->induction_expires)->gte($today));

            $here = $inductions->isCurrent($companyId, 'CONTRACTOR_WORKER', (int) $worker->id);

            $theirs = $credentials[$worker->id] ?? collect();

            return [
                'name' => $worker->name,
                'trade' => $worker->trade,
                'inducted' => $paperwork || $here,
                'induction_expires' => $worker->induction_expires,
                'credentials' => $theirs->map(fn ($c) => [
                    'title' => $c->title,
                    'kind' => $c->kind,
                    'status' => $c->status,
                    'expires_on' => $c->expires_on,
                    'expired' => $c->expires_on !== null
                        && Carbon::parse($c->expires_on)->lt($today),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Where this host has decided they may work (CON-14, shown to them).
     *
     * Only the sites with a decision. Listing every site a host owns and marking most of them
     * "not decided" reads as a list of places the contractor is being refused, when in fact
     * nobody has been asked about them.
     *
     * @return list<array<string,mixed>>
     */
    private function sites(int $companyId, int $contractorId): array
    {
        $today = Carbon::today();

        return DB::table('sheq_contractor_site_approvals as a')
            ->join('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->where('a.company_id', $companyId)->where('a.contractor_id', $contractorId)
            ->whereNull('a.deleted_at')
            ->orderBy('s.name')
            ->get(['a.status', 'a.reason', 'a.conditions', 'a.valid_until', 's.name'])
            ->map(fn ($row) => [
                'site' => $row->name,
                'status' => $row->status === SiteApprovalService::APPROVED
                    && $row->valid_until !== null
                    && Carbon::parse($row->valid_until)->lt($today)
                        ? SiteApprovalService::EXPIRED : $row->status,
                // The site's own words for why it refused, because a contractor who cannot see
                // the reason cannot fix it or dispute it.
                'reason' => $row->reason,
                'conditions' => $row->conditions,
                'valid_until' => $row->valid_until,
            ])->values()->all();
    }

    /**
     * Corrective actions assigned to this contractor and still open.
     *
     * @return list<array<string,mixed>>
     */
    private function actions(int $companyId, int $contractorId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('sheq_actions')) {
            return [];
        }

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('sheq_actions');

        // The actions table predates contractor ownership; on an installation where it has no
        // contractor column there is nothing to show rather than a query that fails.
        if (! in_array('contractor_id', $columns, true)) {
            return [];
        }

        return DB::table('sheq_actions')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['COMPLETED', 'VERIFIED', 'CANCELLED', 'CLOSED'])
            ->orderBy('due_date')->limit(100)
            ->get(['uuid', 'title', 'description', 'due_date', 'status', 'priority'])
            ->map(fn ($a) => array_merge((array) $a, [
                'overdue' => $a->due_date !== null
                    && Carbon::parse($a->due_date)->lt(Carbon::today()),
            ]))->all();
    }

    /** Live documents, newest first per type. */
    private function documents(int $companyId, int $contractorId)
    {
        return DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->whereNull('superseded_at')
            ->orderByDesc('id')
            /*
             * `supersede_reason`, not a column of its own.
             *
             * `decideDocument()` writes a rejection reason into `supersede_reason`, which is
             * named for setting a document aside. One column carrying two meanings is a smell,
             * but it is existing behaviour and the portal has to read where the reason actually
             * is — reading a column that does not exist is how this page would ship blank.
             */
            ->get(['uuid', 'document_type', 'title', 'status', 'expires_on', 'issued_on',
                'supersede_reason', 'created_at']);
    }

    /**
     * The types a contractor may file against.
     *
     * What the host demands, plus whatever is already on file — not the whole vocabulary.
     * Offering all twenty-eight invites uploads nobody asked for, which then sit in a review
     * queue making it look longer than it is.
     *
     * @return list<array{value:string, title:string}>
     */
    private function offerableTypes(array $required, $documents): array
    {
        $codes = collect($required)
            ->merge($documents->pluck('document_type'))
            ->filter()->unique()->sort()->values();

        $vocabulary = ContractorService::documentTypes();

        return $codes->map(fn (string $code) => [
            'value' => $code,
            'title' => $vocabulary[$code] ?? $this->label($code),
        ])->all();
    }

    private function label(string $type): string
    {
        return ContractorService::documentTypes()[$type]
            ?? ucfirst(strtolower(str_replace('_', ' ', $type)));
    }
}
