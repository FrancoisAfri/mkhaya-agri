<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Services\FarmAlerts;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FarmAlertController extends AgriController
{
    public function index(Request $request, FarmAlerts $alerts): Response
    {
        $this->allow($request, R::VIEW_FARMS);
        $alerts->scanIfStale((int) $request->user()->company_id);
        $farm = $this->farmFilter($request);
        $status = $request->query('status', 'open');

        return Inertia::render('Modules/Agri/Alerts', [
            'alerts' => FarmAlert::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
                ->when($status === 'open', fn ($q) => $q->whereIn('status', [FarmAlert::OPEN, FarmAlert::ACKNOWLEDGED]))
                ->when($status === 'resolved', fn ($q) => $q->where('status', FarmAlert::RESOLVED))
                ->with('farm')->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->orderBy('due_on')
                ->limit(300)->get()
                ->map(fn ($a) => ['id' => $a->uuid, 'kind' => $a->kind, 'severity' => $a->severity, 'title' => $a->title, 'message' => $a->message,
                    'link' => $a->link, 'due_on' => $a->due_on?->toDateString(), 'status' => $a->status, 'farm' => $a->farm?->name,
                    'last_seen_at' => $a->last_seen_at?->diffForHumans()]),
            'filters' => ['farm' => $farm?->uuid, 'status' => $status],
            'farms' => $this->farmOptions(),
        ]);
    }

    public function acknowledge(Request $request, FarmAlert $alert): RedirectResponse
    {
        $this->allow($request, R::VIEW_FARMS);
        $alert->update(['status' => FarmAlert::ACKNOWLEDGED, 'acknowledged_by' => $request->user()->id, 'acknowledged_at' => now()]);

        return back()->with('success', 'Alert acknowledged. It closes by itself when the condition clears.');
    }

    public function scan(Request $request, FarmAlerts $alerts): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FARMS, R::VIEW_REPORTS);
        $r = $alerts->scan((int) $request->user()->company_id);

        return back()->with('success', "Checked {$r['farms']} farm(s): {$r['raised']} new alert(s), {$r['resolved']} cleared.");
    }
}
