<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\ContractorComplianceStatus;
use App\Modules\Operations\Sheq\Services\ContractorDocumentEngine;
use App\Modules\Operations\Sheq\Services\ContractorPerformanceService;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\InductionService;
use App\Modules\Operations\Sheq\Services\SafetyFileExporter;
use App\Modules\Operations\Sheq\Services\SafetyFileService;
use App\Modules\Operations\Sheq\Services\SafetyFileWorkflow;
use App\Modules\Operations\Sheq\Services\SiteApprovalService;
use App\Modules\Operations\Sheq\Services\SupplierPortalBridge;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\SafetyFileSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One contractor: whether they may be on site, and what is stopping them (§5.9, SHEQ-CON-1…5).
 *
 * The register could register a contractor and nothing else. Registering one immediately read as
 * BLOCKED — correctly, because `refreshAccessStatus` blocks until the required documents are held
 * — and there was no screen on which to add a document, so the block could never be lifted. The
 * service could already produce the whole compliance file, list expiring documents, score a
 * contractor and record an override; none of it was reachable.
 *
 * The page answers the one question a gatehouse asks: may these people come in, and if not, which
 * piece of paper is missing.
 */
class ContractorController extends Controller
{
    use ResolvesCompany;

    /**
     * What a contractor's people actually do on site.
     *
     * "Trade" is the word the industry uses — a scaffolder's trade is scaffolding — and it was a
     * free-text box, so the same trade arrived as "Scaffolding", "scaffold", "SCAFFOLDING" and
     * "Scaffolder". A register you cannot filter is a list, and competency requirements are keyed
     * by trade, so a spelling variant is a worker whose tickets are never checked.
     *
     * Open-ended all the same: the field is a combobox, so a trade nobody listed can still be
     * typed. The list is the common ground, not a wall.
     */
    private const TRADES = [
        'SCAFFOLDING', 'WELDING', 'ELECTRICAL', 'PLUMBING', 'RIGGING', 'PAINTING',
        'CIVIL', 'ROOFING', 'HVAC', 'INSTRUMENTATION', 'MECHANICAL_FITTING',
        'CLEANING', 'SECURITY', 'CATERING', 'TRANSPORT', 'DEMOLITION',
        'EARTHWORKS', 'ASBESTOS_REMOVAL', 'CONFINED_SPACE', 'HEIGHT_WORK', 'OTHER',
    ];

    public function __construct(
        private readonly ContractorService $contractors,
        private readonly InductionService $inductions,
        private readonly SupplierPortalBridge $suppliers,
        private readonly ContractorPerformanceService $performance,
        private readonly SafetyFileService $safetyFile,
        private readonly SafetyFileExporter $exporter,
        private readonly SafetyFileWorkflow $workflow,
    ) {}

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);
        $contractor = $this->contractor($companyId, $uuid);
        $required = $this->requiredDocumentTypes($companyId);

        /*
         * Computed on view against the host's current demands.
         *
         * A certificate expires on a date, and what the host requires can change; a status stored
         * at registration time would say "allowed" for a contractor whose insurance lapsed last
         * week.
         */
        $file = $this->contractors->complianceFile($companyId, $uuid, $required);

        /*
         * The file behind each document.
         *
         * A compliance file that lists document names and cannot produce one of them is an index,
         * not evidence — an inspector asks to see the letter of good standing, not to be told it
         * exists.
         */
        $documentIds = DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)
            ->whereIn('uuid', collect($file['documents'])->pluck('uuid'))
            ->pluck('id', 'uuid');

        $attachments = DB::table('sheq_files')
            ->where('company_id', $companyId)->where('subject_type', 'CONTRACTOR_DOCUMENT')
            ->whereIn('subject_id', $documentIds->values())
            ->whereNull('deleted_at')
            ->get(['uuid as file_uuid', 'subject_id', 'original_name', 'size'])
            ->keyBy('subject_id');

        $file['documents'] = collect($file['documents'])->map(function (array $document) use ($documentIds, $attachments) {
            $attachment = $attachments->get($documentIds[$document['uuid']] ?? null);

            return $document + [
                'file_uuid' => $attachment->file_uuid ?? null,
                'file_name' => $attachment->original_name ?? null,
                'file_size' => $attachment->size ?? null,
            ];
        })->all();

        /*
         * The verdict, said rather than implied.
         *
         * `mayWork()` wraps the same compliance file into the one sentence a gatehouse needs — may
         * these people work, and if not, why not. Nothing called it, so the screen showed a status
         * chip and a list of blocking entries and left the reader to compose the answer. The chip
         * is also not enough on its own: `EXPIRING` is not `BLOCKED`, and a guard reading an
         * amber chip has to decide what amber means.
         */
        $verdict = $this->contractors->mayWork($companyId, $uuid, $required);

        return Inertia::render('Modules/Sheq/Contractors/Show', [
            'contractor' => $contractor,
            'compliance' => $file,
            'mayWork' => $verdict,
            'requiredTypes' => $required,
            // Filled from the same list the compliance check reads, so the dropdown and the gate
            // cannot drift into different vocabularies again.
            'documentTypes' => collect(ContractorService::documentTypes())
                ->map(fn (string $label, string $code) => ['value' => $code, 'title' => $label])
                ->values()->all(),
            'trades' => self::TRADES,
            /*
             * Where a worker can be placed, for the pickers that fill the access card.
             *
             * Projects come from Project Management because that is the system of record for
             * them; on an instance that never licensed it the list is simply empty and the field
             * does not appear, rather than SHEQ keeping a second list of projects.
             */
            'sites' => DB::table('sheq_sites')->where('company_id', $companyId)
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => ['value' => (int) $s->id, 'title' => $s->name])
                ->all(),
            'projects' => $this->placeableProjects($companyId),
            'workers' => $this->workers($companyId, (int) $contractor['id']),
            'scores' => $this->scores($companyId, (int) $contractor['id']),
            'accessEvents' => $this->accessEvents($companyId, (int) $contractor['id']),
            /*
             * The other half of this contractor's standing (CON-5).
             *
             * A contractor's documents are checked at the gate; their SHEQ assessment is what
             * procurement decided. The two sat in the same database with no way to see one from the
             * other, because nothing ever wrote the `supplier_id` that joins them. Read-only here
             * — the assessment is decided on its own screen — but a gatehouse arguing about a
             * contractor should be able to see that procurement rejected them.
             */
            'supplierLink' => $this->supplierLink($companyId, $contractor),
            /*
             * Every site with this contractor's standing at each, including the ones nobody has
             * decided (CON-14). The undecided ones are the point: a screen showing only the rows
             * that exist answers "where are they approved" and silently drops "and where has
             * nobody looked", which is the question asked before work starts somewhere new.
             */
            'siteApprovals' => app(SiteApprovalService::class)
                ->forContractor($companyId, $contractor['uuid']),
            'can' => [
                'manage' => (bool) $request->user()?->can('sheq.contractor.manage'),
            ],
        ]);
    }

    /**
     * What we know about this contractor on the commercial side, if anything.
     *
     * Never throws: Supplier Management may not be installed, and a contractor profile must not
     * stop opening because of a module the tenant does not have.
     *
     * @return array<string,mixed>
     */
    private function supplierLink(int $companyId, array $contractor): array
    {
        if (! $this->suppliers->isAvailable()) {
            return ['available' => false, 'linked' => false];
        }

        $supplierId = $contractor['supplier_id'] ?? null;

        if ($supplierId === null) {
            return ['available' => true, 'linked' => false];
        }

        return [
            'available' => true,
            'linked' => true,
            'supplier_name' => DB::table('suppliers')->where('company_id', $companyId)
                ->where('id', $supplierId)->value('company_name'),
            'assessment' => $this->suppliers->assessmentFor($companyId, (int) $supplierId),
        ];
    }

    /**
     * Tie this contractor to their Supplier Management record (CON-5).
     *
     * Matched on registration number, falling back to name — the same rule the assessment side
     * uses, so the two cannot resolve to different companies and leave the join finding nothing.
     */
    public function linkSupplier(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $result = $this->suppliers->linkContractor($this->requireCompany($request), $uuid,
            $request->user()?->id);

        // Not found is a fact to report, not a failure: the contractor simply has not been
        // onboarded on the commercial side yet, and the message says what to do about it.
        return $result['linked']
            ? back()->with('success', "Linked to {$result['supplier_name']}.")
            : back()->with('error', $result['message']);
    }

    /**
     * Which conditions refuse contractor site access (CON-10).
     *
     * A host's decision, not the system's: a mine blocks on an expired medical and an office park
     * records it and lets the consultant in. Company-wide rather than per contractor, because
     * "what we demand of people on our site" is a policy and applying it unevenly is how a
     * challenge at a boom succeeds.
     */
    public function saveBlockingRules(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*' => ['boolean'],
        ]);

        $companyId = $this->requireCompany($request);

        app(ContractorComplianceStatus::class)->saveRules($companyId, $validated['rules'],
            $request->user()?->id);

        /*
         * Every contractor's status is computed from these rules, so the one in front of the
         * person changing them moves immediately rather than at its next document upload.
         *
         * Only this one: recomputing the whole estate synchronously would make saving a checkbox
         * take as long as there are contractors. The rest move when they are next touched or when
         * the nightly pass runs, and the status is read through the same evaluator in the meantime.
         */
        $this->contractors->refreshAccessStatus($companyId, $uuid,
            $this->requiredDocumentTypes($companyId), $request->user()?->id);

        return back()->with('success', 'Saved. Contractor statuses are recomputed from these.');
    }

    /**
     * Move the safety file through its states (CON-7).
     *
     * One action rather than six endpoints: the legal moves are a property of the state machine,
     * and six routes would be six places to forget a guard. The service refuses an illegal move
     * and names the ones that are available, which the renderer turns into a message on the screen.
     */
    public function transitionSafetyFile(Request $request, string $uuid): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['submit', 'review', 'request_changes', 'approve',
                'suspend', 'reinstate'])],
            'reason' => ['required_if:action,request_changes', 'required_if:action,suspend',
                'nullable', 'string', 'min:10', 'max:2000'],
            'changes' => ['nullable', 'array', 'max:50'],
            'changes.*' => ['string', 'max:300'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date', 'after:today'],
        ]);

        $companyId = $this->requireCompany($request);
        $actor = $request->user()?->id;

        /*
         * Gated on `sheq.contractor.manage`, including the review decisions.
         *
         * Review practice distinguishes whoever assembles a file from whoever accepts it, and a
         * separate `sheq.contractor.approve` would model that better. It is not used here because
         * the permission does not exist: creating one would leave it assigned to nobody, and a
         * screen gated on an entitlement no role holds is a screen that silently does nothing.
         * Splitting it is a change to the role presets, not to this controller — and where a
         * tenant has configured an approval chain, `ApprovalService` already enforces segregation
         * of duties on the decision itself.
         */
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        switch ($validated['action']) {
            case 'submit':
                $submission = $this->workflow->submit($companyId, $uuid, $actor,
                    $validated['comment'] ?? null);

                $message = $submission->state === SafetyFileWorkflow::UNDER_REVIEW
                    ? 'Submitted, and it has gone to the approval chain you configured.'
                    : 'Submitted for review.';
                break;

            case 'review':
                $this->workflow->startReview($companyId, $uuid, $actor);
                $message = 'Picked up for review. The contractor can see somebody has it.';
                break;

            case 'request_changes':
                $this->workflow->requestChanges($companyId, $uuid, $validated['reason'],
                    $validated['changes'] ?? [], $actor);
                $message = 'Sent back with the changes you listed.';
                break;

            case 'approve':
                $submission = $this->workflow->approve($companyId, $uuid, $actor,
                    $validated['valid_until'] ?? null, $validated['comment'] ?? null);

                $message = $submission->valid_until === null
                    ? 'Approved.'
                    : 'Approved until '.$submission->valid_until.'. It falls out of approval on '
                        .'that date whether or not anybody opens this screen.';
                break;

            case 'suspend':
                $this->workflow->suspend($companyId, $uuid, $validated['reason'], $actor);
                $message = 'Suspended. Their people are refused at the gate until it is reinstated.';
                break;

            default:
                $this->workflow->reinstate($companyId, $uuid, $actor, $validated['comment'] ?? null);
                $message = 'Reinstated.';
        }

        return back()->with('success', $message);
    }

    /**
     * Accept or reject a supplied document (CON-8).
     *
     * A document that has arrived and not been checked satisfies nothing, so this is the act that
     * makes it count. Separate from filing on purpose: whoever uploads certificates and whoever
     * decides they are good enough are not always the same person, and on a site where they are
     * not, the distinction is the control.
     */
    public function decideDocument(Request $request, string $uuid, string $document): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $validated = $request->validate([
            'decision' => ['required', Rule::in([ContractorDocumentEngine::ACCEPTED,
                ContractorDocumentEngine::REJECTED])],
            // Demanded on a rejection by the service; validated here so the person is told which
            // field to fill rather than handed the refusal as a sentence.
            'reason' => ['required_if:decision,REJECTED', 'nullable', 'string', 'min:10', 'max:1000'],
        ]);

        $this->contractors->decideDocument($this->requireCompany($request), $document,
            $validated['decision'], $validated['reason'] ?? null, $request->user()?->id);

        return back()->with('success', $validated['decision'] === ContractorDocumentEngine::ACCEPTED
            ? 'Accepted. It now counts towards their compliance.'
            : 'Rejected, with the reason on the record. It no longer counts towards compliance.');
    }

    /**
     * Correct the file by setting a document aside.
     *
     * The compliance check keeps the furthest expiry per type, which is right for a renewal and
     * inescapable for a typo: a date keyed as 2030 instead of 2026 keeps winning over the correct
     * certificate attached afterwards. The row is kept — compliance evidence is never destroyed —
     * and stops counting.
     */
    public function supersedeDocument(Request $request, string $uuid, string $document): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $validated = $request->validate([
            // Required, and long enough to be a sentence: "correction" tells a reviewer nothing,
            // and this is the record of why a certificate stopped counting.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $this->contractors->supersedeDocument($this->requireCompany($request), $document,
            $validated['reason'], $request->user()?->id);

        return back()->with('success',
            'Set aside. It stays on the file as evidence and no longer counts towards compliance.');
    }

    /**
     * The contractor's safety file, section by section (CON-6).
     *
     * The thing this replaces is a lever-arch folder with dividers, carried to the gate and
     * photocopied when it expires. What makes it a folder rather than a pile of certificates is
     * that an empty divider is itself a finding, so the screen shows the sections that are empty as
     * prominently as the ones that are full — which a list of uploaded documents cannot do.
     *
     * Read by anybody who can see the contractor register. Filing into it needs
     * `sheq.contractor.manage`, like every other write on this record.
     */
    public function safetyFile(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);
        $contractor = $this->contractor($companyId, $uuid);

        return Inertia::render('Modules/Sheq/Contractors/SafetyFile', [
            'contractor' => [
                'uuid' => $contractor['uuid'],
                'name' => $contractor['name'],
                'trade' => $contractor['trade'] ?? null,
                'access_status' => $contractor['access_status'],
            ],
            'file' => $this->safetyFile->assemble($companyId, $uuid),
            /*
             * The file's own state, and every version's history (CON-7).
             *
             * A compliance percentage says whether the documents are there; this says whether a
             * competent person accepted them. A file can hold every certificate and still be
             * rubbish — the method statement describes different work, the appointment names
             * somebody who left — so the two are reported side by side rather than one standing in
             * for the other.
             */
            /*
             * What refuses entry, and exactly why (CON-9, CON-10).
             *
             * Reported beside the section view because they answer different questions: the
             * sections say what is in the file, the verdict says whether these people may work
             * today. A host who switched a rule off still sees the finding, under `advisory` —
             * switching a rule off must not mean "stop checking".
             */
            'verdict' => $this->contractors->mayWork($companyId, $uuid,
                $this->requiredDocumentTypes($companyId)),
            'blockingRules' => app(ContractorComplianceStatus::class)->rulesFor($companyId),
            /*
             * Only the rules a host may actually change. Offering a switch for one that is not
             * theirs to set — a site's own block — would be a control that silently does
             * nothing, which is worse than not offering it: somebody would turn it off and
             * believe they had.
             */
            'allRules' => ContractorComplianceStatus::CONFIGURABLE_RULES,
            /** Said beside them, because its absence from the list needs explaining. */
            'alwaysBlocks' => ['SITE_BLOCKED'],
            'workflow' => [
                'state' => $this->workflow->current($companyId, $uuid, $request->user()?->id)->state,
                'history' => $this->workflow->history($companyId, $uuid),
                'validity_months' => $this->workflow->validityMonths($companyId),
            ],
            'profiles' => collect(SafetyFileSections::profiles())
                ->map(fn (array $profile, string $code) => [
                    'code' => $code,
                    'name' => $profile['name'],
                    'describes' => $profile['describes'],
                    'required' => count($profile['required']),
                ])->values()->all(),
            'documentTypes' => collect(ContractorService::documentTypes())
                ->map(fn (string $label, string $code) => ['value' => $code, 'title' => $label])
                ->values()->all(),
            // For the site-applicability field: a contractor at four sites holds one COID letter
            // and four site approvals, and the file has to be able to say which is which.
            'sites' => DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('is_active', true)
                ->whereNull('deleted_at')->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])
                ->all(),
            /*
             * Activities the tenant has already used, as suggestions rather than a vocabulary.
             *
             * There is no activity catalogue in the module and inventing one here would be a
             * second list for somebody to keep in step with how they actually describe work. A
             * combobox offering what they have typed before converges on consistency without
             * forcing it.
             */
            'knownActivities' => DB::table('sheq_contractor_documents')
                ->where('company_id', $companyId)->whereNotNull('activities')
                ->pluck('activities')
                ->flatMap(fn ($json) => json_decode((string) $json, true) ?: [])
                ->unique()->sort()->values()->all(),
            'can' => [
                'manage' => (bool) $request->user()?->can('sheq.contractor.manage'),
                // Choosing what the file must contain is a configuration decision, not a filing
                // one: a clerk uploading certificates must not be able to stop a section being
                // demanded.
                'configure' => (bool) $request->user()?->can('sheq.configure'),
            ],
        ]);
    }

    /**
     * The whole file as one archive.
     *
     * Downloading twenty-five certificates one at a time is not an alternative to the lever-arch
     * folder; it is the same problem with extra steps. What a client's auditor asks for, and what
     * a site manager forwards to a principal contractor, is the file.
     *
     * Streamed from a temporary archive and deleted after sending — a construction safety file is
     * routinely tens of megabytes, and holding that in memory to satisfy one request is how a
     * server falls over on a Monday morning.
     */
    public function exportSafetyFile(Request $request, string $uuid): BinaryFileResponse
    {
        $companyId = $this->requireCompany($request);

        // Readable on the same footing as the file itself: a guardhouse reads this, and every
        // document in it can already be downloaded one at a time by the same person. The export
        // is audited precisely because it is the bulk act.
        $this->contractor($companyId, $uuid);

        $bundle = $this->exporter->export($companyId, $uuid, $request->user()?->id);

        return response()->download($bundle['path'], $bundle['name'], [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend();
    }

    /**
     * Adopt a shipped profile as the sections this host demands.
     *
     * Industry is chosen, never inferred. Inferring it from the regulatory pack works for mining
     * and tells you nothing about a cleaning company, and the general profile exists so that a
     * tenant in an industry nobody anticipated is not stuck.
     */
    public function applySafetyFileProfile(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            'profile' => ['required', 'string', Rule::in(array_keys(SafetyFileSections::profiles()))],
        ]);

        $sections = $this->safetyFile->applyProfile($this->requireCompany($request),
            $validated['profile'], $request->user()?->id);

        return back()->with('success',
            "Safety file set from that profile: {$sections} sections, and you can change any of them.");
    }

    /** Rename a section, or change whether the file is incomplete without it. */
    public function configureSafetyFileSection(Request $request, string $uuid, string $code): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            // Renamable because "COID" is a South African word and this runs elsewhere.
            'name' => ['sometimes', 'string', 'max:160'],
            'describes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->safetyFile->configureSection($this->requireCompany($request), $code, $validated,
            $request->user()?->id);

        return back()->with('success', 'Section updated.');
    }

    public function storeWorker(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trade' => ['nullable', 'string', 'max:120'],
            /*
             * Where this person works, because the access card prints it and a guard matching a
             * face to a register needs to know whether they belong at this boom at all.
             *
             * Scoped to the tenant in the rule and not only in the dropdown: the request is what
             * decides, and without the company clause a site id from another tenant would be
             * accepted and then printed on a card as this company's site.
             */
            'site_id' => ['nullable', 'integer',
                Rule::exists('sheq_sites', 'id')->where('company_id', $companyId),
            ],
            /*
             * Project Management is the system of record for projects, so this points at its
             * table rather than SHEQ keeping a second list that would drift from the real one.
             * Validated only where that module is installed — on an instance without it the
             * column simply stays empty rather than the form refusing a value it cannot check.
             */
            'project_id' => array_values(array_filter([
                'nullable', 'integer',
                Schema::hasTable('pm_projects')
                    ? Rule::exists('pm_projects', 'id')->where('company_id', $companyId)
                    : null,
            ])),
            'inducted_on' => ['nullable', 'date'],
            'induction_expires' => ['nullable', 'date', 'after:inducted_on'],
            // A face is how a gatehouse knows the person at the turnstile is the person on the
            // register. 5 MB covers a phone photograph without inviting a scan of a passport.
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        // Through the service rather than a bare insert here: registering somebody who will be
        // issued a site pass is a consequential change, and it now carries an audit entry naming
        // who added them.
        $worker = $this->contractors->registerWorker($companyId, $uuid, $data,
            $request->user()?->id);

        if ($request->hasFile('photo')) {
            app(\App\Modules\Operations\Sheq\Services\FileService::class)->store(
                $companyId, $request->file('photo'), [
                    'subject_type' => 'CONTRACTOR_WORKER',
                    'subject_id' => $worker['id'],
                    'category' => 'PHOTO',
                    'caption' => $data['name'],
                ], $request->user()?->id);
        }

        return back()->with('success', 'Worker added.');
    }

    /**
     * Let a named person accept the risk of an incomplete file, for a stated period.
     *
     * Blocking a contractor with work to do and no way past it is how a site ends up with people
     * signed in under somebody else's name. An override is the supported way through, and it
     * carries a reason, an expiry and the name of whoever granted it.
     */
    public function overrideAccess(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'until' => ['required', 'date', 'after:today'],
        ]);

        $this->contractors->overrideAccess($this->requireCompany($request), $uuid,
            $data['reason'], $data['until'], $request->user()?->id);

        return back()->with('success', 'Access granted for the stated period, against your name.');
    }

    /**
     * Approve or block this contractor at one site (CON-14).
     *
     * Separate from the company-wide status on purpose: approval at one site is never approval at
     * the next, and the two are decided by different people. A site manager decides their own
     * boom; head office decides the safety file.
     */
    public function decideSite(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'site_id' => ['required', 'integer',
                Rule::exists('sheq_sites', 'id')->where('company_id', $companyId),
            ],
            'status' => ['required', 'string', Rule::in(SiteApprovalService::DECISIONS)],
            // Required on a block and checked again in the service, because a block with no
            // reason is one nobody can ever lift.
            'reason' => ['nullable', 'string', 'max:2000',
                'required_if:status,'.SiteApprovalService::BLOCKED,
            ],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date', 'after:today'],
        ]);

        app(SiteApprovalService::class)->decide($companyId, $uuid, (int) $data['site_id'],
            $data, $request->user()?->id);

        return back()->with('success', $data['status'] === SiteApprovalService::BLOCKED
            ? 'Blocked at that site. They are refused there whatever their file says elsewhere.'
            : 'Approved for that site. Other sites are unaffected.');
    }

    /** Return a site to undecided, which is neither approval nor a block. */
    public function withdrawSite(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'site_id' => ['required', 'integer',
                Rule::exists('sheq_sites', 'id')->where('company_id', $companyId),
            ],
        ]);

        app(SiteApprovalService::class)
            ->withdraw($companyId, $uuid, (int) $data['site_id'], $request->user()?->id);

        return back()->with('success', 'Withdrawn. That site has no decision on record again.');
    }

    /** Re-evaluate after a document is added, so the gate reflects the file immediately. */
    public function refresh(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $companyId = $this->requireCompany($request);

        // The actor travels with it: a re-evaluation that moves the status writes an audit entry,
        // and an entry with no name against it answers nothing.
        $status = $this->contractors->refreshAccessStatus($companyId, $uuid,
            $this->requiredDocumentTypes($companyId), $request->user()?->id);

        return back()->with('success', "Site access re-evaluated: {$status}.");
    }

    /**
     * What this host demands, for display — the service reads the same setting itself, so every
     * caller gets the same answer regardless of which screen it came from.
     *
     * @return list<string>
     */
    private function requiredDocumentTypes(int $companyId): array
    {
        $stored = DB::table('sheq_settings')->where('company_id', $companyId)
            ->value('contractor_required_documents');

        return array_values(array_filter((array) (json_decode((string) $stored, true) ?: [])));
    }

    /** @return array<string,mixed> */
    private function contractor(int $companyId, string $uuid): array
    {
        $row = DB::table('sheq_contractors as c')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'c.site_id')
            ->where('c.company_id', $companyId)->where('c.uuid', $uuid)->whereNull('c.deleted_at')
            ->first(['c.*', 's.name as site_name']);

        abort_if($row === null, 404);

        return (array) $row;
    }

    /**
     * Project statuses that mean the work is over, so no new worker is placed on them.
     *
     * These are values of `ProjectManagement\Models\Project::STATUSES`, and `ProjectStatusVocabularyTest`
     * asserts every one of them is still a member of it. SHEQ cannot reference the model directly
     * — Project Management is an entitlement and its classes are absent on an instance that never
     * licensed it — so the test is what keeps the two from drifting silently.
     */
    private const FINISHED_PROJECT_STATUSES = ['completed', 'cancelled', 'archived'];

    /**
     * Open projects a worker can be placed on, from Project Management.
     *
     * Empty rather than absent where that module is not installed: the form drops the field and
     * the card prints no project, which is the honest answer — a second list of projects kept
     * here would drift from the real one within a week.
     *
     * @return list<array{value:int, title:string}>
     */
    private function placeableProjects(int $companyId): array
    {
        if (! Schema::hasTable('pm_projects')) {
            return [];
        }

        return DB::table('pm_projects')->where('company_id', $companyId)
            ->when(Schema::hasColumn('pm_projects', 'deleted_at'),
                fn ($q) => $q->whereNull('deleted_at'))
            // Finished projects stay off the picker: nobody is newly placed on work that is over,
            // and a card printed against one names a project that no longer exists on site. Draft
            // and on-hold stay, because crews mobilise before a project is formally active.
            ->when(Schema::hasColumn('pm_projects', 'status'),
                fn ($q) => $q->whereNotIn('status', self::FINISHED_PROJECT_STATUSES))
            ->orderBy('name')->limit(500)
            ->get(['id', 'name'])
            ->map(fn ($p) => ['value' => (int) $p->id, 'title' => (string) $p->name])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function workers(int $companyId, int $contractorId): array
    {
        $today = \Illuminate\Support\Carbon::today();

        /*
         * Names resolved once for the whole crew rather than per worker. A contractor with forty
         * people on a card run would otherwise be eighty extra queries to print one page.
         */
        $siteNames = DB::table('sheq_sites')->where('company_id', $companyId)
            ->pluck('name', 'id');

        $projectNames = Schema::hasTable('pm_projects')
            ? DB::table('pm_projects')->where('company_id', $companyId)->pluck('name', 'id')
            : collect();

        return DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->orderBy('name')
            ->get(['id', 'uuid', 'name', 'trade', 'site_id', 'project_id', 'inducted_on',
                'induction_expires', 'is_active'])
            ->map(function ($w) use ($companyId, $today, $siteNames, $projectNames) {
                /*
                 * Two kinds of evidence, and the gate accepts either.
                 *
                 * The register's own `inducted_on` / `induction_expires` come from the
                 * contractor's paperwork, usually by bulk import. An induction completed *in this
                 * system* is recorded in `sheq_induction_completions` instead, and the register
                 * columns stay blank for it.
                 *
                 * `AccessDecisionService` honours both — refusing somebody who holds one because
                 * they lack the other would be pedantry at a boom — but this screen only ever read
                 * the imported dates. So a worker who sat the induction here, passed it, and will
                 * be let through the turnstile was displayed on the contractor's profile as not
                 * inducted. The screen and the gate have to give the same answer, or the screen is
                 * telling a safety manager to chase paperwork that is already in order.
                 */
                $completedHere = $this->inductions->isCurrent($companyId, 'CONTRACTOR_WORKER', (int) $w->id);

                $paperworkValid = $w->inducted_on !== null
                    && ($w->induction_expires === null
                        || \Illuminate\Support\Carbon::parse($w->induction_expires)->gte($today));

                return (array) $w + [
                    // Printed on the access card, so the screen shows what the card will say.
                    'site_name' => $w->site_id === null ? null : ($siteNames[$w->site_id] ?? null),
                    'project_name' => $w->project_id === null
                        ? null : ($projectNames[$w->project_id] ?? null),
                    'photo_uuid' => DB::table('sheq_files')
                        ->where('company_id', $companyId)
                        ->where('subject_type', 'CONTRACTOR_WORKER')->where('subject_id', $w->id)
                        ->whereNull('deleted_at')
                        ->orderByDesc('id')->value('uuid'),
                    'induction_completed_here' => $completedHere,
                    // An induction that lapsed is the same as never having had one, and it is the
                    // commonest reason a worker is turned away at the gate.
                    'induction_valid' => $paperworkValid || $completedHere,
                ];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    /**
     * Twelve months of scores, from the service that computes them.
     *
     * This was a second query against `sheq_contractor_scores` while
     * `ContractorPerformanceService::history()` sat unused — two readers of the same table, which
     * is how a screen ends up showing a different figure from the report beside it.
     */
    private function scores(int $companyId, int $contractorId): array
    {
        return $this->performance->history($companyId, $contractorId);
    }

    /** Who actually came through the gate, and who was turned away. */
    private function accessEvents(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_access_events')
            ->where('company_id', $companyId)
            ->where('subject_type', 'CONTRACTOR')->where('subject_id', $contractorId)
            ->orderByDesc('occurred_at')->limit(50)
            ->get(['person_name', 'direction', 'allowed', 'reasons', 'occurred_at', 'was_monitor_only'])
            // array_merge, not `+`: the union operator keeps the LEFT value for a key that
            // exists on both sides, so this decode never replaced the raw JSON string and the
            // screen rendered the reasons as text rather than as a list.
            ->map(fn ($e) => array_merge((array) $e, [
                'reasons' => json_decode((string) $e->reasons, true) ?: [],
            ]))->all();
    }
}
