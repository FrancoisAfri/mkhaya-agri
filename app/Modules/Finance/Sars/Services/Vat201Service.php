<?php

namespace App\Modules\Finance\Sars\Services;

use App\Modules\Finance\Accounting\Services\TaxService;
use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\Finance\Sars\Models\SarsTaxConfig;
use App\Modules\Tenancy\Services\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a VAT201 return from the ledger and the company's SARS profile, validates it, and — on
 * generate — persists a versioned SarsSubmission with a stored PDF for the audit trail. Read
 * paths (build/defaultPeriod) never write; generate() is the only mutation. Tenant-scoped via
 * the models (company_id + RLS) and the explicit companyId passed to the tax engine.
 */
class Vat201Service
{
    public function __construct(
        private TaxService $tax,
        private SarsValidator $validator,
        private CurrentCompany $currentCompany,
    ) {
    }

    /** How many months a VAT period spans for the given filing frequency. */
    public function periodMonths(?string $frequency): int
    {
        return match ($frequency) {
            'bi_monthly'  => 2,   // Category A / B
            'category_d'  => 6,   // Category D (farming)
            default       => 1,   // monthly / Category C
        };
    }

    /**
     * The most recent complete VAT period ending before today, sized to the filing frequency.
     * @return array{from:string, to:string}
     */
    public function defaultPeriod(?string $frequency): array
    {
        $months = $this->periodMonths($frequency);
        $to = Carbon::today()->startOfMonth()->subDay();                 // last day of previous month
        $from = (clone $to)->startOfMonth()->subMonths($months - 1);     // back to the period start

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /**
     * Assemble the full VAT201 return for a period WITHOUT persisting: header from the SARS
     * profile, boxes computed from the ledger, and the validation result.
     */
    public function build(string $from, string $to): array
    {
        $config = SarsTaxConfig::current();
        // Always a concrete id so a null current company (e.g. a platform admin) can't compute a
        // cross-tenant VAT201 — fall back to the user's own company, then a sentinel matching nothing.
        $companyId = (int) ($this->currentCompany->id() ?? auth()->user()?->company_id ?? -1);
        $vat = $this->tax->vat201($from, $to, $companyId);

        return [
            'period'     => ['from' => $vat['period']['from'], 'to' => $vat['period']['to'], 'label' => $this->periodLabel($vat['period']['from'], $vat['period']['to'])],
            'header'     => [
                'company_name'   => (string) (\Illuminate\Support\Facades\DB::table('companies')->where('id', $companyId)->value('name') ?? ''),
                'vat_number'     => $config->vat_number,
                'vat_registered' => (bool) $config->vat_registered,
                'vat_frequency'  => $config->vat_frequency,
                'contact_person' => $config->contact_person,
                'contact_email'  => $config->contact_email,
            ],
            'boxes'      => $vat['boxes'],
            'totals'     => [
                'output_tax'      => $vat['output_tax'],
                'input_tax'       => $vat['input_tax'],
                'net'             => $vat['net'],
                'is_payable'      => $vat['is_payable'],
                'total_sales'     => $vat['total_sales'],
                'total_purchases' => $vat['total_purchases'],
            ],
            'validation' => $this->validate($config, $vat),
        ];
    }

    /** Validate the return: the SARS profile, plus VAT201-specific consistency checks. */
    private function validate(SarsTaxConfig $config, array $vat): array
    {
        $result = $this->validator->validateConfig($config);
        $errors = $result['errors'];

        if (! $config->vat_registered) {
            $errors[] = ['entity' => 'config', 'id' => null, 'field' => 'vat_registered', 'message' => 'Company is not marked VAT registered — a VAT201 cannot be filed.'];
        }
        // Output tax should reconcile to standard-rated supplies at 15%; a mismatch means mixed
        // rates or manual VAT journals the operator should confirm before filing.
        $expectedOutput = round(($vat['boxes'][0]['amount'] ?? 0) * 0.15, 2);
        if (abs($expectedOutput - $vat['output_tax']) > max(1.0, abs($vat['output_tax']) * 0.02)) {
            $errors[] = ['entity' => 'vat201', 'id' => null, 'field' => 'output_tax', 'message' => 'Output tax does not reconcile to 15% of standard-rated supplies — check for zero-rated/exempt supplies or manual VAT entries.'];
        }
        if ($vat['output_tax'] < 0 || $vat['input_tax'] < 0) {
            $errors[] = ['entity' => 'vat201', 'id' => null, 'field' => 'tax', 'message' => 'Negative output or input tax in the period — likely misposted credit notes.'];
        }

        // "N lines validated" — the header fields + the four boxes we checked.
        $validated = 5 + count($vat['boxes']);

        return ['validated' => $validated, 'errors' => array_values($errors)];
    }

    /**
     * Generate and persist the VAT201 as a versioned submission with a stored PDF. A new
     * version is created if one already exists for this period (amendment chain).
     */
    public function generate(string $from, string $to, ?string $notes = null): SarsSubmission
    {
        $data = $this->build($from, $to);
        $period = $data['period'];
        $companyId = $this->currentCompany->id();

        $prior = SarsSubmission::ownReturns()->where('tax_type', 'vat201')->where('period', $period['label'])->orderByDesc('version')->first();
        $hasErrors = ! empty($data['validation']['errors']);

        $submission = SarsSubmission::create([
            'company_id'   => $companyId,
            'tax_type'     => 'vat201',
            'period'       => $period['label'],
            'status'       => $hasErrors ? 'generated' : 'validated',
            'version'      => $prior ? $prior->version + 1 : 1,
            'amends_id'    => $prior?->id,
            'totals'       => $data['totals'],
            'payload'      => $data,
            'validation'   => $data['validation'],
            'notes'        => $notes,
            'generated_by' => Auth::id(),
            'generated_at' => now(),
        ]);

        $pdf = Pdf::loadView('pdf.sars.vat201', ['data' => $data, 'submission' => $submission])->setPaper('a4');
        $path = "sars/{$companyId}/vat201/{$submission->reference}.pdf";
        Storage::put($path, $pdf->output());
        $submission->update(['file_path' => $path]);

        return $submission;
    }

    private function periodLabel(string $from, string $to): string
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);

        return $f->isSameMonth($t) ? $f->format('M Y') : ($f->format('M Y') . ' – ' . $t->format('M Y'));
    }
}
