<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Services\CompanyProvisioner;
use App\Modules\Tenancy\Services\Edition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service tenant signup (Phase B). Only available in the SaaS edition — a
 * self-hosted instance has exactly one company (the default) and no signup.
 */
class RegisterCompanyController extends Controller
{
    public function __construct(private CompanyProvisioner $provisioner) {}

    public function show(): Response|RedirectResponse
    {
        abort_unless(Edition::isSaas(), 404);

        return Inertia::render('Tenancy/Signup', ['trialDays' => (int) config('edition.trial_days', 14)]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Edition::isSaas(), 404);

        $data = $request->validate([
            'company_name' => 'required|string|max:150',
            'admin_name' => 'required|string|max:150',
            'email' => 'required|email|max:190|unique:users,email',
            'phone' => 'nullable|string|max:40',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $result = $this->provisioner->provision($data);
        Auth::login($result['user']);

        return redirect()->route('dashboard')->with('success', "Welcome to Mkhaya — your {$result['company']->name} workspace is ready. Your free trial runs for ".config('edition.trial_days', 14).' days.');
    }
}
