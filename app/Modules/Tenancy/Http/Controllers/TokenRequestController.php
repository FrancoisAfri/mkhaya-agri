<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Modules\Tenancy\Models\CompanyMonthlyCredit;
use App\Modules\Tenancy\Models\CompanyStorageCredit;
use App\Modules\Tenancy\Models\TokenRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Platform-admin review of company token/SMS requests, direct grants, and the
 * AI/SMS enforcement-mode toggle (strict vs grace).
 */
class TokenRequestController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenancy/TokenRequests', [
            'requests' => TokenRequest::with('company:id,name,plan')
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
                ->latest('id')->limit(200)->get()
                ->map(fn (TokenRequest $r) => [
                    'id' => $r->id, 'company' => $r->company?->name, 'company_id' => $r->company_id,
                    'type' => $r->type, 'amount' => $r->amount, 'mode' => $r->mode,
                    'total_price' => $r->total_price !== null ? (float) $r->total_price : null,
                    'status' => $r->status, 'note' => $r->note,
                    'created_at' => $r->created_at?->format('Y-m-d H:i'),
                    'decided_at' => $r->decided_at?->format('Y-m-d H:i'),
                ]),
            'enforcement' => AppSetting::get('ai_quota_enforcement', 'strict'),
            'grace_percent' => (int) config('ai.quota.grace_percent', 10),
        ]);
    }

    /** Approve a pending request — grants the credit for this month. */
    public function approve(TokenRequest $tokenRequest)
    {
        abort_if($tokenRequest->status !== 'pending', 422, 'Request already decided.');

        if ($tokenRequest->type === 'storage') {
            // Storage requests are in GB and grant a permanent, cumulative credit.
            CompanyStorageCredit::grantGb($tokenRequest->company_id, (int) $tokenRequest->amount, 'request', 'Approved request', Auth::id());
        } else {
            $source = $tokenRequest->mode === 'subscription' ? 'subscription' : 'grant';
            CompanyMonthlyCredit::grant(
                $tokenRequest->company_id, $tokenRequest->type, (int) $tokenRequest->amount,
                $source, $tokenRequest->id, 'Approved request', Auth::id()
            );
        }

        $tokenRequest->update(['status' => 'approved', 'decided_by' => Auth::id(), 'decided_at' => now()]);

        return back()->with('success', 'Request approved and credit granted.');
    }

    public function reject(TokenRequest $tokenRequest)
    {
        abort_if($tokenRequest->status !== 'pending', 422, 'Request already decided.');
        $tokenRequest->update(['status' => 'rejected', 'decided_by' => Auth::id(), 'decided_at' => now()]);

        return back()->with('success', 'Request rejected.');
    }

    /** Direct grant to any company (no prior request). */
    public function grant(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'type' => ['required', 'in:ai,sms,storage'],
            'amount' => ['required', 'integer', 'min:1'], // storage: amount is in GB
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $tr = TokenRequest::create([
            'company_id' => $data['company_id'], 'type' => $data['type'], 'amount' => $data['amount'],
            'mode' => 'request', 'status' => 'approved', 'note' => $data['note'] ?? 'Admin grant',
            'requested_by' => Auth::id(), 'decided_by' => Auth::id(), 'decided_at' => now(),
        ]);
        if ($data['type'] === 'storage') {
            CompanyStorageCredit::grantGb($data['company_id'], (int) $data['amount'], 'admin', $data['note'] ?? 'Admin grant', Auth::id());
        } else {
            CompanyMonthlyCredit::grant($data['company_id'], $data['type'], $data['amount'], 'grant', $tr->id, $data['note'] ?? 'Admin grant', Auth::id());
        }

        return back()->with('success', 'Credit granted.');
    }

    /** Toggle enforcement mode between strict and grace. */
    public function enforcement(Request $request)
    {
        $data = $request->validate(['mode' => ['required', 'in:strict,grace']]);
        AppSetting::set('ai_quota_enforcement', $data['mode']);

        return back()->with('success', "Enforcement set to {$data['mode']}.");
    }
}
