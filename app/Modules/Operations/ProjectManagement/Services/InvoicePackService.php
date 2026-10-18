<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAnnexure;
use App\Modules\Operations\ProjectManagement\Models\InvoiceCashflow;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\AccountSummary;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\AnnexureCatalogue;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The account pack: the documents a client actually receives.
 *
 * In order — Tax Invoice, Summary, Form 1, Form 2, the annexures actually used, and the cashflow.
 * Only the annexures on the account appear; a pack that printed all thirteen with ten of them
 * empty is not the document the client has been issuing.
 *
 * ── Assembling and rendering are separate ───────────────────────────────────
 *
 * This class builds arrays. Blade turns them into documents and PhpSpreadsheet turns them into
 * sheets. Keeping the two apart is what lets the same figures come out as a PDF, an XLSX or a
 * screen without three descriptions of what a Form 1 contains.
 *
 * ── Nothing is recalculated here ────────────────────────────────────────────
 *
 * Every figure is read from the stored rows. A pack generated from an issued account must show
 * what that account says, not what the engine would say today — the signatures on it describe the
 * former.
 */
class InvoicePackService
{
    /** The pack, in the order the client's document has it. */
    public const KINDS = ['tax_invoice', 'summary', 'form_1', 'form_2', 'annexures', 'cashflow'];

    public function __construct(private readonly InvoicingService $invoicing)
    {
    }

    /** Which documents this account actually has, as `kind => label`. */
    public function available(Invoice $invoice): array
    {
        $documents = [
            'tax_invoice' => 'Tax Invoice',
            'summary'     => 'Summary',
            'form_1'      => 'Form 1 — Professional Fees',
            'form_2'      => 'Form 2 — Disbursements',
        ];

        foreach ($invoice->annexures()->orderBy('number')->get() as $annexure) {
            $documents['annexure_' . $annexure->number] = 'Annexure ' . $annexure->number . ' — ' . $annexure->title;
        }

        if (InvoiceCashflow::where('project_id', $invoice->project_id)->exists()) {
            $documents['cashflow'] = 'Cashflow';
        }

        // Only where somebody added one. Most accounts are not implementing-agent appointments.
        if ($invoice->agentCertificate) {
            $documents['agent_certificate'] = 'Implementing Agent Payment Certificate';
        }

        $documents['pack'] = 'Complete invoice pack';

        return $documents;
    }

    /**
     * One document as data.
     *
     * @return array{kind:string, title:string, orientation:string, header:array, body:array}
     */
    public function document(Invoice $invoice, string $kind): array
    {
        if (str_starts_with($kind, 'annexure_')) {
            return $this->annexure($invoice, (int) Str::after($kind, 'annexure_'));
        }

        return match ($kind) {
            'tax_invoice' => $this->taxInvoice($invoice),
            'summary'     => $this->summary($invoice),
            'form_1'      => $this->formOne($invoice),
            'form_2'      => $this->formTwo($invoice),
            'cashflow'    => $this->cashflow($invoice),
            'agent_certificate' => $this->agentCertificate($invoice),
            default       => throw new InvalidArgumentException("There is no '{$kind}' document in this pack."),
        };
    }

    /** Every document, in order — what "Generate Complete Invoice Pack" produces. */
    public function pack(Invoice $invoice): array
    {
        $documents = [];

        foreach (array_keys($this->available($invoice)) as $kind) {
            if ($kind === 'pack') {
                continue;
            }

            $documents[] = $this->document($invoice, $kind);
        }

        return $documents;
    }

    // ── The documents ────────────────────────────────────────────────────────

    private function taxInvoice(Invoice $invoice): array
    {
        return [
            'kind'        => 'tax_invoice',
            'title'       => 'TAX INVOICE',
            'orientation' => 'portrait',
            'header'      => $this->header($invoice),
            'body'        => [
                'description' => $invoice->description,
                'lines'       => [
                    ['label' => 'Professional fees', 'amount' => Money::money($invoice->fees_now_claimed)],
                    ['label' => 'Disbursements', 'amount' => Money::money($invoice->disbursements_now_claimed)],
                ],
                'subtotal' => Money::money($invoice->subtotal),
                'vat_rate' => $this->percent($invoice->vat_rate),
                'vat'      => Money::money($invoice->vat_amount),
                'total'    => Money::money($invoice->amount_now_due),
                // A tax invoice that does not say where to pay is not one a client can act on.
                'banking'  => [
                    'bank'           => $invoice->banking_name,
                    'account_name'   => $invoice->banking_account_name,
                    'account_number' => $invoice->banking_account_number,
                    'branch_code'    => $invoice->banking_branch_code,
                    'reference'      => $invoice->banking_reference ?? $invoice->invoice_number,
                ],
                'signatories' => ['Consulting Engineer', 'Programme Manager'],
            ],
        ];
    }

    private function summary(Invoice $invoice): array
    {
        // Ordered as the client's Summary lists them — the sheet their finance people read down.
        $categories = $invoice->disbursements()->where('block', 'category')->get()
            ->sortBy(fn ($d) => AnnexureCatalogue::summarySort($d->description))
            ->values();

        $summary = new AccountSummary(
            feesNowClaimed: (string) $invoice->fees_now_claimed,
            disbursementCategories: $categories->map(fn ($d) => [
                'description'        => $d->description,
                'reference'          => $d->reference ?? '',
                'previously_claimed' => $d->previously_claimed,
                'now_claimed'        => $d->this_account,
            ])->all(),
            vatRate: (string) $invoice->vat_rate,
            feesPreviouslyClaimed: (string) $invoice->stages()->sum('previously_claimed'),
        );

        return [
            'kind'        => 'summary',
            'title'       => 'SUMMARY',
            'orientation' => 'portrait',
            'header'      => $this->header($invoice),
            'body'        => $summary->toArray() + [
                // The client's certification wording, and the two signature blocks the form carries.
                'certification' => 'Certified that the amount shown above is claimable in accordance with the agreement.',
                'signatories'   => ['Consulting Engineer', 'Programme Manager'],
            ],
        ];
    }

    private function formOne(Invoice $invoice): array
    {
        $header = $invoice->feeAccount;

        return [
            'kind'        => 'form_1',
            'title'       => 'PROFESSIONAL FEE ACCOUNT — FORM 1',
            'orientation' => 'landscape',
            'header'      => $this->header($invoice),
            'body'        => [
                'value_of_works' => [
                    'external'              => Money::money($header?->external_works),
                    'structural'            => Money::money($header?->structural_works),
                    'other'                 => Money::money($header?->other_works),
                    'total'                 => Money::money($header?->total_value_of_works),
                    'contingency_allowance' => Money::money($header?->contingency_allowance),
                    'completed_to_date'     => Money::money($header?->construction_completed_to_date),
                ],
                'payment_certificate' => $header?->payment_certificate,
                'certificate_dated'   => optional($header?->payment_certificate_dated)->toDateString(),
                'fee_percentage'      => $this->percent($header?->fee_percentage),
                'approved_fee'        => Money::money($header?->approved_fee),
                // Printed but never used to calculate a claim, so the reader can see it is pending.
                'adjusted_fee'        => $header?->adjusted_fee !== null ? Money::money($header->adjusted_fee) : null,
                'stages'              => $invoice->stages()->get()->map(fn ($s) => [
                    'code'               => $s->code,
                    'label'              => $s->label,
                    'apportionment'      => $this->percent($s->apportionment),
                    'progress'           => $this->percent($s->progress),
                    'stage_fee'          => Money::money($s->stage_fee),
                    'value_to_date'      => Money::money($s->value_to_date),
                    'previously_claimed' => Money::money($s->previously_claimed),
                    'this_account'       => Money::money($s->this_account),
                ])->all(),
                'totals' => $this->invoicing->feeCalculator($invoice)->toArray(),
            ],
        ];
    }

    private function formTwo(Invoice $invoice): array
    {
        return [
            'kind'        => 'form_2',
            'title'       => 'DISBURSEMENTS FEE ACCOUNT — FORM 2',
            'orientation' => 'landscape',
            'header'      => $this->header($invoice),
            'body'        => $this->invoicing->disbursementCalculator($invoice)->toArray(),
        ];
    }

    private function annexure(Invoice $invoice, int $number): array
    {
        $annexure = $invoice->annexures()->with('items')->where('number', $number)->first();

        if (! $annexure) {
            throw new InvalidArgumentException("Annexure {$number} is not on this account.");
        }

        return [
            'kind'        => 'annexure_' . $number,
            'title'       => 'ANNEXURE ' . $number . ' — ' . Str::upper($annexure->title),
            'orientation' => 'landscape',
            'header'      => $this->header($invoice),
            'body'        => [
                'number'    => $number,
                'line_type' => $annexure->line_type,
                'columns'   => $this->annexureColumns($annexure->line_type),
                'rows'      => $annexure->items->map(fn ($i) => $this->annexureRow($annexure, $i))->all(),
                'totals'    => [
                    'previously_claimed' => Money::money($annexure->previously_claimed),
                    'this_invoice'       => Money::money($annexure->this_invoice),
                    'total'              => Money::money($annexure->total),
                ],
                // The monthly planning figures the workbook carries beside several of the
                // schedules. A note about the appointment, not a claim — so it never totals in.
                'projections' => collect($annexure->projections ?? [])
                    ->map(fn ($amount, $month) => ['month' => $month, 'amount' => Money::money($amount)])
                    ->values()->all(),
            ],
        ];
    }

    /** The columns the client's claim sheet shows for each shape of annexure. */
    private function annexureColumns(string $lineType): array
    {
        $tail = ['Previous claimed', 'This invoice', 'Total'];

        return match ($lineType) {
            AnnexureCatalogue::DOCUMENTATION   => array_merge(['Quantity', 'Size', 'Description', 'Tariff'], $tail),
            AnnexureCatalogue::TRAVELLING      => array_merge(['Date', 'Name', 'cc', 'Tariff', 'KM', 'Less 50km'], $tail, ['Destination and reason']),
            AnnexureCatalogue::TRAVELLING_TIME => array_merge(['Date', 'Name', 'Post level', 'Tariff', 'Hours', 'Less hours'], $tail),
            AnnexureCatalogue::TIME_BASIS      => array_merge(['Description', 'Rate', 'Hours'], $tail),
            // The surcharged sheets. Annexures 6 and 13 also carry a Kimopax fee beside the claim.
            default => array_merge(
                ['Date', 'Company', 'Description', 'Appointment amount'],
                $tail,
                ['Kimopax fee'],
            ),
        };
    }

    private function annexureRow(InvoiceAnnexure $annexure, $item): array
    {
        $tail = [
            Money::money($item->previously_claimed),
            Money::money($item->this_invoice),
            Money::money($item->total),
        ];

        $date = optional($item->entry_date)->toDateString() ?? '';

        return match ($annexure->line_type) {
            AnnexureCatalogue::DOCUMENTATION => array_merge(
                [$item->quantity, $item->size ?? '', $item->description ?? '', $item->tariff],
                $tail,
            ),
            AnnexureCatalogue::TRAVELLING => array_merge(
                [$date, $item->party ?? '', $item->cc ?? '', $item->tariff, $item->kilometres, $item->less_kilometres],
                $tail,
                [$item->description ?? ''],
            ),
            AnnexureCatalogue::TRAVELLING_TIME => array_merge(
                [$date, $item->party ?? '', $item->post_level ?? '', $item->tariff, $item->hours, $item->less_hours],
                $tail,
            ),
            AnnexureCatalogue::TIME_BASIS => array_merge(
                [$item->description ?? '', $item->tariff, $item->hours],
                $tail,
            ),
            default => array_merge(
                [$date, $item->party ?? '', $item->description ?? '', Money::money($item->appointment_amount)],
                $tail,
                [$item->kimopax_fee === null ? '' : Money::money($item->kimopax_fee)],
            ),
        };
    }

    private function cashflow(Invoice $invoice): array
    {
        $cumulative = '0';

        $rows = InvoiceCashflow::where('project_id', $invoice->project_id)
            ->orderBy('forecast_year')
            ->orderBy('forecast_month')
            ->get()
            ->map(function (InvoiceCashflow $row) use (&$cumulative) {
                $cumulative = Money::add($cumulative, $row->amount_incl_vat);

                return [
                    'month'      => \Carbon\Carbon::create($row->forecast_year, $row->forecast_month, 1)->format('F Y'),
                    'amount'     => Money::money($row->amount_incl_vat),
                    'cumulative' => Money::money($cumulative),
                ];
            })->all();

        return [
            'kind'        => 'cashflow',
            'title'       => 'CASHFLOW',
            'orientation' => 'portrait',
            'header'      => $this->header($invoice),
            'body'        => ['rows' => $rows, 'total' => Money::money($cumulative)],
        ];
    }

    /** The letterhead every document in the pack carries. */
    /**
     * The `AI` sheet — the Implementing Agent's payment certificate.
     *
     * Landscape, because the payment schedule at the foot has seven columns and the certification
     * blocks above it are full-width signature lines.
     */
    private function agentCertificate(Invoice $invoice): array
    {
        $certificate = $invoice->agentCertificate;

        if (! $certificate) {
            throw new InvalidArgumentException('This account has no implementing agent certificate.');
        }

        $certificate->loadMissing(['kpis', 'payments']);
        $figures = $certificate->calculator()->toArray();

        return [
            'kind'        => 'agent_certificate',
            'title'       => 'IMPLEMENTING AGENT: ' . ($certificate->implementing_agent ?? ''),
            'orientation' => 'landscape',
            'header'      => $this->header($invoice) + [
                'project_funder'     => $certificate->project_funder,
                'tender_number'      => $certificate->tender_number,
                'certificate_number' => $certificate->certificate_number,
            ],
            'body' => [
                // The four certification blocks, in the order the funder signs them.
                'certifications' => [
                    ['role' => 'Name of the Implementing Agent', 'name' => $certificate->agent_name],
                    ['role' => 'Name of Project Manager', 'name' => $certificate->project_manager_name],
                    ['role' => 'Name of PMU Manager', 'name' => $certificate->pmu_manager_name],
                    ['role' => 'Technical Manager', 'name' => $certificate->technical_manager_name],
                    ['role' => 'Chief Financial Officer', 'name' => $certificate->cfo_name],
                    ['role' => 'Municipal Manager', 'name' => $certificate->municipal_manager_name],
                ],
                'completion_certificate'  => $certificate->completion_certificate,
                'total_approved_value'    => Money::money($certificate->total_approved_value ?? '0'),
                'total_construction_cost' => Money::money($certificate->total_construction_cost ?? '0'),
                'kpis' => $certificate->kpis->map(fn ($k) => [
                    'question' => $k->question,
                    'answer'   => $k->answer,
                ])->all(),
                'management_fee'    => $figures['management_fee'],
                'construction'      => $figures['construction'],
                'retention_account' => $figures['retention_account'],
                'payment_summary'   => $figures['payment_summary'],
                'certificate_total' => $figures['certificate_total'],
                'schedule'          => [
                    'rows' => $certificate->payments->values()->map(fn ($p, $i) => [
                        'number'           => $i + 1,
                        'paid_on'          => optional($p->paid_on)->format('d/m/Y'),
                        'service_provider' => $p->service_provider,
                        'to_consultant'    => Money::money($p->to_consultant),
                        'to_contractor'    => Money::money($p->to_contractor),
                        'cession'          => Money::money($p->cession),
                        'total'            => Money::money(Money::sum([
                            (string) $p->to_consultant, (string) $p->to_contractor, (string) $p->cession,
                        ])),
                    ])->all(),
                    'totals' => $figures['schedule']['totals'],
                ],
                'notes' => $certificate->notes,
            ],
        ];
    }

    private function header(Invoice $invoice): array
    {
        return [
            'invoice_number'        => $invoice->invoice_number,
            'account_number'        => $invoice->account_number,
            'account_type'          => Str::headline($invoice->account_type),
            'invoice_date'          => optional($invoice->invoice_date)->format('d F Y'),
            'period'                => $invoice->period_start && $invoice->period_end
                ? $invoice->period_start->format('d M Y') . ' – ' . $invoice->period_end->format('d M Y')
                : null,
            'project'               => $invoice->project?->name,
            'project_code'          => $invoice->project?->code,
            'client_project_number' => $invoice->client_project_number,
            'file_number'           => $invoice->file_number,
            'client_name'           => $invoice->client_name,
            'client_address'        => $invoice->client_address,
            'client_vat_number'     => $invoice->client_vat_number,
            'attention'             => $invoice->attention,
            'consultant_name'       => $invoice->consultant_name,
            'consultant_address'    => $invoice->consultant_address,
            'consultant_reference'  => $invoice->consultant_reference,
            'consultant_vat_number' => $invoice->consultant_vat_number,
            'vendor_number'         => $invoice->vendor_number,
            'currency'              => $invoice->currency,
            'status'                => $invoice->status,
        ];
    }

    /** A proportion printed as the percentage the client's form shows. */
    private function percent(string|float|int|null $rate): string
    {
        if ($rate === null) {
            return '—';
        }

        return rtrim(rtrim(Money::round(Money::mul($rate, '100'), 2), '0'), '.') . '%';
    }

    /**
     * A document as a header row and data rows, for the spreadsheet export.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    public function asTable(array $document): array
    {
        $body = $document['body'];

        if (isset($body['columns'], $body['rows'])) {
            return [$body['columns'], array_map(fn ($r) => array_map(fn ($v) => (string) $v, $r), $body['rows'])];
        }

        if ($document['kind'] === 'form_1') {
            return [
                ['Stage', 'Description', 'Apportionment', 'Progress', 'Stage fee', 'Value to date', 'Previously claimed', 'This account'],
                array_map(fn ($s) => [
                    $s['code'], $s['label'], $s['apportionment'], $s['progress'],
                    $s['stage_fee'], $s['value_to_date'], $s['previously_claimed'], $s['this_account'],
                ], $body['stages']),
            ];
        }

        if ($document['kind'] === 'cashflow') {
            return [
                ['Month', 'Amount (VAT Incl.)', 'Cumulative'],
                array_map(fn ($r) => [$r['month'], $r['amount'], $r['cumulative']], $body['rows']),
            ];
        }

        if ($document['kind'] === 'summary') {
            $rows = [['Fees', 'Form 1', $body['fees']['previously_claimed'], $body['fees']['now_claimed'], $body['fees']['total_to_date']]];

            foreach ($body['disbursements'] as $line) {
                $rows[] = [
                    $line['description'], $line['reference'],
                    $line['previously_claimed'], $line['now_claimed'], $line['total_to_date'],
                ];
            }

            $rows[] = ['Total now claimed', '', '', $body['total_now_claimed'], ''];
            $rows[] = ['VAT', '', '', $body['vat'], ''];
            $rows[] = ['Amount now due', '', '', $body['amount_now_due'], ''];

            return [['Description', 'Reference', 'Previously claimed', 'Now claimed this invoice', 'Total claimed to date'], $rows];
        }

        if ($document['kind'] === 'agent_certificate') {
            // The certificate's spreadsheet form is its payment schedule — the only part of it that
            // is a table. The three calculated blocks follow it as labelled rows, because a reader
            // opening the export wants the figures, not a picture of the signature lines.
            $rows = array_map(fn ($r) => [
                (string) $r['number'], (string) $r['paid_on'], (string) $r['service_provider'],
                $r['to_consultant'], $r['to_contractor'], $r['cession'], $r['total'],
            ], $body['schedule']['rows']);

            $totals = $body['schedule']['totals'];
            $rows[] = ['', '', 'Claimed previously', $totals['to_consultant'], $totals['to_contractor'], $totals['cession'], ''];
            $rows[] = ['', '', 'Total claimed', '', '', '', $totals['total']];
            $rows[] = ['', '', 'Management fee on this claim', '', '', '', $body['management_fee']['this_claim']];
            $rows[] = ['', '', 'Total value due for construction', '', '', '', $body['construction']['due_for_construction']];
            $rows[] = ['', '', 'Total value for this certificate', '', '', '', $body['certificate_total']];

            return [
                ['No', 'Date', 'Service provider', 'To consultant', 'To contractor', 'Cession', 'Total'],
                $rows,
            ];
        }

        // Tax invoice and Form 2: a label/amount statement rather than a schedule.
        $rows = [];

        foreach ($body['lines'] ?? [] as $line) {
            $rows[] = [$line['label'], $line['amount']];
        }

        if (isset($body['subtotal'])) {
            $rows[] = ['Subtotal', $body['subtotal']];
            $rows[] = ['VAT ' . ($body['vat_rate'] ?? ''), $body['vat']];
            $rows[] = ['Total', $body['total']];
        }

        return [['Description', 'Amount'], $rows];
    }
}
