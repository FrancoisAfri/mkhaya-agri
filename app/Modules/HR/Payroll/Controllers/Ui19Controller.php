<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\HR\Payroll\Services\Ui19Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UI-19 workspace (Payroll): pick a tax month, see the monthly UIF declaration built live from
 * finalised payroll (employees, remuneration, contributions, engagements & terminations) with
 * its validation result, then generate a versioned submission into the shared SARS history.
 */
class Ui19Controller extends Controller
{
    public function __construct(private Ui19Service $ui19)
    {
    }

    public function index(Request $request): Response
    {
        $month = $request->filled('month') ? $request->string('month')->value() : $this->ui19->defaultMonth();
        if (! preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            $month = $this->ui19->defaultMonth();
        }

        $data = $this->ui19->build($month);

        $recent = SarsSubmission::ownReturns()->where('tax_type', 'ui19')->orderByDesc('id')->limit(8)->get()
            ->map(fn (SarsSubmission $s) => [
                'id'             => $s->id,
                'reference'      => $s->reference,
                'period'         => $s->period,
                'status'         => $s->status,
                'version'        => $s->version,
                'employee_count' => $s->totals['employee_count'] ?? null,
                'generated_at'   => $s->generated_at?->toDateString(),
            ]);

        return Inertia::render('Modules/HR/Payroll/Ui19', [
            'ui19'   => $data,
            'month'  => $month,
            'recent' => $recent,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'notes' => 'nullable|string|max:2000',
        ]);

        $submission = $this->ui19->generate($data['month'], $data['notes'] ?? null);

        return redirect()
            ->route('finance.accounting.sars.submissions.show', $submission->id)
            ->with('success', "UI-19 {$submission->reference} generated (v{$submission->version}).");
    }
}
