<?php

namespace App\Modules\HR\Payroll\Controllers\Bureau;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauPaymentIntegration;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Services\Bureau\DisbursementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Real-time salary payout integrations + submitting a run for disbursement. */
class BureauPayoutController extends Controller
{
    public function __construct(private readonly DisbursementService $payouts)
    {
    }

    public function index(BureauCompany $company): Response
    {
        $this->gate($company);

        return Inertia::render('Modules/HR/Payroll/Bureau/Payouts', [
            'company'      => ['uuid' => $company->uuid, 'name' => $company->name],
            'providers'    => BureauPaymentIntegration::PROVIDERS,
            'integrations' => BureauPaymentIntegration::where('bureau_company_id', $company->id)->get()
                ->map(fn ($i) => [
                    'uuid' => $i->uuid, 'provider' => $i->provider, 'label' => $i->label,
                    'base_url' => $i->base_url, 'sandbox' => $i->sandbox, 'active' => $i->active,
                    'configured' => $i->hasCredentials(), 'last_status' => $i->last_sync_status,
                    'last_message' => $i->last_sync_message,
                ]),
            'disbursements' => $company->payRuns()->with('payslips')->get()->isEmpty() ? [] :
                \App\Modules\HR\Payroll\Models\Bureau\BureauDisbursement::where('bureau_company_id', $company->id)
                    ->latest()->limit(50)->get()
                    ->map(fn ($d) => ['uuid' => $d->uuid, 'reference' => $d->reference, 'provider' => $d->provider, 'status' => $d->status, 'count' => $d->count, 'total' => (float) $d->total_amount, 'at' => $d->created_at?->toDayDateTimeString()]),
        ]);
    }

    public function connect(Request $request, BureauCompany $company): RedirectResponse
    {
        $this->gate($company);
        $data = $request->validate([
            'provider'   => ['required', 'in:'.implode(',', BureauPaymentIntegration::PROVIDERS)],
            'base_url'   => ['required', 'url', 'max:255'],
            'api_key'    => ['nullable', 'string'],
            'api_secret' => ['nullable', 'string'],
            'sandbox'    => ['sometimes', 'boolean'],
            'label'      => ['nullable', 'string', 'max:60'],
        ]);
        $this->payouts->connect($company, $data);

        return back()->with('success', 'Payout provider saved.');
    }

    public function toggle(BureauCompany $company, BureauPaymentIntegration $integration): RedirectResponse
    {
        $this->gate($company);
        abort_unless($integration->bureau_company_id === $company->id, 404);
        $this->payouts->setActive($integration, ! $integration->active);

        return back()->with('success', $integration->active ? 'Provider activated.' : 'Provider deactivated.');
    }

    public function test(BureauCompany $company, BureauPaymentIntegration $integration): RedirectResponse
    {
        $this->gate($company);
        abort_unless($integration->bureau_company_id === $company->id, 404);
        $result = $this->payouts->testConnection($integration);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function submit(BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);
        $d = $this->payouts->submitRun($run);

        return back()->with('success', "Submitted {$d->count} payout(s), total R ".number_format((float) $d->total_amount, 2).' ('.$d->status.').');
    }

    /** Poll the provider for the latest status of a disbursement batch. */
    public function refresh(BureauCompany $company, \App\Modules\HR\Payroll\Models\Bureau\BureauDisbursement $disbursement): RedirectResponse
    {
        $this->gate($company);
        abort_unless($disbursement->bureau_company_id === $company->id, 404);
        try {
            $d = $this->payouts->refreshStatus($disbursement);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Status: '.$d->status.'.');
    }

    private function gate(BureauCompany $company): void
    {
        abort_unless($company->accessibleBy(Auth::user()), 403);
    }
}
