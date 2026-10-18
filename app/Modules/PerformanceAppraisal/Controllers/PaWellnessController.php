<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaBehaviorSignal;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaWellnessScore;
use App\Modules\PerformanceAppraisal\Services\PaWellnessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaWellnessController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(private PaWellnessService $wellness) {}

    public function index(Request $request): Response
    {
        $cycleId = $request->query('cycle_id');

        $latestScores = PaWellnessScore::with('employee')
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->whereIn('id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('pa_wellness_scores')
                    ->groupBy('employee_id');
            })
            ->orderBy('wellness_score')
            ->get()
            ->map(fn($s) => [
                'id'                  => $s->id,
                'employee_id'         => $s->employee_id,
                'employee_name'       => $this->personName($s->employee),
                'wellness_score'      => $s->wellness_score,
                'wellness_grade'      => $s->wellness_grade,
                'burnout_risk'        => $s->burnout_risk,
                'disengagement_level' => $s->disengagement_level,
                'kpi_score'           => $s->kpi_score,
                'feedback_score'      => $s->feedback_score,
                'checkin_score'       => $s->checkin_score,
                'engagement_score'    => $s->engagement_score,
                'signals'             => $s->signals ?? [],
                'created_at'          => $s->created_at->format('d M Y H:i'),
            ]);

        $signals = PaBehaviorSignal::with('employee')
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->whereNull('acknowledged_at')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($s) => [
                'id'            => $s->id,
                'employee_id'   => $s->employee_id,
                'employee_name' => $this->personName($s->employee),
                'signal_type'   => $s->signal_type,
                'severity'      => $s->severity,
                'title'         => $s->title,
                'description'   => $s->description,
                'data'          => $s->data,
                'created_at'    => $s->created_at->format('d M Y'),
            ]);

        return Inertia::render('Modules/PerformanceAppraisal/Wellness/Index', [
            'wellnessScores' => $latestScores,
            'signals'        => $signals,
            'cycles'         => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees'      => $this->personList(),
            'selectedCycle'  => $cycleId ? (int) $cycleId : null,
            'stats'          => [
                'avg_score'     => round($latestScores->avg('wellness_score') ?? 0, 1),
                'critical_count'=> $signals->where('severity', 'critical')->count(),
                'high_risk'     => $latestScores->whereIn('burnout_risk', ['high', 'critical'])->count(),
                'total_signals' => $signals->count(),
            ],
        ]);
    }

    public function generateScore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:users,id'],
            'cycle_id'    => ['nullable', 'exists:pa_cycles,id'],
        ]);

        $this->wellness->generateScore($data['employee_id'], $data['cycle_id'] ?? null, $request->user()->id);

        return back()->with('success', 'Wellness score generated.');
    }

    public function generateAllScores(Request $request): RedirectResponse
    {
        $cycleId = $request->input('cycle_id');
        $userId  = $request->user()->id;

        User::chunk(50, function ($users) use ($cycleId, $userId) {
            foreach ($users as $user) {
                $this->wellness->generateScore($user->id, $cycleId, $userId);
            }
        });

        return back()->with('success', 'Wellness scores generated for all employees.');
    }

    public function detectSignals(Request $request): RedirectResponse
    {
        $data  = $request->validate(['cycle_id' => ['required', 'exists:pa_cycles,id']]);
        $cycle = PaCycle::findOrFail($data['cycle_id']);

        $this->wellness->detectSignalsForCycle($cycle, $request->user()->id);

        return back()->with('success', 'Signal detection complete.');
    }

    public function managerInsights(Request $request): JsonResponse
    {
        $cycleId = $request->query('cycle_id');
        $text    = $this->wellness->generateManagerInsights($request->user()->id, $cycleId ? (int) $cycleId : null);

        return response()->json(['insights' => $text]);
    }

    public function acknowledgeSignal(PaBehaviorSignal $signal, Request $request): RedirectResponse
    {
        $this->wellness->acknowledgeSignal($signal, $request->user()->id);

        return back()->with('success', 'Signal acknowledged.');
    }

    /**

     * Everybody this tenant may choose between.

     *

     * Was built here, in two different shapes across these controllers — some through

     * `User`, which leans on a global scope that steps aside for a platform administrator,

     * and some as a raw `DB::table('users')` where no scope applied at all and `users`

     * carries no row-level security to catch it. One of them offered every tenant's people

     * to every other tenant; the ones reading `hr_people` offered almost nobody.

     */

    private function personList(): \Illuminate\Support\Collection

    {

        return collect($this->tenantPeopleOptions());

    }

    private function personName(?User $user): string
    {
        if (!$user) return '-';
        $first   = $user->person?->first_name ?? '';
        $surname = $user->person?->surname      ?? '';
        $full    = trim("$first $surname");
        return $full ?: $user->email;
    }
}
