<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAgentCertificate;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAgentKpi;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAgentPayment;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAnnexure;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAnnexureItem;
use App\Modules\Operations\ProjectManagement\Models\InvoiceCashflow;
use App\Modules\Operations\ProjectManagement\Models\InvoiceDisbursement;
use App\Modules\Operations\ProjectManagement\Models\InvoiceFeeStage;
use App\Modules\Operations\ProjectManagement\Models\InvoiceSignature;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\AgentCertificateService;
use App\Modules\Operations\ProjectManagement\Services\InvoicePackService;
use App\Modules\Operations\ProjectManagement\Services\InvoicingService;
use App\Modules\Operations\ProjectManagement\Services\ProjectLedgerPosition;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\AnnexureCatalogue;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\FeeStageCatalogue;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Project Invoicing screens.
 *
 * Money is everywhere on these pages, so two rules run through the whole controller.
 *
 * **Cost visibility is checked once, at the top.** Every route here requires `pm.costs.view` in
 * addition to the hub's WRITE tier — an account *is* the cost position, so unlike a project page
 * there is nothing left to show somebody who may not see money.
 *
 * **Payloads are built field by field.** Never `$invoice->toArray()`. The portal's discipline
 * (PortalController) applies with more force here: these models carry the client's VAT number,
 * every rate and every previously-claimed figure, and a spread would hand all of it to any view
 * that happened to render the props.
 */
class InvoicingController extends Controller
{
    public function __construct(
        private readonly InvoicingService $invoicing,
        private readonly InvoicePackService $pack,
        private readonly AgentCertificateService $certificates,
    ) {
    }

    /** The account register, across every project the viewer can see. */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);
        $this->authorizeCosts($request);

        $filters = $request->validate([
            'filter.status'  => ['nullable', Rule::in(Invoice::STATUSES)],
            'filter.project' => ['nullable', 'uuid'],
            'filter.search'  => ['nullable', 'string', 'max:120'],
        ]);

        $status = data_get($filters, 'filter.status');
        $projectUuid = data_get($filters, 'filter.project');
        $search = data_get($filters, 'filter.search');

        $invoices = Invoice::query()
            ->with('project:id,uuid,code,name')
            ->whereIn('project_id', Project::visibleTo($request->user())->select('id'))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($projectUuid, fn ($q) => $q->whereIn(
                'project_id',
                Project::where('uuid', $projectUuid)->select('id')
            ))
            ->when($search, fn ($q) => $q->where('invoice_number', 'ilike', '%' . $search . '%'))
            ->orderByDesc('invoice_date')
            ->orderByDesc('account_number')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Modules/ProjectManagement/Invoicing/Index', [
            'invoices' => [
                'data' => collect($invoices->items())->map(fn (Invoice $i) => $this->summaryRow($i))->all(),
                'meta' => [
                    'current_page' => $invoices->currentPage(),
                    'last_page'    => $invoices->lastPage(),
                    'total'        => $invoices->total(),
                ],
            ],
            'filters'  => ['status' => $status, 'project' => $projectUuid, 'search' => $search],
            'statuses' => Invoice::STATUSES,
            'projects' => Project::visibleTo($request->user())
                ->orderBy('name')
                ->get(['uuid', 'code', 'name'])
                ->map(fn (Project $p) => ['id' => $p->uuid, 'code' => $p->code, 'name' => $p->name])
                ->all(),
            // Class-level: ProjectPolicy@update needs a Project instance, and this is only the
            // hint that decides whether the button is drawn. The post itself authorises against
            // the real project.
            'can' => ['create' => $request->user()->can('create', Project::class)],
        ]);
    }

    /** One account, with everything the pack is built from. */
    public function show(Request $request, string $uuid): Response
    {
        $invoice = $this->findInvoice($uuid);
        $this->authorize('view', $invoice->project);
        $this->authorizeCosts($request);

        $fee = $this->invoicing->feeCalculator($invoice);
        $disbursements = $this->invoicing->disbursementCalculator($invoice);

        return Inertia::render('Modules/ProjectManagement/Invoicing/Show', [
            'invoice'       => $this->detailRow($invoice),
            'feeAccount'    => $fee->toArray(),
            'disbursements' => $disbursements->toArray(),
            'annexures'     => $invoice->annexures()->with('items')->get()->map(fn (InvoiceAnnexure $a) => [
                'id'                 => $a->uuid,
                'number'             => $a->number,
                'title'              => $a->title,
                'line_type'          => $a->line_type,
                'previously_claimed' => Money::money($a->previously_claimed),
                'this_invoice'       => Money::money($a->this_invoice),
                'total'              => Money::money($a->total),
                'items'              => $a->items->map(fn (InvoiceAnnexureItem $i) => [
                    'id'                  => $i->uuid,
                    'entry_date'          => optional($i->entry_date)->toDateString(),
                    'description'         => $i->description,
                    'party'               => $i->party,
                    'post_level'          => $i->post_level,
                    'size'                => $i->size,
                    'variant'             => $i->variant,
                    'quantity'            => $i->quantity,
                    'tariff'              => $i->tariff,
                    'kilometres'          => $i->kilometres,
                    'less_kilometres'     => $i->less_kilometres,
                    'hours'               => $i->hours,
                    'less_hours'          => $i->less_hours,
                    'appointment_amount'  => $i->appointment_amount,
                    'claimed_this_period' => $i->claimed_this_period,
                    'previously_claimed'  => Money::money($i->previously_claimed),
                    'this_invoice'        => Money::money($i->this_invoice),
                    'total'               => Money::money($i->total),
                ])->all(),
            ])->all(),
            // Form 1's header, so the setup panel can be filled in rather than only read.
            'feeAccountHeader' => [
                'external_works'                 => $invoice->feeAccount?->external_works,
                'structural_works'               => $invoice->feeAccount?->structural_works,
                'other_works'                    => $invoice->feeAccount?->other_works,
                'total_value_of_works'           => $invoice->feeAccount?->total_value_of_works,
                'contingency_allowance'          => $invoice->feeAccount?->contingency_allowance,
                'construction_completed_to_date' => $invoice->feeAccount?->construction_completed_to_date,
                'payment_certificate'            => $invoice->feeAccount?->payment_certificate,
                'payment_certificate_dated'      => optional($invoice->feeAccount?->payment_certificate_dated)->toDateString(),
                'fee_percentage'                 => $invoice->feeAccount?->fee_percentage,
                'approved_fee'                   => $invoice->feeAccount?->approved_fee,
                'adjusted_fee'                   => $invoice->feeAccount?->adjusted_fee,
            ],
            'disbursementRows' => $invoice->disbursements()->where('block', 'category')->get()
                ->map(fn (InvoiceDisbursement $d) => [
                    'id'                 => $d->uuid,
                    'description'        => $d->description,
                    'reference'          => $d->reference,
                    'previously_claimed' => Money::money($d->previously_claimed),
                    'this_account'       => Money::money($d->this_account),
                ])->all(),
            'catalogue' => collect(AnnexureCatalogue::all())->map(fn (array $a, int $n) => [
                'number'    => $n,
                'title'     => $a['title'],
                'line_type' => $a['line_type'],
            ])->values()->all(),
            'signatures' => $invoice->signatures()->get()->map(fn (InvoiceSignature $s) => [
                'role'                => $s->role,
                'signer_name'         => $s->signer_name,
                'registration_number' => $s->registration_number,
                'signed_at'           => optional($s->signed_at)->toDateTimeString(),
                // False when the figures have moved since it was given.
                'matches'             => $s->matchesContent(hash('sha256', (string) $invoice->amount_now_due)),
            ])->all(),
            'documents' => collect($this->pack->available($invoice))
                ->map(fn (string $label, string $kind) => ['kind' => $kind, 'label' => $label])
                ->values()->all(),
            // Present only where the practice takes implementing-agent appointments and somebody
            // has added a certificate to this account. Absent is the normal case.
            'certificate' => $invoice->agentCertificate
                ? $this->certificates->payload($invoice->agentCertificate)
                : null,
            'can' => [
                'edit'     => $invoice->isDraft(),
                // Offering the button on an account that already has one would just be a second
                // way to reach the same certificate.
                'addCertificate' => $this->certificates->enabledForCompany() && ! $invoice->agentCertificate,
                'generate' => $invoice->isDraft() && $request->user()->can('certifyClaim', $invoice->project),
                'cancel'   => ! in_array($invoice->status, ['cancelled'], true),
                'markSent' => $invoice->status === 'generated',
                'sign'     => in_array($invoice->status, ['generated', 'sent'], true)
                    && $request->user()->can('certifyClaim', $invoice->project),
            ],
        ]);
    }

/**
     * One project's accounts, reached from the project itself.
     *
     * The register is portfolio-wide, but an account belongs to a job — so the project page needs
     * a way in that lands on that job rather than on everything. It renders the same page with the
     * project fixed, which is what a filtered register already is.
     */
    public function forProject(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);
        $this->authorizeCosts($request);

        $invoices = Invoice::query()
            ->with('project:id,uuid,code,name')
            ->where('project_id', $project->id)
            ->orderByDesc('account_number')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Modules/ProjectManagement/Invoicing/Index', [
            'invoices' => [
                'data' => collect($invoices->items())->map(fn (Invoice $i) => $this->summaryRow($i))->all(),
                'meta' => [
                    'current_page' => $invoices->currentPage(),
                    'last_page'    => $invoices->lastPage(),
                    'total'        => $invoices->total(),
                ],
            ],
            'filters'  => ['status' => null, 'project' => $project->uuid, 'search' => null],
            'statuses' => Invoice::STATUSES,
            'projects' => [['id' => $project->uuid, 'code' => $project->code, 'name' => $project->name]],
            'can'      => ['create' => $request->user()->can('update', $project)],
            // Tells the page it is scoped to one job, so it offers "back to the project" rather
            // than a project filter the reader cannot change.
            'scopedTo' => ['id' => $project->uuid, 'code' => $project->code, 'name' => $project->name],
            /*
             * What the client actually owes on this job, from the ledger.
             *
             * Distinct from anything already on this page. The account rows say what was billed;
             * this says what came back. Before invoices reached the books there was no answer to
             * give, so the question was not asked — and "sent" was doing duty for a financial
             * state it never had.
             */
            'ledger' => app(ProjectLedgerPosition::class)->forProject($project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $this->authorizeCosts($request);

        $data = $request->validate([
            'invoice_date' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end'   => ['nullable', 'date', 'after_or_equal:period_start'],
            'client_name'  => ['nullable', 'string', 'max:190'],
            'description'  => ['nullable', 'string', 'max:2000'],
            // Where to pay. Stated on the account so a later change of bank cannot restate an
            // invoice that has already been issued.
            'banking_name'           => ['nullable', 'string', 'max:120'],
            'banking_account_name'   => ['nullable', 'string', 'max:190'],
            'banking_account_number' => ['nullable', 'string', 'max:40'],
            'banking_branch_code'    => ['nullable', 'string', 'max:20'],
        ]);

        $invoice = $this->invoicing->startNextAccount($project, array_filter($data));

        return redirect()
            ->route('project-management.invoicing.show', $invoice->uuid)
            ->with('success', __('project_management.flash.invoice_opened'));
    }

    public function recalculate(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->findInvoice($uuid);
        $this->authorize('update', $invoice->project);
        $this->authorizeCosts($request);

        try {
            $this->invoicing->recalculate($invoice);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.invoice_recalculated'));
    }

    public function generate(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->findInvoice($uuid);
        // Issuing an account is the moment work becomes money a client owes, which is precisely
        // what certifyClaim already governs — the same grant as running the project, not the one
        // that lets somebody edit a task.
        $this->authorize('certifyClaim', $invoice->project);
        $this->authorizeCosts($request);

        try {
            $this->invoicing->generate($invoice);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.invoice_generated'));
    }

    public function cancel(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->findInvoice($uuid);
        $this->authorize('certifyClaim', $invoice->project);
        $this->authorizeCosts($request);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->invoicing->cancel($invoice, $data['reason']);

        return back()->with('success', __('project_management.flash.invoice_cancelled'));
    }

    /** Form 1's stage rows — the numbers a user actually moves each period. */
    public function updateStage(Request $request, string $uuid, string $stageUuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        $stage = InvoiceFeeStage::where('invoice_id', $invoice->id)->where('uuid', $stageUuid)->firstOrFail();

        $data = $request->validate([
            'label'         => ['nullable', 'string', 'max:190'],
            'apportionment' => ['nullable', 'numeric', 'between:0,1'],
            // Progress is cumulative, so it can never go backwards past what has been claimed —
            // enforced in the service; here it is simply bounded.
            'progress'      => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $stage->update(array_filter($data, fn ($v) => $v !== null));

        // Editing a stage changes what the account claims, so the figures follow immediately
        // rather than waiting for somebody to press Recalculate and wonder why nothing moved.
        $this->invoicing->recalculate($invoice);

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    public function addAnnexure(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->findInvoice($uuid);
        $this->authorize('update', $invoice->project);
        $this->authorizeCosts($request);

        $data = $request->validate([
            'number' => ['required', 'integer', Rule::in(AnnexureCatalogue::numbers())],
        ]);

        $number = (int) $data['number'];

        InvoiceAnnexure::firstOrCreate(
            ['invoice_id' => $invoice->id, 'number' => $number],
            [
                'title'          => AnnexureCatalogue::title($number),
                'line_type'      => AnnexureCatalogue::lineType($number),
                'surcharge_rate' => AnnexureCatalogue::surcharge($number),
            ],
        );

        // The Form 2 row this annexure feeds, so the schedule and the annexure cannot disagree.
        InvoiceDisbursement::firstOrCreate(
            [
                'invoice_id'  => $invoice->id,
                'block'       => 'category',
                'description' => AnnexureCatalogue::summaryDescription($number),
            ],
            ['reference' => 'Annexure ' . $number, 'sort' => $number],
        );

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    /** The client's cashflow sheet: month, amount incl VAT, and a running cumulative. */
    public function cashflow(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);
        $this->authorizeCosts($request);

        $rows = InvoiceCashflow::query()
            ->whereIn('project_id', Project::visibleTo($request->user())->select('id'))
            ->orderBy('forecast_year')
            ->orderBy('forecast_month')
            ->get();

        $cumulative = '0';
        $months = $rows->map(function (InvoiceCashflow $row) use (&$cumulative) {
            $cumulative = Money::add($cumulative, $row->amount_incl_vat);

            return [
                'id'         => $row->uuid,
                'year'       => $row->forecast_year,
                'month'      => $row->forecast_month,
                'amount'     => Money::money($row->amount_incl_vat),
                // Computed on the way through rather than trusted from the column, so a row
                // inserted out of order cannot leave a stale running total behind it.
                'cumulative' => Money::money($cumulative),
            ];
        })->all();

        return Inertia::render('Modules/ProjectManagement/Invoicing/Cashflow', [
            'months' => $months,
            'total'  => Money::money($cumulative),
        ]);
    }


/** Mark a generated invoice as sent to the client. */
    public function markSent(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->findInvoice($uuid);
        $this->authorize('certifyClaim', $invoice->project);
        $this->authorizeCosts($request);

        try {
            $this->invoicing->markSent($invoice);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.invoice_sent'));
    }

    /**
     * Sign the certification block.
     *
     * The client's Summary carries two signature areas — Consulting Engineer and Programme
     * Manager. A signature binds to the figures as they stand, so it is recorded against a hash of
     * the amount now due: if the invoice were ever to change, the signature would visibly no longer
     * describe it. Only an issued invoice can be signed, for the same reason.
     */
    public function sign(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->findInvoice($uuid);
        $this->authorize('certifyClaim', $invoice->project);
        $this->authorizeCosts($request);

        abort_unless($invoice->status === 'generated' || $invoice->status === 'sent', 422,
            'Only an issued invoice can be certified.');

        $data = $request->validate([
            'role'                => ['required', Rule::in(InvoiceSignature::ROLES)],
            'signer_name'         => ['required', 'string', 'max:190'],
            'registration_number' => ['nullable', 'string', 'max:60'],
        ]);

        InvoiceSignature::updateOrCreate(
            ['invoice_id' => $invoice->id, 'role' => $data['role']],
            $data + [
                'user_id'        => $request->user()->id,
                'signed_at'      => now(),
                'content_sha256' => hash('sha256', (string) $invoice->amount_now_due),
            ],
        );

        return back()->with('success', __('project_management.flash.invoice_signed'));
    }

    /**
     * Download one document from the pack, or the whole pack.
     *
     * A normal navigation rather than XHR, because the browser has to receive it as a download —
     * the same reason InsightsController's export is a `window.location.href`.
     */
    public function download(Request $request, string $uuid, string $kind)
    {
        $invoice = $this->findInvoice($uuid);
        $this->authorize('view', $invoice->project);
        $this->authorizeCosts($request);

        $format = $request->query('format', 'pdf');
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);
        abort_unless(array_key_exists($kind, $this->pack->available($invoice)), 404);

        $documents = $kind === 'pack'
            ? $this->pack->pack($invoice)
            : [$this->pack->document($invoice, $kind)];

        $stem = Str::slug($invoice->invoice_number . '-' . $kind);

        return $format === 'xlsx'
            ? $this->xlsx($stem, $documents)
            : $this->pdf($stem, $documents, $invoice);
    }

    /**
     * The pack as a PDF.
     *
     * Orientation follows the widest document in the set: a Form 1 with eight columns is unreadable
     * in portrait, and splitting a pack across two page setups is not something DomPDF will do.
     */
    private function pdf(string $stem, array $documents, Invoice $invoice)
    {
        $orientation = collect($documents)->contains(fn (array $d) => $d['orientation'] === 'landscape')
            ? 'landscape'
            : 'portrait';

        $html = view('project-management.invoicing.layout', [
            'title'     => $documents[0]['title'],
            'header'    => $documents[0]['header'],
            'documents' => $documents,
            'printed'   => now()->toDayDateTimeString(),
        ])->render();

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4', $orientation)
            ->download($stem . '.pdf');
    }

    /** The same figures as a workbook — one sheet per document, as the client's own file has. */
    private function xlsx(string $stem, array $documents)
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex(0);

        foreach ($documents as $index => $document) {
            [$headers, $rows] = $this->pack->asTable($document);

            $sheet = $book->createSheet($index);
            $sheet->setTitle(Str::limit(str_replace('_', ' ', $document['kind']), 28, ''));
            $sheet->fromArray([$headers], null, 'A1');
            $sheet->fromArray(array_map(fn ($r) => array_map([$this, 'safeCell'], $r), $rows), null, 'A2');
            $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
            $sheet->freezePane('A2');

            foreach (range('A', $sheet->getHighestColumn()) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        }, $stem . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Neutralise a cell a spreadsheet would execute.
     *
     * A value starting =, +, - or @ is a formula to Excel, and client names, descriptions and
     * destinations all come from users. Same guard as InsightsController::safeCell.
     */
    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

// ── Filling the account in ───────────────────────────────────────────────
    //
    // Everything below is what a person needs to actually prepare an account: the value of the
    // works, the stage rows, the annexure lines and the disbursements. Without these the module
    // could compute an account but nobody could enter one.

/**
     * The account's own details: who it is to, who it is from, and where to pay.
     *
     * These carry forward from the previous account, so on a mature project nobody types them
     * again — but the first account on a project has nothing to carry forward, and without this
     * the Tax Invoice printed with no client, no consultant and no banking block.
     */
    public function updateDetails(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        $data = $request->validate([
            'invoice_date'           => ['nullable', 'date'],
            'period_start'           => ['nullable', 'date'],
            'period_end'             => ['nullable', 'date', 'after_or_equal:period_start'],
            'account_type'           => ['nullable', 'string', 'max:80'],
            'description'            => ['nullable', 'string', 'max:2000'],

            'client_name'            => ['nullable', 'string', 'max:190'],
            'client_address'         => ['nullable', 'string', 'max:1000'],
            'client_vat_number'      => ['nullable', 'string', 'max:40'],
            'attention'              => ['nullable', 'string', 'max:190'],
            'client_project_number'  => ['nullable', 'string', 'max:60'],

            'consultant_name'        => ['nullable', 'string', 'max:190'],
            'consultant_address'     => ['nullable', 'string', 'max:1000'],
            'consultant_reference'   => ['nullable', 'string', 'max:60'],
            'consultant_vat_number'  => ['nullable', 'string', 'max:40'],
            'vendor_number'          => ['nullable', 'string', 'max:60'],
            'file_number'            => ['nullable', 'string', 'max:60'],

            'banking_name'           => ['nullable', 'string', 'max:120'],
            'banking_account_name'   => ['nullable', 'string', 'max:190'],
            'banking_account_number' => ['nullable', 'string', 'max:40'],
            'banking_branch_code'    => ['nullable', 'string', 'max:20'],
            'banking_reference'      => ['nullable', 'string', 'max:60'],

            // Both rates are stated per account so a later change of company default cannot
            // restate an invoice that has already been issued.
            'vat_rate'               => ['nullable', 'numeric', 'between:0,1'],
            'disbursement_fee_rate'  => ['nullable', 'numeric', 'between:0,1'],
        ]);

        // A cleared field is a cleared field: only keys the form actually sent are written, so
        // blanking an address stores a blank rather than being ignored as "not supplied".
        $invoice->update(array_intersect_key($data, $request->all()));

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    /** Form 1's header: the value of the works and the fee derived from it. */
    public function updateFeeAccount(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        $data = $request->validate([
            'external_works'                 => ['nullable', 'numeric', 'min:0'],
            'structural_works'               => ['nullable', 'numeric', 'min:0'],
            'other_works'                    => ['nullable', 'numeric', 'min:0'],
            'contingency_allowance'          => ['nullable', 'numeric', 'min:0'],
            'construction_completed_to_date' => ['nullable', 'numeric', 'min:0'],
            'payment_certificate'            => ['nullable', 'string', 'max:60'],
            'payment_certificate_dated'      => ['nullable', 'date'],
            'fee_percentage'                 => ['nullable', 'numeric', 'between:0,1'],
            'approved_fee'                   => ['nullable', 'numeric', 'min:0'],
            'adjusted_fee'                   => ['nullable', 'numeric', 'min:0'],
        ]);

        $header = $invoice->feeAccount;
        $header->fill(array_filter($data, fn ($v) => $v !== null));

        // The form shows the split and the total; the total is the sum, not a fourth thing to type
        // and get wrong.
        $header->total_value_of_works = Money::money(Money::sum([
            $header->external_works, $header->structural_works, $header->other_works,
        ]));

        $header->save();

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    /** Add a stage row to Form 1. */
    public function storeStage(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        $data = $request->validate([
            'code'          => ['required', 'string', 'max:10'],
            'label'         => ['required', 'string', 'max:190'],
            'apportionment' => ['nullable', 'numeric', 'between:0,1'],
            'progress'      => ['nullable', 'numeric', 'between:0,1'],
            // A fixed row takes its value from elsewhere — 3.1 Time basis, fed by Annexure 11.
            'fixed_value_to_date' => ['nullable', 'numeric', 'min:0'],
        ]);

        InvoiceFeeStage::updateOrCreate(
            ['invoice_id' => $invoice->id, 'code' => $data['code']],
            $data + ['sort' => (int) (InvoiceFeeStage::where('invoice_id', $invoice->id)->max('sort') + 1)],
        );

        $this->invoicing->snapshotPreviousClaims($invoice);

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    public function destroyStage(Request $request, string $uuid, string $stageUuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        InvoiceFeeStage::where('invoice_id', $invoice->id)->where('uuid', $stageUuid)->firstOrFail()->delete();

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    /**
     * Fill Form 1 with the client's standard stage structure.
     *
     * 3.1 to 3.9, gaps included — there is no 3.7. Typing eight rows by hand on every account is
     * how a stage ends up missing from one of them.
     */
    public function seedStages(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        foreach (FeeStageCatalogue::all() as $sort => $stage) {
            InvoiceFeeStage::firstOrCreate(
                ['invoice_id' => $invoice->id, 'code' => $stage['code']],
                [
                    'label'               => $stage['label'],
                    'sort'                => $sort,
                    'apportionment'       => $stage['apportionment'],
                    'progress'            => 0,
                    'fixed_value_to_date' => $stage['fixed'] ? 0 : null,
                ],
            );
        }

        $this->invoicing->snapshotPreviousClaims($invoice);

        return back()->with('success', __('project_management.flash.invoice_stages_seeded'));
    }

    /** A line on an annexure — the claim sheet entries the schedule is built from. */
    public function storeAnnexureItem(Request $request, string $uuid, string $annexureUuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        $annexure = InvoiceAnnexure::where('invoice_id', $invoice->id)
            ->where('uuid', $annexureUuid)
            ->firstOrFail();

        $data = $request->validate([
            'entry_date'          => ['nullable', 'date'],
            'description'         => ['nullable', 'string', 'max:500'],
            'party'               => ['nullable', 'string', 'max:190'],
            'post_level'          => ['nullable', 'string', 'max:60'],
            'cc'                  => ['nullable', 'string', 'max:20'],
            'size'                => ['nullable', 'string', 'max:20'],
            'variant'             => ['nullable', 'string', 'max:30'],
            'quantity'            => ['nullable', 'numeric'],
            'tariff'              => ['nullable', 'numeric'],
            'kilometres'          => ['nullable', 'numeric'],
            'less_kilometres'     => ['nullable', 'numeric'],
            'hours'               => ['nullable', 'numeric'],
            'less_hours'          => ['nullable', 'numeric'],
            'appointment_amount'  => ['nullable', 'numeric'],
            'kimopax_fee'         => ['nullable', 'numeric'],
            'claimed_this_period' => ['nullable', 'boolean'],
        ]);

        $annexure->items()->create($data + [
            'sort' => (int) ($annexure->items()->max('sort') + 1),
        ]);

        $this->invoicing->recalculate($invoice);

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    public function destroyAnnexureItem(Request $request, string $uuid, string $itemUuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        $item = InvoiceAnnexureItem::whereIn(
            'annexure_id',
            InvoiceAnnexure::where('invoice_id', $invoice->id)->select('id')
        )->where('uuid', $itemUuid)->firstOrFail();

        $item->delete();
        $this->invoicing->recalculate($invoice);

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    /**
     * A disbursement row entered directly on Form 2.
     *
     * Four of the client's thirteen categories have no annexure behind them — monitoring and
     * supervision, environmental consultant, geohydrologist, town planners — so they are typed
     * here rather than totalled from a claim sheet.
     */
    public function storeDisbursement(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        $data = $request->validate([
            'block'        => ['required', Rule::in(InvoiceDisbursement::BLOCKS)],
            'description'  => ['required', 'string', 'max:190'],
            'reference'    => ['nullable', 'string', 'max:60'],
            'this_account' => ['nullable', 'numeric', 'min:0'],
        ]);

        InvoiceDisbursement::updateOrCreate(
            [
                'invoice_id'  => $invoice->id,
                'block'       => $data['block'],
                'description' => $data['description'],
            ],
            [
                'reference'    => $data['reference'] ?? null,
                'this_account' => $data['this_account'] ?? 0,
                'sort'         => (int) (InvoiceDisbursement::where('invoice_id', $invoice->id)->max('sort') + 1),
            ],
        );

        $this->invoicing->recalculate($invoice);

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    public function destroyDisbursement(Request $request, string $uuid, string $rowUuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        InvoiceDisbursement::where('invoice_id', $invoice->id)->where('uuid', $rowUuid)->firstOrFail()->delete();
        $this->invoicing->recalculate($invoice);

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }


    // ── The Implementing Agent's payment certificate ─────────────────────────

    /**
     * Add the certificate to this account.
     *
     * Refused unless the practice has said in setup that it takes implementing-agent appointments:
     * the certificate commits the funder's managers to signing a page, and it should not be
     * possible to produce one by accident.
     */
    public function openCertificate(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->editableInvoice($request, $uuid);

        abort_unless($this->certificates->enabledForCompany(), 403);

        $this->certificates->open($invoice);

        return back()->with('success', __('project_management.flash.certificate_opened'));
    }

    public function updateCertificate(Request $request, string $uuid): RedirectResponse
    {
        $certificate = $this->editableCertificate($request, $uuid);

        $data = $request->validate([
            'implementing_agent'       => ['nullable', 'string', 'max:190'],
            'project_funder'           => ['nullable', 'string', 'max:190'],
            'tender_number'            => ['nullable', 'string', 'max:80'],
            'project_name'             => ['nullable', 'string', 'max:190'],
            'certificate_number'       => ['nullable', 'string', 'max:40'],
            'completion_certificate'   => ['nullable', 'string', 'max:20'],
            'total_approved_value'     => ['nullable', 'numeric'],
            'total_construction_cost'  => ['nullable', 'numeric'],
            'max_fee_base'             => ['nullable', 'numeric', 'min:0'],
            // Null means "derive it from the schedule", which is the normal case.
            'claimed_previously'       => ['nullable', 'numeric', 'min:0'],
            'work_done_to_date'        => ['nullable', 'numeric'],
            'nominated_subcontractors' => ['nullable', 'numeric'],
            'escalation'               => ['nullable', 'numeric'],
            'penalties_recovered'      => ['nullable', 'numeric', 'min:0'],
            'materials_on_site'        => ['nullable', 'numeric'],
            'previously_certified'     => ['nullable', 'numeric'],
            'retention_rate'           => ['nullable', 'numeric', 'between:0,1'],
            'previous_retention_held'  => ['nullable', 'numeric'],
            'retention_surety_rate'    => ['nullable', 'numeric', 'between:0,1'],
            'cession_payments'         => ['nullable', 'numeric'],
            'notes'                    => ['nullable', 'string', 'max:5000'],
            'agent_name'               => ['nullable', 'string', 'max:190'],
            'project_manager_name'     => ['nullable', 'string', 'max:190'],
            'pmu_manager_name'         => ['nullable', 'string', 'max:190'],
            'technical_manager_name'   => ['nullable', 'string', 'max:190'],
            'cfo_name'                 => ['nullable', 'string', 'max:190'],
            'municipal_manager_name'   => ['nullable', 'string', 'max:190'],
        ]);

        // Absent keys must not blank stored values — the screen saves one block at a time.
        $this->certificates->update($certificate, array_intersect_key($data, $request->all()));

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    /** One Yes/No answer on the KPI block. */
    public function updateCertificateKpi(Request $request, string $uuid, string $kpiUuid): RedirectResponse
    {
        $certificate = $this->editableCertificate($request, $uuid);

        $data = $request->validate([
            'question' => ['nullable', 'string', 'max:255'],
            'answer'   => ['nullable', Rule::in(['YES', 'NO', 'N/A'])],
        ]);

        InvoiceAgentKpi::where('certificate_id', $certificate->id)->where('uuid', $kpiUuid)
            ->firstOrFail()
            ->update(array_intersect_key($data, $request->all()));

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    public function storeCertificateKpi(Request $request, string $uuid): RedirectResponse
    {
        $certificate = $this->editableCertificate($request, $uuid);

        $data = $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'answer'   => ['nullable', Rule::in(['YES', 'NO', 'N/A'])],
        ]);

        InvoiceAgentKpi::create($data + [
            'certificate_id' => $certificate->id,
            'position'       => (int) InvoiceAgentKpi::where('certificate_id', $certificate->id)->max('position') + 1,
        ]);

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    public function destroyCertificateKpi(Request $request, string $uuid, string $kpiUuid): RedirectResponse
    {
        $certificate = $this->editableCertificate($request, $uuid);

        InvoiceAgentKpi::where('certificate_id', $certificate->id)->where('uuid', $kpiUuid)
            ->firstOrFail()->delete();

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    /** A payment the funder actually made — a historical row, not this account's claim. */
    public function storeCertificatePayment(Request $request, string $uuid): RedirectResponse
    {
        $certificate = $this->editableCertificate($request, $uuid);

        $data = $request->validate([
            'paid_on'          => ['nullable', 'date'],
            'service_provider' => ['nullable', 'string', 'max:190'],
            'to_consultant'    => ['nullable', 'numeric'],
            'to_contractor'    => ['nullable', 'numeric'],
            'cession'          => ['nullable', 'numeric'],
        ]);

        InvoiceAgentPayment::create($data + [
            'certificate_id' => $certificate->id,
            'position'       => (int) InvoiceAgentPayment::where('certificate_id', $certificate->id)->max('position') + 1,
            // Only the row the invoice owns carries this, and the service maintains that row.
            'is_current_claim' => false,
        ]);

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    public function updateCertificatePayment(Request $request, string $uuid, string $rowUuid): RedirectResponse
    {
        $certificate = $this->editableCertificate($request, $uuid);

        $row = InvoiceAgentPayment::where('certificate_id', $certificate->id)
            ->where('uuid', $rowUuid)->firstOrFail();

        // The claim row mirrors the account. Letting it be typed over would break the one link
        // that keeps the certificate and the invoice telling the same story.
        abort_if($row->is_current_claim, 422, __('project_management.invoicing.claim_row_is_derived'));

        $row->update($request->validate([
            'paid_on'          => ['nullable', 'date'],
            'service_provider' => ['nullable', 'string', 'max:190'],
            'to_consultant'    => ['nullable', 'numeric'],
            'to_contractor'    => ['nullable', 'numeric'],
            'cession'          => ['nullable', 'numeric'],
        ]));

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    public function destroyCertificatePayment(Request $request, string $uuid, string $rowUuid): RedirectResponse
    {
        $certificate = $this->editableCertificate($request, $uuid);

        $row = InvoiceAgentPayment::where('certificate_id', $certificate->id)
            ->where('uuid', $rowUuid)->firstOrFail();

        abort_if($row->is_current_claim, 422, __('project_management.invoicing.claim_row_is_derived'));

        $row->delete();

        return back()->with('success', __('project_management.flash.invoice_updated'));
    }

    /** The certificate on an account that may still be edited, or a 404/403. */
    private function editableCertificate(Request $request, string $uuid): InvoiceAgentCertificate
    {
        $invoice = $this->editableInvoice($request, $uuid);

        $certificate = InvoiceAgentCertificate::where('invoice_id', $invoice->id)->first();

        abort_if($certificate === null, 404);

        return $certificate;
    }

    /**
     * An account that may still be edited.
     *
     * One place to make the check, because "is this account still open" is the question every one
     * of these endpoints has to ask and the one it would be easiest to forget in a new one.
     */
    private function editableInvoice(Request $request, string $uuid): Invoice
    {
        $invoice = $this->findInvoice($uuid);
        $this->authorize('update', $invoice->project);
        $this->authorizeCosts($request);

        abort_if($invoice->isFrozen(), 422, 'That account has been issued and can no longer be changed.');

        return $invoice;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * An account is nothing but cost, so there is no redacted view of one.
     *
     * Elsewhere in the module a viewer without `pm.costs.view` sees the page with the money removed
     * (ProjectPresenter::redactFor). Here that would be a blank form, so the route is refused
     * outright and the hub card is tiered to match.
     */
    private function authorizeCosts(Request $request): void
    {
        abort_unless($request->user()->can('viewCosts', Project::class), 403);
    }

    private function findInvoice(string $uuid): Invoice
    {
        return Invoice::with('project')->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function summaryRow(Invoice $invoice): array
    {
        return [
            'id'             => $invoice->uuid,
            'invoice_number' => $invoice->invoice_number,
            'account_number' => $invoice->account_number,
            'project'        => $invoice->project?->name,
            'project_code'   => $invoice->project?->code,
            'client_name'    => $invoice->client_name,
            'invoice_date'   => optional($invoice->invoice_date)->toDateString(),
            'status'         => $invoice->status,
            'currency'       => $invoice->currency,
            'amount_now_due' => Money::money($invoice->amount_now_due),
        ];
    }

    /** @return array<string, mixed> */
    private function detailRow(Invoice $invoice): array
    {
        return $this->summaryRow($invoice) + [
            'account_type'              => $invoice->account_type,
            'period_start'              => optional($invoice->period_start)->toDateString(),
            'period_end'                => optional($invoice->period_end)->toDateString(),
            'client_address'            => $invoice->client_address,
            'client_vat_number'         => $invoice->client_vat_number,
            'attention'                 => $invoice->attention,
            'client_project_number'     => $invoice->client_project_number,
            'consultant_name'           => $invoice->consultant_name,
            'consultant_address'        => $invoice->consultant_address,
            'consultant_reference'      => $invoice->consultant_reference,
            'consultant_vat_number'     => $invoice->consultant_vat_number,
            'banking_name'              => $invoice->banking_name,
            'banking_account_name'      => $invoice->banking_account_name,
            'banking_account_number'    => $invoice->banking_account_number,
            'banking_branch_code'       => $invoice->banking_branch_code,
            'banking_reference'         => $invoice->banking_reference,
            'vendor_number'             => $invoice->vendor_number,
            'file_number'               => $invoice->file_number,
            'description'               => $invoice->description,
            'vat_rate'                  => (string) $invoice->vat_rate,
            'disbursement_fee_rate'     => (string) $invoice->disbursement_fee_rate,
            'fees_now_claimed'          => Money::money($invoice->fees_now_claimed),
            'disbursements_now_claimed' => Money::money($invoice->disbursements_now_claimed),
            'subtotal'                  => Money::money($invoice->subtotal),
            'vat_amount'                => Money::money($invoice->vat_amount),
            'generated_at'              => optional($invoice->generated_at)->toDateTimeString(),
            'stages'                    => $invoice->stages()->get()->map(fn (InvoiceFeeStage $s) => [
                'id'                 => $s->uuid,
                'code'               => $s->code,
                'label'              => $s->label,
                // A fixed row takes its value from an annexure, so it has no apportionment or
                // progress to edit. Without this flag the page cannot tell the two kinds apart.
                'is_fixed'           => $s->isFixed(),
                'apportionment'      => $s->apportionment,
                'progress'           => $s->progress,
                'stage_fee'          => Money::money($s->stage_fee),
                'value_to_date'      => Money::money($s->value_to_date),
                'previously_claimed' => Money::money($s->previously_claimed),
                'this_account'       => Money::money($s->this_account),
            ])->all(),
        ];
    }
}
