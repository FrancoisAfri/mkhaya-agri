<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage per-user sales targets / quotas. Managers set weekly and monthly goals
 * for each metric (calls, emails, meetings, leads, deals won, revenue) which the
 * performance dashboards then measure actuals against.
 */
class CrmTargetController extends Controller
{
    public function index(Request $request): Response
    {
        $users = DB::table('users')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->select('users.id', DB::raw("CONCAT(hr_people.first_name, ' ', hr_people.surname) as name"))
            ->orderBy('hr_people.first_name')
            ->limit(1000)
            ->get();

        $names = $users->pluck('name', 'id');

        // Per-user, per-period target map (for loading into the editor).
        $targets = CrmTarget::all()->groupBy('user_id')->map(
            fn ($rows) => $rows->groupBy('period')->map(fn ($r) => $r->pluck('target_value', 'metric'))
        );

        // Flat management rows — one per (user, period) that has any target set.
        $rows = CrmTarget::all()
            ->groupBy(fn ($t) => $t->user_id.'|'.$t->period)
            ->map(function ($group) use ($names) {
                $first = $group->first();
                return [
                    'user_id' => $first->user_id,
                    'name'    => $names[$first->user_id] ?? ('User #'.$first->user_id),
                    'period'  => $first->period,
                    'values'  => $group->pluck('target_value', 'metric'),
                ];
            })
            ->sortBy([['name', 'asc'], ['period', 'asc']])
            ->values();

        return Inertia::render('Modules/CRM/Targets', [
            'users'   => $users,
            'targets' => $targets,
            'rows'    => $rows,
            'metrics' => CrmTarget::METRICS,
            'periods' => CrmTarget::PERIODS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id'        => 'required|exists:users,id',
            'period'         => 'required|in:weekly,monthly',
            'targets'        => 'required|array',
            'targets.*'      => 'nullable|numeric|min:0',
        ]);

        foreach ($data['targets'] as $metric => $value) {
            if (! array_key_exists($metric, CrmTarget::METRICS)) {
                continue;
            }
            CrmTarget::updateOrCreate(
                ['user_id' => $data['user_id'], 'period' => $data['period'], 'metric' => $metric],
                ['target_value' => (float) ($value ?? 0), 'created_by' => Auth::id()],
            );
        }

        return back()->with('success', 'Targets saved.');
    }

    /** Delete all targets for a user + period (one row in the management table). */
    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer',
            'period'  => 'required|in:weekly,monthly',
        ]);

        CrmTarget::where('user_id', $data['user_id'])->where('period', $data['period'])->delete();

        return back()->with('success', 'Targets removed.');
    }
}
