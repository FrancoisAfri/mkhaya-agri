<?php

namespace App\Modules\Finance\Sars\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\Finance\Sars\Models\SarsTaxConfig;
use App\Modules\Finance\Sars\Services\Vat201Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * VAT201 workspace: pick a period, see the return computed live from the ledger with its
 * validation result, then generate a stored, versioned submission. Tenant-scoped through the
 * service + models (company_id + RLS).
 */
class SarsVat201Controller extends Controller
{
    public function __construct(private Vat201Service $vat201)
    {
    }

    public function index(Request $request): Response
    {
        $config = SarsTaxConfig::current();
        $default = $this->vat201->defaultPeriod($config->vat_frequency);

        $from = $request->filled('from') ? $request->date('from')->toDateString() : $default['from'];
        $to   = $request->filled('to') ? $request->date('to')->toDateString() : $default['to'];

        $return = $this->vat201->build($from, $to);

        $recent = SarsSubmission::ownReturns()->where('tax_type', 'vat201')->orderByDesc('id')->limit(8)->get()
            ->map(fn (SarsSubmission $s) => [
                'id'           => $s->id,
                'reference'    => $s->reference,
                'period'       => $s->period,
                'status'       => $s->status,
                'version'      => $s->version,
                'net'          => $s->totals['net'] ?? null,
                'is_payable'   => $s->totals['is_payable'] ?? null,
                'generated_at' => $s->generated_at?->toDateString(),
            ]);

        return Inertia::render('Modules/Finance/Sars/Vat201', [
            'vat'       => $return,
            'selected'  => ['from' => $from, 'to' => $to],
            'frequency' => $config->vat_frequency,
            'configured' => (bool) $config->vat_registered && filled($config->vat_number),
            'recent'    => $recent,
            'statuses'  => SarsSubmission::STATUSES,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from'  => 'required|date',
            'to'    => 'required|date|after_or_equal:from',
            'notes' => 'nullable|string|max:2000',
        ]);

        $submission = $this->vat201->generate($data['from'], $data['to'], $data['notes'] ?? null);

        return redirect()
            ->route('finance.accounting.sars.submissions.show', $submission->id)
            ->with('success', "VAT201 {$submission->reference} generated (v{$submission->version}).");
    }
}
