<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaTalentProfile;
use App\Modules\PerformanceAppraisal\Models\PaLearningRecommendation;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Services\PaTalentGrowthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaTalentGrowthController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(private PaTalentGrowthService $service) {}

    public function index(Request $request): Response
    {
        $cycleId = $request->integer('cycle_id') ?: null;

        $profiles = PaTalentProfile::query()
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->orderByDesc('composite_score')
            ->get()
            ->map(function ($p) {
                $p->employee_name = $this->resolveEmployeeName($p->employee_id);
                $p->assessed_at   = $p->assessed_at?->toDateString();
                return $p;
            });

        $recommendations = PaLearningRecommendation::query()
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('employee_id')
            ->get()
            ->map(function ($r) {
                $r->employee_name = $this->resolveEmployeeName($r->employee_id);
                return $r;
            });

        $stats = [
            'top_talent'          => $profiles->where('talent_tier', 'top_talent')->count(),
            'high_performers'     => $profiles->where('talent_tier', 'high_performer')->count(),
            'promotion_ready'     => $profiles->whereIn('promotion_readiness_level', ['ready', 'ready_now'])->count(),
            'leadership_pipeline' => $profiles->where('is_leadership_pipeline', true)->count(),
        ];

        return Inertia::render('Modules/PerformanceAppraisal/TalentGrowth/Index', [
            'profiles'        => $profiles->values(),
            'recommendations' => $recommendations->values(),
            'cycles'          => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees'       => $this->personList(),
            'selectedCycle'   => $cycleId,
            'stats'           => $stats,
        ]);
    }

    public function buildAllProfiles(Request $request): RedirectResponse
    {
        $cycleId = $request->integer('cycle_id') ?: null;

        foreach ($this->tenantPeopleIds($request) as $userId) {
            try {
                $this->service->buildTalentProfile($userId, $cycleId);
            } catch (\Throwable) {
                continue;
            }
        }

        return back();
    }

    public function buildProfile(Request $request, int $employee): RedirectResponse
    {
        $cycleId = $request->integer('cycle_id') ?: null;
        $this->service->buildTalentProfile($employee, $cycleId);
        return back();
    }

    public function skillGaps(int $employee): JsonResponse
    {
        return response()->json(['gaps' => $this->service->detectSkillGaps($employee)]);
    }

    public function generateRecommendations(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'         => 'required|integer',
            'development_plan_id' => 'nullable|integer',
            'cycle_id'            => 'nullable|integer',
        ]);

        $this->service->generateLearningRecommendations(
            (int)  $data['employee_id'],
            isset($data['development_plan_id']) ? (int) $data['development_plan_id'] : null,
            isset($data['cycle_id'])            ? (int) $data['cycle_id']            : null,
        );

        return back();
    }

    public function successionRecommendation(Request $request, int $employee): JsonResponse
    {
        $cycleId = $request->integer('cycle_id') ?: null;
        $text    = $this->service->generateSuccessionRecommendation($employee, $cycleId);
        return response()->json(['recommendation' => $text]);
    }

    public function recommendationUpdate(Request $request, PaLearningRecommendation $recommendation): RedirectResponse
    {
        $data = $request->validate([
            'status'   => 'sometimes|in:pending,in_progress,completed,skipped',
            'priority' => 'sometimes|in:low,medium,high,critical',
        ]);
        $recommendation->update($data);
        return back();
    }

    public function recommendationDestroy(PaLearningRecommendation $recommendation): RedirectResponse
    {
        $recommendation->delete();
        return back();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveEmployeeName(int $id): string
    {
        return $this->tenantPersonName($id);
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

  private function personList(): array

  {

      return $this->tenantPeopleOptions();

  }
}
