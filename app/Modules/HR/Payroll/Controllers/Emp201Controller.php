<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\HR\Payroll\Services\Emp201Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EMP201 workspace (Payroll): pick a tax month, see the monthly PAYE/SDL/UIF declaration built
 * live from finalised payroll with its validation result, then generate a stored, versioned
 * submission that lands in the shared SARS submission history. Tenant-scoped via the service.
 */
class Emp201Controller extends Controller
{
    public function __construct(private Emp201Service $emp201)
    {
    }

    public function index(Request $request): Response
    {
        $month = $request->filled('month') ? $request->string('month')->value() : $this->emp201->defaultMonth();
        // Guard against a malformed month param.
        if (! preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            $month = $this->emp201->defaultMonth();
        }

        $emp201 = $this->emp201->build($month);

        /*
         * What was actually filed for this month, and whether payroll still agrees with it.
         *
         * The figures above are rebuilt from live payslips on every page load, which is right up
         * until the return is filed. After that they are a different thing entirely: a correction
         * to a payslip, a reversal, a late run landing in the month, all move the live number while
         * the return SARS holds stays where it was. The screen showed only the live figure, so the
         * two silently diverged and the only way to notice was to open the stored PDF.
         *
         * The filed version is shown alongside it, with the difference named. A difference is not
         * an error — it is the signal that this month needs an amendment.
         */
        $filed = SarsSubmission::ownReturns()->where('tax_type', 'emp201')->where('period', $month)
            ->orderByDesc('version')->first();

        $drift = null;
        if ($filed) {
            $was = (float) ($filed->totals['total_payable'] ?? 0);
            $now = (float) ($emp201['totals']['total_payable'] ?? 0);

            $drift = [
                'reference'   => $filed->reference,
                'version'     => $filed->version,
                'status'      => $filed->status,
                'filed_total' => $was,
                'live_total'  => $now,
                'difference'  => round($now - $was, 2),
                // Rounding noise is not drift. A cent either way on an aggregate of hundreds of
                // payslips would otherwise put a warning on the screen every month.
                'differs'     => abs($now - $was) >= 0.01,
            ];
        }

        $recent = SarsSubmission::ownReturns()->where('tax_type', 'emp201')->orderByDesc('id')->limit(8)->get()
            ->map(fn (SarsSubmission $s) => [
                'id'            => $s->id,
                'reference'     => $s->reference,
                'period'        => $s->period,
                'status'        => $s->status,
                'version'       => $s->version,
                'total_payable' => $s->totals['total_payable'] ?? null,
                'generated_at'  => $s->generated_at?->toDateString(),
            ]);

        return Inertia::render('Modules/HR/Payroll/Emp201', [
            'emp201'   => $emp201,
            'month'    => $month,
            'recent'   => $recent,
            'filed'    => $drift,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'notes' => 'nullable|string|max:2000',
        ]);

        $submission = $this->emp201->generate($data['month'], $data['notes'] ?? null);

        return redirect()
            ->route('finance.accounting.sars.submissions.show', $submission->id)
            ->with('success', "EMP201 {$submission->reference} generated (v{$submission->version}).");
    }
}
