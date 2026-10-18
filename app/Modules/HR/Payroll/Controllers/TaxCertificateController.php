<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\TaxCertificate;
use App\Modules\HR\Payroll\Services\Bureau\BureauAuditService;
use App\Modules\HR\Payroll\Services\TaxCertificateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The certificates that were issued, and the buttons that issue more.
 *
 * Searchable by the things somebody actually has in front of them when they ring up in July asking
 * for their IRP5 again: a name, an employee number, a tax number, a year. Not a uuid.
 */
class TaxCertificateController extends Controller
{
    public function __construct(
        private readonly TaxCertificateService $certificates,
        private readonly BureauAuditService $audit,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $search = trim((string) $request->input('search'));

        $certificates = TaxCertificate::query()
            ->when($request->input('tax_year'), fn ($q, $year) => $q->where('tax_year', $year))
            ->when($request->input('client'), fn ($q, $uuid) => $q->whereHas('bureauCompany', fn ($c) => $c->where('uuid', $uuid)))
            ->when($request->input('type'), fn ($q, $type) => $q->where('type', $type))
            // Superseded versions are history, not the worklist. They are reachable from the
            // certificate that replaced them.
            ->when(! $request->boolean('include_superseded'), fn ($q) => $q->current())
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('certificate_number', 'ilike', "%{$search}%")
                ->orWhereRaw("employee_snapshot->>'name' ILIKE ?", ["%{$search}%"])
                ->orWhereRaw("employee_snapshot->>'employee_number' ILIKE ?", ["%{$search}%"])
                ->orWhereRaw("employee_snapshot->>'tax_number' ILIKE ?", ["%{$search}%"])
                ->orWhereRaw("employee_snapshot->>'id_number' ILIKE ?", ["%{$search}%"])))
            ->with('bureauCompany:id,uuid,name')
            ->orderByDesc('tax_year')
            ->orderBy('certificate_number')
            ->paginate(50)
            ->withQueryString();

        $certificates->getCollection()->transform(fn (TaxCertificate $c) => [
            'uuid' => $c->uuid, 'certificate_number' => $c->certificate_number,
            'type' => $c->type, 'version' => $c->version, 'status' => $c->status,
            'tax_year' => $c->tax_year, 'period' => $c->period,
            'employee' => $c->employeeName(),
            'employee_number' => $c->employee_snapshot['employee_number'] ?? null,
            'tax_number' => $c->employee_snapshot['tax_number'] ?? null,
            'client' => $c->bureauCompany?->name,
            'gross' => (float) $c->gross, 'paye' => (float) $c->paye,
            'issued_at' => $c->issued_at?->toDateTimeString(),
            'reissue_reason' => $c->reissue_reason,
        ]);

        return Inertia::render('Modules/HR/Payroll/TaxCertificates/Index', [
            'certificates' => $certificates,
            'clients' => BureauCompany::query()->where('is_active', true)->orderBy('name')->get(['uuid', 'name']),
            'years' => TaxCertificate::query()->distinct()->orderByDesc('tax_year')->pluck('tax_year'),
            'filters' => $request->only(['search', 'tax_year', 'client', 'type', 'include_superseded']),
        ]);
    }

    /** One certificate, with everything on it and the versions it replaced. */
    public function show(TaxCertificate $certificate): InertiaResponse
    {
        $history = TaxCertificate::query()
            ->forEmployee($certificate->employee_type, $certificate->employee_id)
            ->where('tax_year', $certificate->tax_year)
            ->where('period', $certificate->period)
            ->orderByDesc('version')
            ->get()
            ->map(fn (TaxCertificate $c) => [
                'uuid' => $c->uuid, 'version' => $c->version,
                'issued_at' => $c->issued_at?->toDateTimeString(),
                'reason' => $c->reissue_reason,
                'gross' => (float) $c->gross, 'paye' => (float) $c->paye,
                'current' => $c->id === $certificate->id,
            ]);

        return Inertia::render('Modules/HR/Payroll/TaxCertificates/Show', [
            'certificate' => [
                'uuid' => $certificate->uuid,
                'certificate_number' => $certificate->certificate_number,
                'type' => $certificate->type, 'version' => $certificate->version,
                'status' => $certificate->status,
                'tax_year' => $certificate->tax_year, 'period' => $certificate->period,
                'employee' => $certificate->employee_snapshot,
                'employer' => $certificate->employer_snapshot,
                'source_codes' => $certificate->source_codes,
                'gross' => (float) $certificate->gross, 'paye' => (float) $certificate->paye,
                'uif' => (float) $certificate->uif, 'eti' => (float) $certificate->eti,
                'retirement' => (float) $certificate->retirement,
                'medical' => (float) $certificate->medical,
                'fringe_benefits' => (float) $certificate->fringe_benefits,
                'periods_worked' => $certificate->periods_worked,
                'period_start' => $certificate->period_start?->toDateString(),
                'period_end' => $certificate->period_end?->toDateString(),
                'issued_at' => $certificate->issued_at?->toDateTimeString(),
                'reissue_reason' => $certificate->reissue_reason,
            ],
            'history' => $history,
        ]);
    }

    /** Issue (or reissue) every certificate for a client's tax year. */
    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Optional now: without a client this issues off the employer's own payroll. An
            // employer running payroll in-house had no way to issue a stored certificate at all —
            // its IRP5 was recomputed on every download, which is the defect this whole class
            // exists to fix, fixed for the bureau and never for them.
            'client'   => ['nullable', 'string', 'exists:bureau_companies,uuid'],
            'tax_year' => ['required', 'integer', 'between:2000,2100'],
            'half'     => ['nullable', 'in:02,08'],
            'reason'   => ['nullable', 'string', 'max:255'],
        ]);

        if (blank($data['client'] ?? null)) {
            return $this->generateForEmployer($data);
        }

        $company = BureauCompany::where('uuid', $data['client'])->firstOrFail();
        abort_unless($company->accessibleBy(Auth::user()), 403);

        $issued = $this->certificates->issueForClient(
            $company,
            (int) $data['tax_year'],
            $data['half'] ?? TaxCertificateService::FULL_YEAR,
            $data['reason'] ?? null,
        );

        $reissues = $issued->filter->isReissue()->count();

        $this->audit->client(
            $company,
            BureauAuditService::CERTIFICATES_GENERATED,
            "Tax certificates issued for {$company->name} ({$data['tax_year']}).",
            ['tax_year' => $data['tax_year'], 'issued' => $issued->count(), 'reissued' => $reissues],
        );

        return back()->with('success', $reissues > 0
            ? "{$issued->count()} certificate(s) issued, {$reissues} of them a new version."
            : "{$issued->count()} certificate(s) issued.");
    }

    /** Issue off the employer's own payroll rather than a bureau client's. */
    private function generateForEmployer(array $data): RedirectResponse
    {
        $issued = $this->certificates->issueForEmployer(
            (int) $data['tax_year'],
            $data['half'] ?? TaxCertificateService::FULL_YEAR,
            $data['reason'] ?? null,
        );

        $reissues = $issued->filter->isReissue()->count();

        return back()->with('success', $reissues > 0
            ? "{$issued->count()} certificate(s) issued, {$reissues} of them a new version."
            : "{$issued->count()} certificate(s) issued.");
    }

    /**
     * The certificate as a file, read back from what was issued.
     *
     * Not recomputed. That is the whole reason the figures are stored: an employee asking for a copy
     * in July must receive the document they filed their return with, not a fresh calculation off
     * data that has moved since.
     */
    public function download(TaxCertificate $certificate): Response
    {
        $lines = [
            $certificate->type . ' — ' . $certificate->certificate_number,
            'Tax year: ' . $certificate->tax_year . ' (period ' . $certificate->period . ')',
            'Version: ' . $certificate->version,
            '',
            'Employer: ' . ($certificate->employer_snapshot['name'] ?? ''),
            'PAYE reference: ' . ($certificate->employer_snapshot['paye_reference'] ?? ''),
            '',
            'Employee: ' . $certificate->employeeName(),
            'Employee number: ' . ($certificate->employee_snapshot['employee_number'] ?? ''),
            'ID number: ' . ($certificate->employee_snapshot['id_number'] ?? ''),
            'Tax number: ' . ($certificate->employee_snapshot['tax_number'] ?? ''),
            'Period worked: ' . $certificate->period_start?->toDateString() . ' to ' . $certificate->period_end?->toDateString(),
            '',
            'Source code,Amount',
        ];

        foreach ($certificate->source_codes ?? [] as $code => $amount) {
            $lines[] = $code . ',' . number_format((float) $amount, 2, '.', '');
        }

        $lines[] = '';
        $lines[] = 'Issued: ' . $certificate->issued_at?->toDateTimeString();

        return response(implode("\r\n", $lines) . "\r\n", 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="' . $certificate->certificate_number . '.txt"',
        ]);
    }

    /** Every current certificate matching the filter, as one CSV. */
    public function downloadAll(Request $request): Response
    {
        $certificates = TaxCertificate::query()
            ->when($request->input('tax_year'), fn ($q, $year) => $q->where('tax_year', $year))
            ->when($request->input('client'), fn ($q, $uuid) => $q->whereHas('bureauCompany', fn ($c) => $c->where('uuid', $uuid)))
            ->current()
            ->orderBy('certificate_number')
            ->get();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'Certificate number', 'Type', 'Version', 'Tax year', 'Employee', 'Employee number',
            'ID number', 'Tax number', 'Gross (3699)', 'PAYE (4102)', 'UIF (4141)',
            'Retirement (4001)', 'Medical (4005)', 'Incentive (7004)', 'Issued',
        ]);

        foreach ($certificates as $c) {
            fputcsv($handle, [
                $c->certificate_number, $c->type, $c->version, $c->tax_year,
                $c->employeeName(),
                $c->employee_snapshot['employee_number'] ?? '',
                $c->employee_snapshot['id_number'] ?? '',
                $c->employee_snapshot['tax_number'] ?? '',
                number_format((float) $c->gross, 2, '.', ''),
                number_format((float) $c->paye, 2, '.', ''),
                number_format((float) $c->uif, 2, '.', ''),
                number_format((float) $c->retirement, 2, '.', ''),
                number_format((float) $c->medical, 2, '.', ''),
                number_format((float) $c->eti, 2, '.', ''),
                $c->issued_at?->toDateTimeString(),
            ]);
        }

        rewind($handle);

        return response(stream_get_contents($handle), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="tax-certificates-' . ($request->input('tax_year') ?: 'all') . '.csv"',
        ]);
    }
}
