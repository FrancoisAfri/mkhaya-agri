<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\ContractSignature;
use App\Modules\Operations\ProjectManagement\Models\ContractTemplate;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectContract;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\ProjectContractService;
use App\Modules\Operations\ProjectManagement\Support\ContractBody;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Project contracts, start to signature, without leaving this module.
 *
 * ── Two permissions, as everywhere else on this module ──────────────────────
 *
 * Seeing that a contract exists and what it says is delivery information. Its *value* is cost, and
 * is gated on `pm.costs.view` separately — a site manager can read the terms they are working to
 * without learning what the practice is paying.
 */
class ProjectContractController extends Controller
{
    public function __construct(private readonly ProjectContractService $contracts)
    {
    }

    /** The register: every contract on every project this person can see. */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $showCosts = $this->canSeeCosts($request);

        $contracts = ProjectContract::query()
            ->with(['project:id,uuid,code,name', 'member:id,display_name'])
            ->when($request->string('status')->isNotEmpty(),
                fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->string('project')->isNotEmpty(), fn ($q) => $q->whereHas(
                'project', fn ($p) => $p->where('uuid', $request->string('project')->toString())
            ))
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        return Inertia::render('Modules/ProjectManagement/Contracts/Index', [
            'contracts' => $contracts->map(fn (ProjectContract $c) => $this->row($c, $showCosts))->all(),
            'filters'   => [
                'status'  => $request->string('status')->toString() ?: null,
                'project' => $request->string('project')->toString() ?: null,
            ],
            'statuses'  => ProjectContract::STATUSES,
            'projects'  => $this->projectOptions(),
            // Who each project's contract could be *with*. Without this the draft dialog can raise
            // a contract with no counterparty — which then cannot be sent for signature at all,
            // because there is nobody to send it to.
            'members'   => $this->contractorsByProject(),
            'templates' => ContractTemplate::query()->active()->orderBy('name')->get()
                ->map(fn (ContractTemplate $t) => ['id' => $t->uuid, 'name' => $t->name])->all(),
            'costs'     => $showCosts,
            'can'       => [
                'draft'           => $request->user()?->can('create', Project::class) ?? false,
                'manageTemplates' => $request->user()?->can('manageSettings', Project::class) ?? false,
            ],
        ]);
    }

    /** One contract: the wording, its history, who has signed, and what may be done next. */
    public function show(Request $request, string $uuid): Response
    {
        $contract = ProjectContract::with(['project', 'member', 'versions.author', 'signatures'])
            ->where('uuid', $uuid)->firstOrFail();

        $userId = $request->user()?->id;

        // A party to the contract may read it even without `pm.projects.view`: the contractor is a
        // portal user, and a document you are being asked to sign is one you must be able to open.
        // Anybody else needs the ordinary project permission.
        $isParty = $contract->signatures->contains(fn (ContractSignature $s) => $s->user_id === $userId);

        if (! $isParty) {
            $this->authorize('view', $contract->project);
        }

        $showCosts = $this->canSeeCosts($request);
        $version = $contract->currentVersion();
        $mine = $this->contracts->pendingFor($contract, $userId);
        $mayManage = $request->user()?->can('update', $contract->project) ?? false;

        return Inertia::render('Modules/ProjectManagement/Contracts/Show', [
            'contract' => [
                'id'         => $contract->uuid,
                'number'     => $contract->contract_number,
                'title'      => $contract->title,
                'summary'    => $contract->summary,
                'status'     => $contract->status,
                'currency'   => $contract->currency,
                'start_date' => optional($contract->start_date)->toDateString(),
                'end_date'   => optional($contract->end_date)->toDateString(),
                'value'      => $showCosts ? Money::money($contract->contract_value) : null,
                'version'    => $contract->current_version,
                // Sanitised on the way out as well as in: the body is rendered as markup and a
                // template is not a trusted author.
                'body'       => ContractBody::sanitize($version?->body),
                'warns_on_amend' => $contract->amendmentWouldWithdrawSignatures(),
            ],
            'project' => [
                'id'   => $contract->project?->uuid,
                'code' => $contract->project?->code,
                'name' => $contract->project?->name,
            ],
            'contractor' => $contract->member ? [
                'name'  => $contract->member->display_name,
                'email' => $contract->member->email,
            ] : null,
            'versions' => $contract->versions->map(fn ($v) => [
                'id'      => $v->uuid,
                'no'      => $v->version_no,
                'note'    => $v->change_note,
                'author'  => $v->author?->name,
                'created' => optional($v->created_at)->toDateTimeString(),
                'current' => $v->version_no === $contract->current_version,
            ])->all(),
            /**
             * Signatures, grouped into the rounds they belong to.
             *
             * A signature block is a statement of where the document stands now. The earlier
             * rounds are the audit trail and they matter, but rendered as one flat list they make
             * the same person appear two or three times with contradictory answers — which is what
             * a reopened contract looked like.
             *
             * The current round is whichever envelope the newest signature belongs to.
             */
            'signatureRounds' => $contract->signatures
                ->sortByDesc('id')
                ->groupBy('envelope_uuid')
                ->values()
                ->map(fn ($round, $i) => [
                    'current' => $i === 0,
                    'signatures' => $round
                        ->sortBy('sign_order')
                        ->map(fn (ContractSignature $s) => $s->toPayload($version?->content_sha256))
                        ->values()->all(),
                ])->all(),
            // The signature this viewer is being asked for, if any. Drives the sign panel.
            // The name is carried so the field can be prefilled. We already know who is signing —
            // asking them to retype a name we are holding is how a Sign button ends up disabled
            // with no explanation.
            'mySignature' => $mine ? [
                'id'    => $mine->uuid,
                'party' => $mine->party,
                'name'  => $mine->signer_name,
            ] : null,
            // What this viewer has already done, so a contract that is "out for signature" with no
            // Sign button explains itself instead of looking broken.
            'mySigned' => $userId === null ? null : (function () use ($contract, $userId) {
                $done = $contract->signatures
                    ->firstWhere(fn (ContractSignature $s) => $s->user_id === $userId
                        && in_array($s->status, ['signed', 'declined'], true));

                return $done === null ? null : [
                    'status' => $done->status,
                    'at'     => optional($done->signed_at)->toDateTimeString(),
                    'name'   => $done->typed_name,
                ];
            })(),
            // Outside parties get the portal frame, never the business's own application shell.
            'portal' => $isParty && ! $mayManage,
            'signatories' => $mayManage ? $this->signatories() : [],
            'defaults'    => ['signatory_user_id' => $request->user()?->id],
            // The document, and the signatures this person has saved for reuse.
            'document'    => [
                'ready'     => $contract->hasDocument(),
                'signed'    => $contract->hasSignedDocument(),
                'url'       => $contract->hasDocument()
                    ? route('project-management.contracts.document', $contract->uuid)
                    : null,
                'sha256'    => $contract->signed_document_sha256 ?? $contract->document_sha256,
                'signed_at' => optional($contract->signed_document_at)->toDateTimeString(),
            ],
            'savedSignatures' => $userId === null ? [] :
                \App\Modules\Operations\ProjectManagement\Models\UserSignature::query()
                    ->where('user_id', $userId)->orderByDesc('id')->limit(10)->get()
                    ->map(fn ($sig) => $sig->toPayload())->all(),
            'costs'       => $showCosts,
            'can'         => [
                /**
                 * Who may still change the wording.
                 *
                 * The practice, at any time before signature. The other party too — negotiation
                 * has two sides — but only until *they* have signed: having put your name to it,
                 * you have agreed, and rewriting it unilaterally afterwards is not a negotiation.
                 * Once everybody has signed the contract is closed to both, and only a reopening
                 * puts it back in play.
                 */
                'amend'  => ! $contract->isClosed()
                    && ($mayManage || ($isParty && $mine !== null)),
                // A signed contract is done. The practice may still reopen it — an agreement can
                // need changing — but that is a deliberate act that tells both parties and sends
                // the document back round, never a quiet edit.
                'reopen' => $mayManage && $contract->status === 'signed',
                'submit' => $mayManage && $contract->isEditable(),
                'send'   => $mayManage && in_array($contract->status, ['approved', 'draft'], true)
                    && $contract->member !== null,
            ],
        ]);
    }

    /** Draft a contract on a project — from a template, or from nothing at all. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'project'        => ['required', 'uuid'],
            'member'         => ['nullable', 'uuid'],
            'template'       => ['nullable', 'uuid'],
            'title'          => ['nullable', 'string', 'max:255'],
            'summary'        => ['nullable', 'string', 'max:2000'],
            'body'           => ['nullable', 'string'],
            'contract_value' => ['nullable', 'numeric', 'min:0'],
            'start_date'     => ['nullable', 'date'],
            'end_date'       => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $project = Project::where('uuid', $data['project'])->firstOrFail();

        $this->authorize('update', $project);

        $member = ! empty($data['member'])
            ? ProjectMember::where('project_id', $project->id)->where('uuid', $data['member'])->first()
            : null;

        $template = ! empty($data['template'])
            ? ContractTemplate::where('uuid', $data['template'])->first()
            : null;

        $contract = $this->contracts->draft($project, $member, $data, $template);

        return redirect()
            ->route('project-management.contracts.show', $contract->uuid)
            ->with('success', __('project_management.flash.contract_drafted'));
    }

    /** Amend the wording or the terms. Writes a new version; never rewrites the last. */
    public function amend(Request $request, string $uuid): RedirectResponse
    {
        // Either side of the negotiation may revise it. Sending it out and submitting it for
        // internal review stay with the practice — those are ours to decide — but the wording
        // belongs to both parties, and a contractor who cannot propose a change has to negotiate
        // by email and hope somebody types it in.
        $contract = $this->amendable($request, $uuid);

        $data = $request->validate([
            'body'           => ['nullable', 'string'],
            'title'          => ['nullable', 'string', 'max:255'],
            'summary'        => ['nullable', 'string', 'max:2000'],
            'contract_value' => ['nullable', 'numeric', 'min:0'],
            'start_date'     => ['nullable', 'date'],
            'end_date'       => ['nullable', 'date', 'after_or_equal:start_date'],
            // Required, and not for form's sake: a version history whose entries cannot say what
            // changed is a list of timestamps.
            'change_note'    => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if (array_key_exists('contract_value', $data) && $data['contract_value'] !== null) {
            $this->authorizeCosts($request);
        }

        return $this->guard(
            fn () => $this->contracts->amend($contract, $data, $data['change_note']),
            __('project_management.flash.contract_amended'),
        );
    }

    public function submit(Request $request, string $uuid): RedirectResponse
    {
        $contract = $this->manageable($request, $uuid);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->guard(
            fn () => $this->contracts->submitForReview($contract, $data['note'] ?? null),
            __('project_management.flash.contract_submitted'),
        );
    }

    /** Send it to the parties. The contractor signs first; our signatory countersigns. */
    public function send(Request $request, string $uuid): RedirectResponse
    {
        $contract = $this->manageable($request, $uuid);

        $data = $request->validate([
            'signatory_user_id' => ['nullable', 'integer'],
            'signatory_name'    => ['nullable', 'string', 'max:150'],
            'signatory_email'   => ['nullable', 'email', 'max:255'],
        ]);

        abort_if($contract->project_member_id === null, 422, __('project_management.contracts.no_counterparty'));

        $member = ProjectMember::findOrFail($contract->project_member_id);

        abort_if(blank($member->email), 422, __('project_management.contractors.no_email'));

        // Runs through the company scope, so an id from another tenant is simply not found rather
        // than quietly countersigning our contract.
        $signatoryUser = ! empty($data['signatory_user_id'])
            ? User::query()->find((int) $data['signatory_user_id'])
            : null;

        abort_if(! empty($data['signatory_user_id']) && $signatoryUser === null, 422);

        $signatory = [
            'name'    => $signatoryUser?->name ?? $data['signatory_name'] ?? $request->user()->name,
            'email'   => $signatoryUser?->email ?? $data['signatory_email'] ?? $request->user()->email,
            'user_id' => $signatoryUser?->id,
        ];

        abort_if(blank($signatory['email']), 422, __('project_management.contracts.no_signatory_email'));

        return $this->guard(
            fn () => $this->contracts->sendForSignature($contract, $member, $signatory, $request),
            __('project_management.flash.contract_sent'),
        );
    }

    /** Sign, as one of the named parties. Available to the contractor and to us alike. */
    public function sign(Request $request, string $uuid): RedirectResponse
    {
        $signature = $this->mySignature($request, $uuid);

        $data = $request->validate([
            'typed_name' => ['required', 'string', 'max:190'],
            // The drawing, when they chose to draw one. Its shape and size are checked in the
            // service, where the rule lives next to the column that stores it.
            'drawing'    => ['nullable', 'string', 'max:1600000'],
            // The executed PDF, flattened in the browser where the signature was placed on the
            // page. Sent with the signature rather than after it: a signed document that exists
            // without its signature record, or the reverse, is a contract nobody can rely on.
            'pdf'        => ['nullable', 'string', 'max:20000000'],
        ]);

        return $this->guard(
            function () use ($signature, $data, $request) {
                $this->contracts->sign(
                    $signature,
                    $data['typed_name'],
                    $request,
                    $data['drawing'] ?? null,
                );

                if (! empty($data['pdf'])) {
                    $this->contracts->storeSignedDocument($signature->contract, $data['pdf']);
                }
            },
            __('project_management.flash.contract_signed'),
            route('project-management.contracts.show', $signature->contract->uuid),
        );
    }

    /**
     * The signing view: the PDF itself, to be signed on the page.
     *
     * Reached only by somebody with a signature pending on this contract — the same rule as
     * signing, because opening the signing surface and signing are the same act separated by a
     * few seconds.
     */
    public function signView(Request $request, string $uuid): Response
    {
        $contract = ProjectContract::with(['project', 'signatures'])->where('uuid', $uuid)->firstOrFail();

        $mine = $this->contracts->pendingFor($contract, $request->user()?->id);

        abort_if($mine === null, 403, __('project_management.contracts.not_yours_to_sign'));
        abort_unless($contract->hasDocument(), 404, __('project_management.contracts.no_document'));

        $mayManage = $request->user()?->can('update', $contract->project) ?? false;

        return Inertia::render('Modules/ProjectManagement/Contracts/Sign', [
            'contract' => [
                'id'          => $contract->uuid,
                'number'      => $contract->contract_number,
                'title'       => $contract->title,
                'documentUrl' => route('project-management.contracts.document', [
                    $contract->uuid, 'signed' => 0,
                ]),
            ],
            'project'    => $contract->project ? ['name' => $contract->project->name] : null,
            'party'      => $mine->party,
            'signerName' => $mine->signer_name,
            'savedSignatures' => \App\Modules\Operations\ProjectManagement\Models\UserSignature::query()
                ->where('user_id', $request->user()->id)
                ->orderByDesc('id')->limit(10)->get()
                ->map(fn ($sig) => $sig->toPayload())->all(),
            'portal' => ! $mayManage,
            'me'     => [
                'name' => $request->user()->name,
                'role' => $mine->party === 'contractor' ? 'contractor' : 'client',
            ],
        ]);
    }

    /**
     * The contract as a document.
     *
     * The executed copy when there is one — that is what somebody asking for "the contract" wants
     * — and otherwise the issued copy that is currently out for signature. Streamed rather than
     * handed over as a blob so the policy check above actually gates the bytes.
     */
    public function document(Request $request, string $uuid)
    {
        $contract = ProjectContract::with('signatures')->where('uuid', $uuid)->firstOrFail();

        $userId = $request->user()?->id;
        $isParty = $contract->signatures->contains(fn (ContractSignature $s) => $s->user_id === $userId);

        if (! $isParty) {
            $this->authorize('view', $contract->project);
        }

        $signed = $request->boolean('signed', true) && $contract->hasSignedDocument();
        $bytes = $signed ? $contract->signedDocumentBytes() : $contract->documentBytes();

        abort_if(blank($bytes), 404, __('project_management.contracts.no_document'));

        // Rebuilt from the contract number, which is ours, but sanitised anyway — a header is not
        // the place to trust a value because of where it came from.
        $name = \Illuminate\Support\Str::of(str_replace('/', '-', (string) $contract->contract_number))
            ->replaceMatches('/[^\w.\- ]+/u', '_')->limit(120, '')->trim()->value() ?: 'contract';

        return response($bytes, 200, [
            'Content-Type'           => 'application/pdf',
            'Content-Length'         => (string) strlen($bytes),
            'Content-Disposition'    => 'inline; filename="' . $name . ($signed ? '-signed' : '') . '.pdf"',
            // Inline is right for a contract a party is reading, but the browser must not be free
            // to decide the file is something more interesting than a PDF.
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Keep the executed copy produced by the signing view.
     *
     * The flattening happens in the browser — that is where the signature was placed on the page —
     * so what arrives is checked for actually being a PDF before it is trusted as the legal record.
     */
    public function storeSignedDocument(Request $request, string $uuid): RedirectResponse
    {
        $contract = ProjectContract::where('uuid', $uuid)->firstOrFail();

        // Only a party to it may lodge the executed copy.
        abort_if(
            $this->contracts->pendingFor($contract, $request->user()?->id) === null
                && ! ($request->user()?->can('update', $contract->project) ?? false),
            403,
        );

        $data = $request->validate(['pdf' => ['required', 'string', 'max:20000000']]);

        return $this->guard(
            fn () => $this->contracts->storeSignedDocument($contract, $data['pdf']),
            __('project_management.flash.contract_document_saved'),
        );
    }

    /** Save a signature for reuse, so nobody redraws it on every document. */
    public function storeSignature(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'  => ['nullable', 'string', 'max:100'],
            'kind'  => ['nullable', 'in:signature,initial'],
            'image' => ['required', 'string', 'max:1600000'],
        ]);

        abort_unless(
            preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', trim($data['image'])),
            422,
            __('project_management.contracts.bad_signature_image'),
        );

        $kind = $data['kind'] ?? 'signature';

        \App\Modules\Operations\ProjectManagement\Models\UserSignature::create([
            'uuid'    => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $request->user()->id,
            // Spelled out rather than built from $kind: TranslationsTest greps for literal keys,
            // and one assembled from a variable is a key no search — and no translator — finds.
            // `validate()` returns only the keys that were sent, so a nullable field that was
            // omitted is absent rather than null — indexing it directly throws.
            'name'    => ($data['name'] ?? null) ?: match ($kind) {
                'initial' => __('project_management.contracts.my_initial'),
                default   => __('project_management.contracts.my_signature'),
            },
            'kind'    => $kind,
            'image'   => trim($data['image']),
        ]);

        return back()->with('success', __('project_management.flash.signature_saved'));
    }

    public function destroySignature(Request $request, string $uuid): RedirectResponse
    {
        $signature = \App\Modules\Operations\ProjectManagement\Models\UserSignature::where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($signature->user_id === $request->user()?->id, 403);

        $signature->delete();

        return back();
    }

    /**
     * Reopen a signed contract so it can be amended.
     *
     * The practice only, and never quiet: every party who signed is told, with the reason, and the
     * contract goes back to draft to be re-issued for signature.
     */
    public function reopen(Request $request, string $uuid): RedirectResponse
    {
        $contract = $this->manageable($request, $uuid);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->guard(
            fn () => $this->contracts->reopenForAmendment($contract, $data['reason']),
            __('project_management.flash.contract_reopened'),
        );
    }

    public function decline(Request $request, string $uuid): RedirectResponse
    {
        $signature = $this->mySignature($request, $uuid);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->guard(
            fn () => $this->contracts->decline($signature, $data['reason'], $request),
            __('project_management.flash.contract_declined'),
            route('project-management.contracts.show', $signature->contract->uuid),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The signature being asked of *this* viewer on this contract.
     *
     * Addressed by contract rather than by signature id on purpose: it means a contractor cannot
     * sign in somebody else's place by passing their uuid, because the lookup is always "what is
     * pending for me".
     *
     * That lookup *is* the authorisation, and deliberately does not also ask ProjectPolicy@view.
     * The other party to a subcontract is a portal user who does not hold `pm.projects.view` — the
     * whole point of sending them a contract is that somebody with authority already decided they
     * should sign it. Requiring the project permission as well would mean the one person the
     * document is for cannot sign it.
     */
    private function mySignature(Request $request, string $uuid): ContractSignature
    {
        $contract = ProjectContract::where('uuid', $uuid)->firstOrFail();

        $signature = $this->contracts->pendingFor($contract, $request->user()?->id);

        abort_if($signature === null, 403, __('project_management.contracts.not_yours_to_sign'));

        return $signature;
    }

    /**
     * A contract this person may revise: the practice, or the other party to it.
     *
     * Deliberately wider than `manageable()`. An amendment writes a new version and withdraws the
     * signatures already given, so nothing is agreed behind anybody's back — which is what makes
     * it safe to let the counterparty do it.
     */
    private function amendable(Request $request, string $uuid): ProjectContract
    {
        $contract = ProjectContract::with('signatures')->where('uuid', $uuid)->firstOrFail();

        if ($request->user()?->can('update', $contract->project)) {
            return $contract;
        }

        // Not staff — so the only way in is as a party who has not yet signed. A signature is an
        // agreement; somebody who has given theirs does not get to rewrite the document under it.
        // The check is for a *pending* signature rather than mere membership.
        $pending = $this->contracts->pendingFor($contract, $request->user()?->id);

        abort_if($pending === null, 403, __('project_management.contracts.not_yours_to_amend'));

        return $contract;
    }

    private function manageable(Request $request, string $uuid): ProjectContract
    {
        $contract = ProjectContract::where('uuid', $uuid)->firstOrFail();

        $this->authorize('update', $contract->project);

        return $contract;
    }

    /**
     * Service rules surface as a message on the screen, not a 500.
     *
     * `$to` exists because signing is the one action that destroys its own page: once your
     * signature is in, the signing view refuses you — correctly, you have nothing pending — so
     * redirecting *back* landed people on a 403 the instant they succeeded. A failure still goes
     * back, because that page is still theirs to retry on.
     */
    private function guard(callable $action, string $success, ?string $to = null): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['contract' => $e->getMessage()]);
        }

        $redirect = $to ? redirect()->to($to) : back();

        return $redirect->with('success', $success);
    }

    /** @return array<string, mixed> */
    private function row(ProjectContract $c, bool $showCosts): array
    {
        $row = [
            'id'           => $c->uuid,
            'number'       => $c->contract_number,
            'title'        => $c->title,
            'status'       => $c->status,
            'version'      => $c->current_version,
            'project'      => $c->project?->name,
            'project_code' => $c->project?->code,
            'contractor'   => $c->member?->display_name,
            'start_date'   => optional($c->start_date)->toDateString(),
            'end_date'     => optional($c->end_date)->toDateString(),
        ];

        return $showCosts
            ? $row + ['value' => Money::money($c->contract_value), 'currency' => $c->currency]
            : $row;
    }

    /** @return list<array{id:string, label:string}> */
    private function projectOptions(): array
    {
        return Project::query()->orderBy('name')->limit(300)->get()
            ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => trim($p->code . ' — ' . $p->name)])
            ->all();
    }

    /**
     * Contractors on each project, keyed by the project's uuid.
     *
     * Sent as one map rather than fetched per project so choosing a project fills the second
     * dropdown without a round trip — a contract is usually raised in one sitting, and a spinner
     * between two selects is the sort of friction that gets a feature abandoned.
     *
     * @return array<string, list<array{id:string, label:string}>>
     */
    private function contractorsByProject(): array
    {
        return ProjectMember::query()
            ->where('role_key', 'contractor')
            ->active()
            ->with('project:id,uuid')
            ->orderBy('display_name')
            ->limit(1000)
            ->get()
            ->filter(fn (ProjectMember $m) => $m->project !== null)
            ->groupBy(fn (ProjectMember $m) => $m->project->uuid)
            ->map(fn ($members) => $members
                ->map(fn (ProjectMember $m) => ['id' => $m->uuid, 'label' => $m->display_name])
                ->values()->all())
            ->all();
    }

    /** @return list<array{id:int, label:string, email:string}> */
    private function signatories(): array
    {
        return User::query()
            ->with('person:id,user_id,first_name,surname')
            ->orderBy('email')->limit(500)->get()
            ->filter(fn (User $u) => filled($u->email))
            ->map(fn (User $u) => ['id' => $u->id, 'label' => $u->name, 'email' => $u->email])
            ->values()->all();
    }

    private function canSeeCosts(Request $request): bool
    {
        return $request->user()?->can('viewCosts', Project::class) ?? false;
    }

    private function authorizeCosts(Request $request): void
    {
        abort_unless($this->canSeeCosts($request), 403);
    }
}
