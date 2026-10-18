<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\DocumentService;
use App\Modules\Procurement\Governance\Services\RecordService;
use App\Modules\Procurement\Governance\Support\Integration\ChainLinker;
use App\Modules\Procurement\Governance\Support\Presenters\OptionLists;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Procurement 360° record (§6) and the universal audit trail as it applies to one procurement
 * (§7).
 *
 * The register is the answer to "show me everything about PROC-2026-000145" — request, plan,
 * budget, tender, evaluation, supplier, contract, deviations and every action anyone took, in one
 * place, assembled from the modules that own each part rather than copied out of them.
 */
class RecordController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly RecordService $records,
        private readonly DocumentService $documents,
        private readonly OptionLists $options,
        private readonly Capabilities $capabilities,
        private readonly ChainLinker $chain,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $records = $companyId === null ? collect() : DB::table('procgov_records as r')
            ->leftJoin('company_units as u', 'u.id', '=', 'r.company_unit_id')
            ->leftJoin('users as o', 'o.id', '=', 'r.officer_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'o.id')
            ->where('r.company_id', $companyId)
            ->whereNull('r.deleted_at')
            ->when($request->string('stage')->toString(), fn ($q, $v) => $q->where('r.stage', $v))
            ->when($request->string('status')->toString(), fn ($q, $v) => $q->where('r.status', $v))
            ->when($request->integer('officer_id'), fn ($q, $v) => $q->where('r.officer_id', $v))
            ->when($request->string('search')->toString(), fn ($q, $v) => $q->where(function ($inner) use ($v) {
                $inner->where('r.reference', 'ilike', "%{$v}%")
                    ->orWhere('r.title', 'ilike', "%{$v}%");
            }))
            ->orderByDesc('r.opened_at')
            ->limit(300)
            ->get([
                'r.uuid', 'r.reference', 'r.title', 'r.category', 'r.method', 'r.stage', 'r.status',
                'r.estimated_value', 'r.awarded_value', 'r.currency', 'r.opened_at',
                'r.target_completion_at', 'r.closed_at', 'r.financial_year_label',
                'u.name as department',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as officer"),
            ]);

        return Inertia::render('Modules/ProcurementGovernance/Records/Index', [
            'records' => $records,
            'filters' => $request->only(['stage', 'status', 'officer_id', 'search']),
            'stages' => RecordService::STAGES,
            'officers' => $companyId ? $this->options->officers($companyId) : [],
            // Deliberately not loading linkable contracts here: the register is a list, and
            // fetching 200 contracts to render a table nobody links from is a query for nothing.
            'noCompany' => $companyId === null,
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    /** §6.1 — the whole record. */
    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->companyId($request);

        $record = DB::table('procgov_records')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('deleted_at')
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $record, 404);

        $threeSixty = $companyId ? $this->records->threeSixty($companyId, (int) $record->id) : [];

        return Inertia::render('Modules/ProcurementGovernance/Records/Show', array_merge($threeSixty, [
            'documents' => $companyId
                ? $this->documents->forSubject($companyId, 'procgov_records', (int) $record->id, $request->user())
                : [],
            'stages' => RecordService::STAGES,
            'officers' => $companyId ? $this->options->officers($companyId) : [],
            'linkableContracts' => $companyId ? $this->chain->selectableContracts($companyId) : [],
            'contractModule' => $this->capabilities->integratesWith('contracts.register'),
            'can' => [
                'edit' => (bool) $request->user()?->can('procgov.record.edit'),
                'audit' => (bool) $request->user()?->can('procgov.audit.view'),
            ],
            'context' => $this->capabilities->forResponse(),
        ]));
    }

    public function transition(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $record = $this->find($companyId, $uuid);

        $data = $request->validate([
            'stage' => ['required', Rule::in(RecordService::STAGES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->records->transition($companyId, (int) $record->id, $data['stage'], $data['note'] ?? null);

        return back()->with('success', __('procurement_governance.flash.records.moved', ['stage' => $data['stage']]));
    }

    public function assignOfficer(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $record = $this->find($companyId, $uuid);

        $data = $request->validate([
            'officer_id' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->records->assignOfficer(
            $companyId,
            (int) $record->id,
            $data['officer_id'] ?? null,
            $data['note'] ?? null,
        );

        return back()->with('success', __('procurement_governance.flash.records.officer_updated'));
    }

    /**
     * Re-discover the links into the other modules on demand. The nightly reconciliation does this
     * anyway; this is for the officer who has just had a contract signed and wants it on the record
     * now rather than tomorrow.
     */
    public function syncLinks(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $record = $this->find($companyId, $uuid);

        $written = $this->chain->sync($companyId, (int) $record->id);

        return back()->with(
            'success',
            $written === []
                ? __('procurement_governance.flash.records.no_links_found')
                : __('procurement_governance.flash.records.links_found', [
                    'count' => collect($written)->map(fn ($n, $role) => "{$n} {$role}")->implode(', '),
                ]),
        );
    }

    /** §6.1: attach a contract that was not raised through a purchase order. */
    public function linkContract(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $record = $this->find($companyId, $uuid);

        $data = $request->validate(['contract_id' => ['required', 'integer']]);

        $linked = $this->chain->linkContractManually($companyId, (int) $record->id, (int) $data['contract_id']);

        return back()->with(
            $linked ? 'success' : 'error',
            $linked
                ? 'Contract linked to this procurement.'
                : 'That contract could not be found, or Contract Management is not available.',
        );
    }

    public function uploadDocument(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $record = $this->find($companyId, $uuid);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'title' => ['nullable', 'string', 'max:255'],
            'category_code' => ['nullable', 'string', 'max:40'],
            'confidentiality' => ['nullable', Rule::in(['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'])],
            'expires_at' => ['nullable', 'date'],
        ]);

        $this->documents->store($companyId, $request->file('file'), [
            'title' => $request->input('title'),
            'category_code' => $request->input('category_code'),
            'confidentiality' => $request->input('confidentiality'),
            'expires_at' => $request->input('expires_at'),
            'link' => [
                'link_role' => 'record',
                'entity_type' => 'procgov_records',
                'entity_id' => (int) $record->id,
                'entity_reference' => $record->reference,
            ],
        ]);

        return back()->with('success', __('procurement_governance.flash.records.document_attached'));
    }

    /** §7 — the full audit trail for one procurement. */
    public function audit(Request $request, string $uuid): Response
    {
        $companyId = $this->companyId($request);
        $record = $this->find($companyId, $uuid);

        abort_unless($request->user()?->can('procgov.audit.view'), 403);

        return Inertia::render('Modules/ProcurementGovernance/Records/Audit', [
            'record' => $record,
            'events' => $companyId ? $this->records->auditTrail($companyId, (int) $record->id, 1000) : [],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    private function find(?int $companyId, string $uuid): object
    {
        $record = DB::table('procgov_records')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('deleted_at')
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $record, 404);

        return $record;
    }
}
