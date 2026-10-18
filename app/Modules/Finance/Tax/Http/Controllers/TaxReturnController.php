<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxReturn;
use App\Modules\Finance\Tax\Services\TaxReturnService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §10/§11 Tax returns: the register of returns, one return's workspace, and its workflow. */
class TaxReturnController extends TaxController
{
    public function __construct(private readonly TaxReturnService $returns)
    {
    }

    public function index(Request $request): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $business = $this->businessFilter();
        $status = $request->query('status');

        $rows = TaxReturn::query()->with(['period:id,uuid,label', 'form:id,code,version', 'taxType:id,code'])->forBusiness($business)
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return Inertia::render('Modules/Finance/Tax/Returns', [
            'returns' => $rows->through(fn (TaxReturn $r) => $this->row($r)),
            'filters' => ['status' => $status],
            'statuses' => TaxReturn::STATUSES,
        ] + $this->businessProps($business));
    }

    public function show(TaxReturn $return): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $return->load(['lines', 'period', 'form', 'taxType']);
        $names = collect($this->people())->pluck('name', 'id');
        $user = request()->user();

        return Inertia::render('Modules/Finance/Tax/ReturnShow', [
            'taxReturn' => $this->row($return) + [
                'lines' => $return->lines->map(fn ($l) => ['field' => $l->field, 'label' => $l->label, 'kind' => $l->kind,
                    'calculated' => (string) $l->calculated, 'adjustment' => (string) $l->adjustment, 'final' => (string) $l->final,
                    'adjustment_reason' => $l->adjustment_reason, 'explanation' => $l->explanation, 'source_count' => $l->source_count]),
                'validation' => $return->validation, 'snapshot' => $return->snapshot,
                'prepared_by' => $names[$return->prepared_by] ?? null, 'prepared_at' => $return->prepared_at?->toDateTimeString(),
                'reviewed_by' => $names[$return->reviewed_by] ?? null, 'reviewed_at' => $return->reviewed_at?->toDateTimeString(),
                'review_comment' => $return->review_comment,
                'approved_by' => $names[$return->approved_by] ?? null, 'approved_at' => $return->approved_at?->toDateTimeString(),
                'approval_comment' => $return->approval_comment,
                'submission_method' => $return->submission_method, 'submission_response' => $return->submission_response,
                'submitted_at' => $return->submitted_at?->toDateTimeString(),
                'ids' => ['prepared_by' => $return->prepared_by, 'reviewed_by' => $return->reviewed_by],
                'form_label' => "{$return->form->name} (mapping v{$return->form->version})",
            ],
            'evidence' => $this->returns->evidence($return),
            'versions' => TaxReturn::query()->where('period_id', $return->period_id)->orderByDesc('version')
                ->get(['uuid', 'version', 'status', 'purpose', 'amount_payable', 'submission_status'])
                ->map(fn ($v) => $v->only(['uuid', 'version', 'status', 'purpose', 'submission_status']) + ['amount_payable' => (string) $v->amount_payable]),
            'can' => [
                'prepare' => $user->can(TaxPermissions::PREPARE),
                'review' => $user->can(TaxPermissions::REVIEW),
                'approve' => $user->can(TaxPermissions::APPROVE),
            ],
            'me' => $user->id,
        ]);
    }

    /**
     * §3.2 drill-down: the register lines a return field was built from, at the version used. For an
     * EMP201 the lines are employees; their names need tax.payroll.view (§16).
     */
    public function sources(Request $request, TaxReturn $return, string $field)
    {
        $this->allow(TaxPermissions::VIEW);
        $return->loadMissing('form');
        $showPeople = $return->form->code !== 'EMP201' || $request->user()->can(TaxPermissions::VIEW_PAYROLL);

        $rows = $return->sources()->where('field', $field)
            ->join('tax_transactions as t', 't.id', '=', 'tax_return_sources.transaction_id')
            ->orderBy('t.document_date')->limit(1000)
            ->get(['tax_return_sources.amount', 'tax_return_sources.transaction_version', 't.uuid', 't.document_date', 't.document_type',
                't.document_number', 't.party_name', 't.tax_code', 't.direction', 't.version', 't.is_current']);

        return response()->json([
            'field' => $field,
            'people_hidden' => ! $showPeople,
            'rows' => $rows->map(fn ($r) => [
                'uuid' => $r->uuid, 'date' => (string) $r->document_date, 'type' => $r->document_type, 'document' => $r->document_number,
                'party' => $showPeople ? $r->party_name : null, 'code' => $r->tax_code, 'amount' => (string) $r->amount,
                'version' => $r->transaction_version, 'changed_since' => ! $r->is_current,
            ]),
        ]);
    }

    public function prepare(TaxPeriod $period): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'return.prepare');
        try {
            $return = $this->returns->prepare($period);
        } catch (\App\Modules\Finance\Tax\Exceptions\TaxControlException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.returns.show', $return)->with('success', "Return {$return->reference} prepared.");
    }

    public function adjust(Request $request, TaxReturn $return): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'return.adjust');
        $d = $request->validate(['field' => ['required', 'string', 'max:20'], 'amount' => ['required', 'numeric'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return $this->attempt(fn () => $this->returns->adjust($return, $d['field'], (string) $d['amount'], $d['reason']), 'Field adjusted. The calculated value is kept beside it.');
    }

    public function explain(Request $request, TaxReturn $return): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'return.explain');
        $d = $request->validate(['field' => ['required', 'string', 'max:20'], 'explanation' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->attempt(fn () => $this->returns->explain($return, $d['field'], $d['explanation']), 'Explanation saved.');
    }

    public function validateReturn(TaxReturn $return): RedirectResponse
    {
        $this->allow(TaxPermissions::VIEW);
        $r = $this->returns->validate($return);

        return back()->with($r['errors'] ? 'error' : 'success', $r['errors'] ? count($r['errors']) . ' check(s) failed — see the return.' : 'All checks pass.');
    }

    public function send(TaxReturn $return): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'return.send');

        return $this->attempt(fn () => $this->returns->sendForReview($return), 'Sent for review.');
    }

    public function review(Request $request, TaxReturn $return): RedirectResponse
    {
        $this->allow(TaxPermissions::REVIEW, 'return.review');
        $d = $request->validate(['pass' => ['required', 'boolean'], 'comment' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->attempt(fn () => $this->returns->review($return, (bool) $d['pass'], $d['comment']), $d['pass'] ? 'Reviewed — waiting for approval.' : 'Sent back to the preparer.');
    }

    public function approve(Request $request, TaxReturn $return): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE, 'return.approve');
        $d = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->returns->approve($return, $d['comment'] ?? null), 'Approved. The liability and the SARS submission record are created.');
    }

    public function submission(Request $request, TaxReturn $return): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE, 'return.submission');
        $d = $request->validate([
            'outcome' => ['required', 'in:submitted,pending,unknown'], 'method' => ['required', 'in:manual_efiling,integration'],
            'reference' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:2000'], 'checked_efiling' => ['boolean'],
        ]);

        return $this->attempt(fn () => $this->returns->recordSubmission($return, $d['outcome'], $d['method'], $d['reference'] ?? null, $d['note'] ?? null,
            (bool) ($d['checked_efiling'] ?? false)), 'Submission recorded.');
    }

    public function outcome(Request $request, TaxReturn $return): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE, 'return.outcome');
        $d = $request->validate(['outcome' => ['required', 'in:accepted,rejected'], 'reference' => ['required', 'string', 'max:120'], 'response' => ['nullable', 'string', 'max:4000']]);

        return $this->attempt(fn () => $this->returns->recordOutcome($return, $d['outcome'], $d['reference'], $d['response'] ?? null), 'SARS outcome recorded.');
    }

    /** §15: evidence goes through Document Management — its type checks, malware scan and quotas apply. */
    public function evidence(Request $request, TaxReturn $return, DocumentService $documents): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'return.evidence');
        $d = $request->validate(['key' => ['required', 'string', 'max:40'], 'file' => ['required', 'file', 'max:20480'], 'title' => ['nullable', 'string', 'max:200']]);

        $stored = $documents->store($request->file('file'), [
            'title' => $d['title'] ?: "{$return->reference} — {$d['key']}",
            'description' => "Tax evidence for {$return->reference}", 'is_confidential' => true,
        ]);
        if (! $stored['document']) {
            return back()->with('error', $stored['error'] ?? 'The file could not be stored.');
        }

        return $this->attempt(fn () => $this->returns->attachEvidence($return, $d['key'], $stored['document']), 'Evidence filed in Document Management and linked to the return.');
    }

    /**
     * TAX-SUB-002: the approved return in the layout a person keys into eFiling — PDF or CSV. Only an
     * approved (or later) return: a capture sheet of a draft is a draft that looks final.
     */
    public function capture(Request $request, TaxReturn $return)
    {
        $this->allow(TaxPermissions::VIEW);
        if (! in_array($return->status, ['approved', 'submitted', 'accepted'], true)) {
            return back()->with('error', 'A capture sheet is produced from an approved return.');
        }
        $return->load(['lines', 'period', 'form', 'registration']);
        $names = collect($this->people())->pluck('name', 'id');
        app(\App\Modules\Finance\Tax\Services\TaxAudit::class)->record('return.capture_sheet', $return, ['period_id' => $return->period_id,
            'context' => ['format' => $request->query('format', 'pdf')]]);

        if ($request->query('format') === 'csv') {
            return $this->csv("{$return->reference}-capture.csv", ['Field', 'Description', 'Amount'],
                $return->lines->map(fn ($l) => [$l->field, $l->label, (string) $l->final])->all(),
                ['Return' => $return->reference, 'Form' => "{$return->form->code} v{$return->form->version}", 'Business' => $this->entityName($return->entity_id),
                    'Reference number' => $return->registration->reference, 'Period' => $return->period->label, 'Generated' => now()->toDateTimeString(),
                    'Note' => 'For capture on SARS eFiling. Not a submission.']);
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.tax.capture-sheet', [
            'return' => $return, 'form' => $return->form, 'period' => $return->period, 'registration' => $return->registration,
            'lines' => $return->lines, 'business' => $this->entityName($return->entity_id),
            'approvedBy' => $names[$return->approved_by] ?? '—', 'generatedAt' => now()->toDateTimeString(),
        ])->setPaper('a4')->download("{$return->reference}-capture.pdf");
    }

    private function row(TaxReturn $r): array
    {
        return [
            'uuid' => $r->uuid, 'reference' => $r->reference, 'form' => $r->form?->code, 'tax_type' => $r->taxType?->code,
            'period' => $r->period?->label, 'period_uuid' => $r->period?->uuid, 'entity' => $this->entityName($r->entity_id),
            'version' => $r->version, 'purpose' => $r->purpose, 'status' => $r->status, 'amount_payable' => (string) $r->amount_payable,
            'submission_status' => $r->submission_status, 'submission_reference' => $r->submission_reference,
            'payment_status' => $r->payment_status, 'due_date' => $r->due_date?->toDateString(),
        ];
    }
}
