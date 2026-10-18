<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\CommandCentreService;
use App\Modules\Operations\Sheq\Services\SiteRiskScoreService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The live SHEQ command centre (CMD-1 to CMD-8).
 *
 * Everything the opening screen needs arrives in one request. A dashboard that loads its tiles and
 * then fetches its indicators is a dashboard that is half-drawn at the moment somebody reads it,
 * and on a screen whose whole purpose is "what is happening now" a half-drawn state is worse than
 * a slow one.
 *
 * The drill-down (CMD-2) is the exception and is deliberately a separate JSON endpoint: it is
 * seven levels deep, nobody opens all seven, and loading the bottom of it eagerly would mean
 * running the compliance engine for every worker on the estate to render a page showing eleven
 * numbers.
 */
class CommandCentreController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(
        private readonly CommandCentreService $centre,
        private readonly SiteRiskScoreService $siteRisk,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $siteId = $this->permittedSite($request, $companyId);
        $period = $request->string('period')->toString() ?: now()->format('Y-m');

        return Inertia::render('Modules/Sheq/Registers/CommandCentre', [
            'noCompany' => $companyId === null,
            'siteId' => $siteId,
            'period' => $period,
            'board' => $companyId === null ? null : $this->centre->board($companyId, $siteId),
            'indicators' => $companyId === null ? null
                : $this->centre->indicators($companyId, $siteId),
            'heatmap' => $companyId === null ? null : $this->centre->heatmap($companyId, $period),
            'executive' => $companyId === null ? null
                : $this->centre->executive($companyId, $period),
            'weights' => $companyId === null ? [] : $this->siteRisk->weightsFor($companyId),
            'signals' => SiteRiskScoreService::SIGNALS,
            'bands' => SiteRiskScoreService::BANDS,
            'levels' => CommandCentreService::LEVELS,
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            'canConfigure' => (bool) $request->user()?->can('sheq.configure'),
        ]);
    }

    /** One step of CMD-2's drill-down. */
    public function drill(Request $request): JsonResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'level' => ['required', Rule::in(CommandCentreService::LEVELS)],
            'region_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'department_unit_id' => ['nullable', 'integer'],
            'contractor_id' => ['nullable', 'integer'],
            'worker_id' => ['nullable', 'integer'],
        ]);

        return response()->json(
            $this->centre->drill($companyId, $data['level'], $data));
    }

    /** Recompute the site risk scores on demand (§16), for the period on screen. */
    public function recompute(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);
        $period = $request->string('period')->toString() ?: now()->format('Y-m');

        $count = $this->siteRisk->computeAll($companyId, $period);

        return back()->with('success', "{$count} site(s) rescored for {$period}.");
    }

    /** The §16 weights. */
    public function saveWeights(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $rules = ['weights' => ['required', 'array']];

        foreach (SiteRiskScoreService::signalKeys() as $key) {
            $rules["weights.{$key}"] = ['nullable', 'integer', 'min:0', 'max:50'];
        }

        $data = $request->validate($rules);

        $this->siteRisk->saveWeights($companyId, $data['weights'], $request->user()?->id);

        // Rescored immediately, because a weight change that does not move the board looks like
        // it did nothing and somebody saves it again.
        $this->siteRisk->computeAll($companyId,
            $request->string('period')->toString() ?: now()->format('Y-m'));

        return back()->with('success', 'Weights saved and every site rescored.');
    }
}
