<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Services\PeerBenchmarks;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Peer benchmarks: the organisation's crops against anonymous peer bands, once it has opted in. */
class BenchmarkController extends AgriController
{
    public function index(Request $request, PeerBenchmarks $benchmarks): Response
    {
        $this->allow($request, R::VIEW_REPORTS);
        $farm = $this->farmFilter($request);

        return Inertia::render('Modules/Agri/Benchmarks', [
            'filters' => ['farm' => $farm?->uuid],
            'farms' => collect($this->farmOptions())->map(fn ($f) => ['id' => $f['id'], 'name' => $f['name']])->values(),
            'data' => $benchmarks->forCompany((int) $request->user()->company_id, $farm),
            'rules' => ['min_farms' => PeerBenchmarks::MIN_FARMS, 'min_orgs' => PeerBenchmarks::MIN_ORGS],
            'can' => ['opt' => $request->user()->can(R::MANAGE_SETTINGS)],
        ]);
    }

    public function optIn(Request $request, PeerBenchmarks $benchmarks): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SETTINGS);
        $on = (bool) $request->validate(['opt_in' => ['required', 'boolean']])['opt_in'];
        $benchmarks->optIn((int) $request->user()->company_id, $on, $request->user());

        return back()->with('success', $on ? 'Your organisation now takes part in peer benchmarks; its figures count from the next weekly run.'
            : 'Your organisation no longer takes part; its figures leave the bands at the next weekly run.');
    }
}
