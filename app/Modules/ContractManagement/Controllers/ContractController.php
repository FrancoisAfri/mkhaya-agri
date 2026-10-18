<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Jobs\ProcessContractAiAnalysis;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractAi;
use App\Modules\ContractManagement\Models\ContractCategory;
use App\Modules\ContractManagement\Models\ContractObligation;
use App\Modules\ContractManagement\Models\ContractRequest;
use App\Modules\ContractManagement\Models\ContractsTemplate;
use App\Modules\ContractManagement\Models\ContractType;
use App\Modules\ContractManagement\Services\ContractService;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Services\ESignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    use FileStorage;

    public function __construct(private readonly ContractService $contracts) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contract::class);

        $filters = $request->only('search', 'status', 'type', 'category', 'supplier');

        $contracts = $this->contracts->getContracts($filters)
            ->through(fn (Contract $c) => [
                'id' => $c->id, 'uuid' => $c->uuid,
                'contract_number' => $c->contract_number,
                'title' => $c->title,
                'contract_status' => $c->contract_status,
                'approval_status' => $c->approval_status,
                'contract_value' => $c->contract_value,
                'start_date' => $c->start_date?->toDateString(),
                'end_date' => $c->end_date?->toDateString(),
                'type' => $c->type?->name,
                'category' => $c->category?->name,
                'risk_score' => $c->ai?->risk_score,
            ]);

        return Inertia::render('Modules/ContractManagement/Index', [
            'contracts' => $contracts,
            'filters' => $filters,
            'types' => ContractType::where('status', true)->get(['id', 'name']),
            'categories' => ContractCategory::where('status', true)->get(['id', 'name']),
            'statuses' => $this->statuses(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Contract::class);

        return Inertia::render('Modules/ContractManagement/Create', [
            'types' => ContractType::where('status', true)->get(['id', 'name']),
            'categories' => ContractCategory::where('status', true)->get(['id', 'name']),
            'suppliers' => $this->supplierList(),
            'templates' => ContractsTemplate::where('status', true)
                ->orderBy('name')->get(['id', 'name', 'content']),
            'requests' => $this->approvedUnlinkedRequests(),
        ]);
    }

    /** Approved contract requests that have not yet been converted to a contract. */
    private function approvedUnlinkedRequests(): Collection
    {
        return ContractRequest::query()
            ->where('status', 'approved')
            ->whereNotIn('id', Contract::query()->whereNotNull('contract_request_id')->pluck('contract_request_id'))
            ->orderByDesc('id')
            ->get(['id', 'request_number', 'request_title', 'contract_value'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'label' => $r->request_number.' — '.$r->request_title,
                'request_title' => $r->request_title,
                'contract_value' => $r->contract_value,
            ]);
    }

    /** Bulk-upload screen: many contract files at once, processed in the background. */
    public function bulkUploadForm(): Response
    {
        $this->authorize('create', Contract::class);

        return Inertia::render('Modules/ContractManagement/BulkUpload', [
            'types' => ContractType::where('status', true)->get(['id', 'name']),
            'categories' => ContractCategory::where('status', true)->get(['id', 'name']),
            'suppliers' => $this->supplierList(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Contract::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'contract_request_id' => ['required', 'integer', 'exists:contract_requests,id'],
            'contract_type_id' => ['nullable', 'integer'],
            'contract_category_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'contract_value' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:3'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'periodicity' => ['nullable', 'string', 'max:50'],
            'governing_law' => ['nullable', 'string', 'max:255'],
            'jurisdiction' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'max:51200'], // up to 50MB
            'publish' => ['nullable', 'boolean'],
        ]);

        // Still stored in the database, but on the shared disk rather than in this row — that is
        // where encryption at rest and the tenant storage quota apply.
        $hasFile = $request->hasFile('file');
        $pendingBytes = null;
        if ($hasFile) {
            $blob = $this->saveFileToDatabase($request->file('file'));
            $pendingBytes = base64_decode($blob['data']);
            $data['file_name'] = $blob['original_name'];
            $data['file_mime'] = $blob['mime'];
            $data['file_size'] = $blob['size'];
        }

        $publish = (bool) ($data['publish'] ?? false);
        unset($data['publish'], $data['file']);

        $contract = $this->contracts->createContract($data, Auth::id());

        if ($pendingBytes !== null) {
            // Written after the row exists so the file is never orphaned by a failed create.
            $contract->writeBytes($pendingBytes, pathinfo((string) $data['file_name'], PATHINFO_EXTENSION));
            $contract->save();
        }

        if ($publish) {
            $this->contracts->publish($contract, Auth::id());
        }

        // Analyse the uploaded document with AI in the BACKGROUND so large files
        // never block the request; the owner is notified when it's ready.
        $message = 'Contract created.';
        if ($hasFile) {
            $this->queueAnalysis($contract);
            $message = 'Contract created. The document is being analysed in the background — you\'ll be notified when it\'s ready.';
        }

        return redirect()->route('contracts.show', $contract->id)->with('success', $message);
    }

    /**
     * Bulk upload: one draft contract per file, each analysed in the background.
     * The user is told it's processing and notified per contract when done.
     */
    public function bulkUpload(Request $request): RedirectResponse
    {
        $this->authorize('create', Contract::class);

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['file', 'max:51200'], // up to 50MB each
            'contract_type_id' => ['nullable', 'integer'],
            'contract_category_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
        ]);

        $count = 0;
        foreach ($request->file('files') as $file) {
            $blob = $this->saveFileToDatabase($file);

            $contract = $this->contracts->createContract([
                'title' => pathinfo($blob['original_name'], PATHINFO_FILENAME),
                'contract_type_id' => $data['contract_type_id'] ?? null,
                'contract_category_id' => $data['contract_category_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'file_name' => $blob['original_name'],
                'file_mime' => $blob['mime'],
                'file_size' => $blob['size'],
            ], Auth::id());

            $contract->writeBytes(base64_decode($blob['data']), pathinfo((string) $blob['original_name'], PATHINFO_EXTENSION));
            $contract->save();

            // Queued only once the bytes are on the disk — the analysis reads them.
            $this->queueAnalysis($contract);
            $count++;
        }

        return redirect()->route('contracts.index')->with(
            'success',
            "{$count} contract(s) uploaded. They are being analysed in the background — you'll be notified as each completes."
        );
    }

    /**
     * Mark the contract's analysis as in-progress and dispatch it to run AFTER the
     * response (no queue worker required; scales onto a worker if one exists).
     */
    private function queueAnalysis(Contract $contract): void
    {
        ContractAi::updateOrCreate(
            ['contract_id' => $contract->id],
            ['status' => 'processing', 'error' => null]
        );

        ProcessContractAiAnalysis::dispatch($contract->id, Auth::id())
            ->afterResponse();
    }

    /** Stream the contract document stored in the database. */
    public function download(Contract $contract): \Illuminate\Http\Response
    {
        $this->authorize('view', Contract::class);
        abort_unless($contract->hasDocument(), 404, 'No document stored for this contract.');

        return $contract->documentResponse();
    }

    public function show(Contract $contract): Response
    {
        $this->authorize('view', Contract::class);

        $contract->load([
            'type', 'category', 'milestones', 'reviews',
            'statusHistories', 'appraisals', 'ai',
            'obligations' => fn ($q) => $q->orderByRaw('due_date is null')->orderBy('due_date'),
        ]);

        // Linked e-signature envelope (if the contract has been sent for signing).
        $signature = null;
        if ($contract->esign_envelope_id) {
            $envelope = Envelope::with('signers')->find($contract->esign_envelope_id);

            if ($envelope) {
                $signature = [
                    'status' => $envelope->status?->value,
                    'uuid' => $envelope->uuid,
                    'sent_at' => $envelope->sent_at?->toDateTimeString(),
                    'signers' => $envelope->signers->map(fn ($s) => [
                        'name' => $s->name,
                        'email' => $s->email,
                        'status' => $s->status?->value,
                        'signed_at' => $s->signed_at?->toDateTimeString(),
                    ])->values(),
                ];
            }
        }

        return Inertia::render('Modules/ContractManagement/Show', [
            'contract' => [
                'id' => $contract->id,
                // Every control on this page addresses the contract, and they all key on the uuid.
                'uuid' => $contract->uuid,
                'contract_number' => $contract->contract_number,
                'title' => $contract->title,
                'description' => $contract->description,
                'contract_status' => $contract->contract_status,
                'approval_status' => $contract->approval_status,
                'payment_status' => $contract->payment_status,
                'contract_value' => $contract->contract_value,
                'currency' => $contract->currency,
                'final_contract_value' => $contract->final_contract_value,
                'periodicity' => $contract->periodicity,
                'governing_law' => $contract->governing_law,
                'jurisdiction' => $contract->jurisdiction,
                'start_date' => $contract->start_date?->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
                'type' => $contract->type?->name,
                'category' => $contract->category?->name,
                'file_path' => $contract->file_path,
                'file_name' => $contract->file_name,
                'signature_status' => $contract->signature_status,
                'our_recommendation' => $contract->our_recommendation,
                'rejection_reason' => $contract->rejection_reason,
                'milestones' => $contract->milestones->map(fn ($m) => [
                    'id' => $m->id,
                    'milestone' => $m->milestone,
                    'due_date' => $m->due_date?->toDateString(),
                    'status' => $m->status,
                ])->values(),
                'reviews' => $contract->reviews->map(fn ($r) => [
                    'id' => $r->id,
                    'reviewer_name' => $r->reviewer_name,
                    'comment' => $r->comment,
                    'status' => $r->status,
                    'feedback' => $r->feedback,
                    'complexity_rating' => $r->complexity_rating,
                ])->values(),
                'status_histories' => $contract->statusHistories->map(fn ($h) => [
                    'from_status' => $h->from_status,
                    'to_status' => $h->to_status,
                    'changed_at' => $h->changed_at?->toDateTimeString(),
                    'notes' => $h->notes,
                ])->values(),
                'obligations' => $contract->obligations->map(fn ($o) => [
                    'id' => $o->id,
                    'description' => $o->description,
                    'owner_name' => $o->owner_name,
                    'due_date' => $o->due_date?->toDateString(),
                    'status' => $o->status,
                    'source' => $o->source,
                ])->values(),
            ],
            'signature' => $signature,
            'ai' => $contract->ai ? [
                'status' => $contract->ai->status,
                'summary' => $contract->ai->summary,
                'parties' => $contract->ai->parties,
                'key_dates' => $contract->ai->key_dates,
                'obligations' => $contract->ai->obligations,
                'payment_terms' => $contract->ai->payment_terms,
                'termination' => $contract->ai->termination,
                'renewal_terms' => $contract->ai->renewal_terms,
                'risk_score' => $contract->ai->risk_score,
                'compliance_score' => $contract->ai->compliance_score,
                'complexity_score' => $contract->ai->complexity_score,
                'risk_factors' => $contract->ai->risk_factors,
                'key_points' => $contract->ai->key_points,
                'recommendations' => $contract->ai->recommendations,
                'renewal_recommendation' => $contract->ai->renewal_recommendation,
                'renewal_brief' => $contract->ai->renewal_brief,
                'analyzed_at' => $contract->ai->analyzed_at?->toDateTimeString(),
                'error' => $contract->ai->error,
            ] : null,
            'statuses' => $this->statuses(),
        ]);
    }

    /**
     * Send the contract document to one or more signers for e-signature via the
     * ESign module. The original document binary is NOT handed to ESign — that
     * module links back to the contract via related_type/related_id and produces
     * its own signed certificate. The contract document remains downloadable here.
     */
    public function sendForSignature(Request $request, Contract $contract, ESignService $esign): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        abort_unless($contract->hasDocument(), 422, 'This contract has no stored document to sign.');

        $data = $request->validate([
            'signers' => ['required', 'array', 'min:1'],
            'signers.*.name' => ['required', 'string', 'max:150'],
            'signers.*.email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $envelope = $esign->create(
                envelopeData: [
                    'title' => 'Contract: '.$contract->title,
                    'document_type' => 'contract',
                    'message' => 'Please review and sign contract '.$contract->contract_number.'.',
                    'related_type' => Contract::class,
                    'related_id' => $contract->id,
                ],
                signers: array_map(fn (array $s) => [
                    'name' => $s['name'],
                    'email' => $s['email'],
                    'role' => 'authorized_signatory',
                ], $data['signers']),
                createdBy: Auth::id(),
            );

            $esign->send($envelope, Auth::id(), $request);

            $contract->update([
                'esign_envelope_id' => $envelope->id,
                'signature_status' => 'sent',
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors([
                'signature' => 'Could not send the contract for signature: '.$e->getMessage(),
            ]);
        }

        return back()->with('success', 'Contract sent for e-signature.');
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'contract_type_id' => ['nullable', 'integer'],
            'contract_category_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'contract_value' => ['nullable', 'numeric'],
            'final_contract_value' => ['nullable', 'numeric'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'periodicity' => ['nullable', 'string', 'max:50'],
            'payment_status' => ['nullable', 'string', 'max:50'],
        ]);

        $this->contracts->updateContract($contract, $data);

        return back()->with('success', 'Contract updated.');
    }

    public function destroy(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('delete', Contract::class);

        $this->contracts->softDelete($contract, Auth::id(), $request->ip());

        return redirect()->route('contracts.index')->with('success', 'Contract deleted.');
    }

    public function trash(): Response
    {
        $this->authorize('delete', Contract::class);

        $contracts = Contract::onlyTrashed()
            ->latest('deleted_at')
            ->paginate(20)
            ->through(fn (Contract $c) => [
                'id' => $c->id, 'uuid' => $c->uuid,
                'contract_number' => $c->contract_number,
                'title' => $c->title,
                'deleted_at' => $c->deleted_at?->toDateTimeString(),
            ]);

        return Inertia::render('Modules/ContractManagement/Trash', [
            'contracts' => $contracts,
        ]);
    }

    public function restore(int $id): RedirectResponse
    {
        $this->authorize('delete', Contract::class);

        $this->contracts->restore($id);

        return back()->with('success', 'Contract restored.');
    }

    public function forceDelete(int $id): RedirectResponse
    {
        $this->authorize('delete', Contract::class);

        Contract::onlyTrashed()->where('id', $id)->forceDelete();

        return back()->with('success', 'Contract permanently deleted.');
    }

    public function publish(Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $this->contracts->publish($contract, Auth::id());

        return back()->with('success', 'Contract published.');
    }

    public function updateStatus(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'status' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->contracts->recordStatusChange($contract, $data['status'], $data['notes'] ?? null, Auth::id());

        return back()->with('success', 'Status updated.');
    }

    public function terminate(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate(['notes' => ['nullable', 'string']]);

        $this->contracts->recordStatusChange($contract, 'terminated', $data['notes'] ?? 'Contract terminated', Auth::id());

        return back()->with('success', 'Contract terminated.');
    }

    public function renew(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'end_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! empty($data['end_date'])) {
            $contract->update(['end_date' => $data['end_date']]);
        }

        $this->contracts->recordStatusChange($contract, 'renewed', $data['notes'] ?? 'Contract renewed', Auth::id());

        return back()->with('success', 'Contract renewed.');
    }

    // ─── Obligations ─────────────────────────────────────────────────────────────

    public function obligationStore(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'description' => ['required', 'string'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:open,in_progress,met,breached,waived'],
            'notes' => ['nullable', 'string'],
        ]);

        $contract->obligations()->create([
            'description' => $data['description'],
            'owner_name' => $data['owner_name'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'] ?? 'open',
            'source' => 'manual',
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Obligation added.');
    }

    public function obligationUpdate(Request $request, Contract $contract, ContractObligation $obligation): RedirectResponse
    {
        $this->authorize('update', Contract::class);
        abort_unless($obligation->contract_id === $contract->id, 404);

        $data = $request->validate([
            'description' => ['sometimes', 'required', 'string'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:open,in_progress,met,breached,waived'],
            'notes' => ['nullable', 'string'],
        ]);

        if (($data['status'] ?? null) === 'met' && ! $obligation->completed_at) {
            $data['completed_at'] = now();
        }

        $obligation->update($data);

        return back()->with('success', 'Obligation updated.');
    }

    public function obligationDestroy(Contract $contract, ContractObligation $obligation): RedirectResponse
    {
        $this->authorize('update', Contract::class);
        abort_unless($obligation->contract_id === $contract->id, 404);

        $obligation->delete();

        return back()->with('success', 'Obligation removed.');
    }

    // ─── helpers ───────────────────────────────────────────────────────────────

    private function statuses(): array
    {
        return ['draft', 'published', 'pending', 'active', 'expired', 'renewed', 'terminated', 'cancelled'];
    }

    /**
     * Suppliers belong to another (deferred) module; guard against missing table.
     */
    private function supplierList(): array
    {
        if (! Schema::hasTable('suppliers')) {
            return [];
        }

        return DB::table('suppliers')
            ->when(Schema::hasColumn('suppliers', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('id')
            ->limit(500)
            ->get(['id', Schema::hasColumn('suppliers', 'company_name') ? 'company_name as name' : 'id as name'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
            ->toArray();
    }
}
