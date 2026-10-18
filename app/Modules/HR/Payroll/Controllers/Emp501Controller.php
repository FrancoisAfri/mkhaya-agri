<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\HR\Payroll\Services\Emp501Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EMP501 workspace (Payroll): pick a tax year, see the annual reconciliation and the IRP5 /
 * IT3(a) certificate schedule built live from finalised payroll, generate a versioned
 * submission into the shared SARS history, and download an individual employee certificate.
 * Tenant-scoped via the service.
 */
class Emp501Controller extends Controller
{
    public function __construct(private Emp501Service $emp501)
    {
    }

    public function index(Request $request): Response
    {
        $taxYear = (int) ($request->integer('tax_year') ?: $this->emp501->defaultTaxYear());

        $data = $this->emp501->build($taxYear);

        $recent = SarsSubmission::ownReturns()->where('tax_type', 'emp501')->orderByDesc('id')->limit(8)->get()
            ->map(fn (SarsSubmission $s) => [
                'id'           => $s->id,
                'reference'    => $s->reference,
                'period'       => $s->period,
                'status'       => $s->status,
                'version'      => $s->version,
                'certificates' => $s->totals['certificate_count'] ?? null,
                'reconciled'   => $s->totals['reconciled'] ?? null,
                'generated_at' => $s->generated_at?->toDateString(),
            ]);

        // Offer the completed tax years for the picker.
        $years = \Illuminate\Support\Facades\DB::table('sa_tax_years')
            ->distinct()->orderByDesc('tax_year')->pluck('tax_year')->map(fn ($y) => (int) $y)->unique()->values();

        return Inertia::render('Modules/HR/Payroll/Emp501', [
            'emp501'  => $data,
            'taxYear' => $taxYear,
            'years'   => $years,
            'recent'  => $recent,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tax_year' => 'required|integer|min:2000|max:2100',
            'notes'    => 'nullable|string|max:2000',
        ]);

        $submission = $this->emp501->generate((int) $data['tax_year'], $data['notes'] ?? null);

        return redirect()
            ->route('finance.accounting.sars.submissions.show', $submission->id)
            ->with('success', "EMP501 {$submission->reference} generated (v{$submission->version}).");
    }

    public function certificate(Request $request, int $employee): \Symfony\Component\HttpFoundation\Response
    {
        $taxYear = (int) ($request->integer('tax_year') ?: $this->emp501->defaultTaxYear());
        $pdf = $this->emp501->certificatePdf($employee, $taxYear);

        return $pdf->download("IRP5-{$employee}-{$taxYear}.pdf");
    }
}
