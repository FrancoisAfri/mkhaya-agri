<?php

namespace App\Modules\HR\Payroll\Controllers\Bureau;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Services\Bureau\BureauCompanyService;
use App\Modules\HR\Payroll\Services\Bureau\BureauOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll-bureau agent dashboard: the agent's client companies. Access is scoped
 * to the agent; the bureau module must be active (route middleware).
 */
class BureauCompanyController extends Controller
{
    public function __construct(
        private readonly BureauCompanyService $companies,
        private readonly BureauOperationsService $operations,
    ) {
    }

    public function index(): Response
    {
        $agent = Auth::user();
        $clients = $this->companies->forAgent($agent);

        return Inertia::render('Modules/HR/Payroll/Bureau/Dashboard', [
            'companies' => $clients->map(fn (BureauCompany $c) => $this->summary($c)),
            // What is outstanding across every client at once. A list of client cards answers "who
            // are my clients", which is a question nobody has; this answers the one a bureau opens
            // the system with — and which used to be answerable only by opening forty clients in
            // turn and remembering what you saw.
            'work'      => $this->operations->overview($clients),
            'frequencies' => BureauCompany::FREQUENCIES,
            'countries' => array_keys(BureauCompany::COUNTRIES),
            // A bureau running both moves between them all day; the link only appears where the
            // module is actually bought.
            'leaveBureau' => ['available' => false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->companies->create(Auth::user(), $this->validated($request));

        return redirect()->route('payroll.bureau.companies.show', $company->uuid)
            ->with('success', 'Company added.');
    }

    public function show(BureauCompany $company): Response
    {
        $this->authorizeAccess($company);

        return Inertia::render('Modules/HR/Payroll/Bureau/Company', [
            'company'     => $this->detail($company),
            'frequencies' => BureauCompany::FREQUENCIES,
            // Leave Bureau is its own module and a client may have payroll without it, so the
            // screen is told whether there is anywhere to send somebody rather than offering a
            // link that leads to a 404.
            'leave'       => [
                'available' => false,
                'active'    => false,
            ],
        ]);
    }

    public function update(Request $request, BureauCompany $company): RedirectResponse
    {
        $this->authorizeAccess($company);
        $this->companies->update($company, $this->validated($request, false));

        return back()->with('success', 'Company updated.');
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name'                => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'trading_name'        => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'paye_reference'      => ['nullable', 'string', 'max:30'],
            'uif_reference'       => ['nullable', 'string', 'max:30'],
            'sdl_reference'       => ['nullable', 'string', 'max:30'],
            'contact_name'        => ['nullable', 'string', 'max:255'],
            'contact_email'       => ['nullable', 'email', 'max:255'],
            'contact_phone'       => ['nullable', 'string', 'max:40'],
            'address'             => ['nullable', 'string', 'max:1000'],
            'pay_frequency'       => ['nullable', 'in:monthly,fortnightly,weekly'],
            'tax_year'            => ['nullable', 'integer', 'between:2000,2100'],
            'is_active'           => ['sometimes', 'boolean'],
            'bank'                => ['nullable', 'string', 'in:'.implode(',', \App\Modules\HR\Payroll\Support\SaBanks::codes())],
            'bank_account_name'   => ['nullable', 'string', 'max:120'],
            'bank_account_number' => ['nullable', 'string', 'max:30'],
            'bank_branch_code'    => ['nullable', 'string', 'max:15'],
            'bank_user_code'      => ['nullable', 'string', 'max:15'],
            'auto_email_payslips'  => ['sometimes', 'boolean'],
            'country'             => ['nullable', 'string', 'in:'.implode(',', array_keys(\App\Modules\HR\Payroll\Models\Bureau\BureauCompany::COUNTRIES))],
        ]);
    }

    private function authorizeAccess(BureauCompany $company): void
    {
        abort_unless($company->accessibleBy(Auth::user()), 403);
    }

    private function summary(BureauCompany $c): array
    {
        return [
            'uuid'          => $c->uuid,
            'name'          => $c->name,
            'trading_name'  => $c->trading_name,
            'pay_frequency' => $c->pay_frequency,
            'is_active'     => $c->is_active,
            'paye_reference' => $c->paye_reference,
        ];
    }

    private function detail(BureauCompany $c): array
    {
        return array_merge($this->summary($c), [
            'registration_number' => $c->registration_number,
            'uif_reference'       => $c->uif_reference,
            'sdl_reference'       => $c->sdl_reference,
            'contact_name'        => $c->contact_name,
            'contact_email'       => $c->contact_email,
            'contact_phone'       => $c->contact_phone,
            'address'             => $c->address,
            'tax_year'            => $c->tax_year,
            'bank'                => $c->bank,
            'bank_account_name'   => $c->bank_account_name,
            'bank_account_number' => $c->bank_account_number,
            'bank_branch_code'    => $c->bank_branch_code,
            'bank_user_code'      => $c->bank_user_code,
            'auto_email_payslips'  => $c->auto_email_payslips,
            'country'             => $c->country,
            'currency'            => $c->currency,
            'countries'           => array_keys(\App\Modules\HR\Payroll\Models\Bureau\BureauCompany::COUNTRIES),
            'banks'               => \App\Modules\HR\Payroll\Support\SaBanks::options(),
        ]);
    }
}
