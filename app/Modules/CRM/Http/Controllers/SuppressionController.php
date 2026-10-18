<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmSuppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marketing suppression list (unsubscribes / bounces) — POPIA / GDPR opt-out
 * compliance. Suppressed addresses are skipped by campaign sends.
 */
class SuppressionController extends Controller
{
    public function index(Request $request): Response
    {
        $suppressions = CrmSuppression::latest()->limit(1000)->get()
            ->map(fn ($s) => [
                'id'      => $s->id,
                'channel' => $s->channel,
                'address' => $s->address,
                'reason'  => $s->reason,
                'at'      => $s->created_at?->toDateString(),
            ]);

        return Inertia::render('Modules/CRM/Suppressions', [
            'suppressions' => $suppressions,
            'count'        => CrmSuppression::count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'channel' => 'required|in:email,whatsapp',
            'address' => 'required|string|max:200',
            'reason'  => 'nullable|in:unsubscribed,bounced,complained,manual',
        ]);

        CrmSuppression::suppress($data['channel'], $data['address'], $data['reason'] ?? 'manual', null, Auth::id());

        return back()->with('success', 'Address suppressed.');
    }

    public function destroy(CrmSuppression $suppression): RedirectResponse
    {
        $suppression->delete();

        return back()->with('success', 'Address removed from the suppression list.');
    }
}
