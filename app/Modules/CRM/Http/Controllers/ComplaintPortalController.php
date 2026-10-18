<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Setup\Models\CompanyIdentity;
use App\Modules\CRM\Services\CrmComplaintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public customer feedback portal (no auth). Clients submit a complaint or
 * compliment; AI then grades it, identifies the employee involved and routes it.
 * Branding (name + logo) comes from the company identity (settings/setup).
 */
class ComplaintPortalController extends Controller
{
    public function show(): Response
    {
        $identity = CompanyIdentity::systemSettings();

        return Inertia::render('Modules/CRM/Portal/Complaint', [
            'company' => $identity['full_company_name'] ?: $identity['company_name'],
            'logo'    => $identity['company_logo_url'] ?: null,
        ]);
    }

    public function store(Request $request, CrmComplaintService $complaints): RedirectResponse
    {
        $data = $request->validate([
            'subject'        => 'required|string|max:250',
            'body'           => 'required|string|min:10|max:8000',
            'customer_name'  => 'required|string|max:150',
            'customer_email' => 'required|email|max:190',
            'customer_phone' => 'nullable|string|max:40',
        ]);

        $complaint = $complaints->create($data, 'portal');

        return back()->with('success', 'Thank you — your feedback has been logged (reference '.$complaint->reference.').');
    }
}
