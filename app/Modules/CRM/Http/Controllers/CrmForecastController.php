<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmPerformanceService;
use App\Modules\CRM\Services\CrmTeamService;
use App\Modules\CRM\Services\CrmVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI forecasting & pipeline inspection — a weighted roll-up, per-rep gap to
 * quota, what changed this week, and an AI forecast call with recommended plays.
 */
class CrmForecastController extends Controller
{
    public function __construct(
        private CrmVisibilityService $visibility,
        private CrmPerformanceService $perf,
        private CrmTeamService $teams,
        private CrmAiService $ai,
    ) {
    }

    public function index(Request $request): Response
    {
        $owners = $this->visibility->visibleOwnerIds();
        $scope  = fn ($q) => $owners !== null ? $q->whereIn('owner_id', $owners) : $q;
        [$from, $to] = [now()->startOfMonth(), now()->endOfMonth()];

        // Org roll-up.
        $open        = $scope(CrmDeal::where('status', 'open'));
        $openValue   = (float) (clone $open)->sum('amount');
        $weighted    = (float) (clone $open)->sum(DB::raw('amount * probability / 100'));
        $wonValue    = (float) $scope(CrmDeal::where('status', 'won'))->whereBetween('closed_at', [$from, $to])->sum('amount');
        $commit      = round($wonValue + $weighted, 2);

        // Per-rep gap to quota (monthly revenue target).
        $reps = $this->rollupByRep($owners, $from, $to);
        $quota = array_sum(array_column($reps, 'target'));

        // What changed this week.
        $weekFrom = now()->subDays(7);
        $changed = [
            'created' => $this->countValue($scope(CrmDeal::query())->where('created_at', '>=', $weekFrom)),
            'won'     => $this->countValue($scope(CrmDeal::where('status', 'won'))->where('closed_at', '>=', $weekFrom)),
            'lost'    => $this->countValue($scope(CrmDeal::where('status', 'lost'))->where('closed_at', '>=', $weekFrom)),
            'moved'   => $scope(CrmDeal::where('status', 'open'))->where('stage_changed_at', '>=', $weekFrom)->count(),
        ];

        return Inertia::render('Modules/CRM/Forecast', [
            'rollup' => [
                'open_value'   => $openValue,
                'weighted'     => round($weighted, 2),
                'won_value'    => $wonValue,
                'commit'       => $commit,
                'quota'        => $quota,
                'gap'          => round($quota - $wonValue, 2),
                'attainment'   => $quota > 0 ? round($wonValue / $quota * 100) : null,
                'open_count'   => (clone $open)->count(),
            ],
            'reps'     => $reps,
            'changed'  => $changed,
            'insights'  => Session::get('forecast_insights'),
            'anomalies' => Session::get('forecast_anomalies'),
            'aiEnabled' => $this->ai->isConfigured(),
        ]);
    }

    /** Scan the visible open pipeline for anomalies (sandbagging, stuck, errors). */
    public function anomalies(Request $request): RedirectResponse
    {
        $owners = $this->visibility->visibleOwnerIds();
        $scope  = fn ($q) => $owners !== null ? $q->whereIn('owner_id', $owners) : $q;

        $lastActivity = $scope(CrmActivity::where('related_type', 'deal'))
            ->select('related_id', DB::raw('max(created_at) as last'))->groupBy('related_id')->pluck('last', 'related_id');

        $deals = $scope(CrmDeal::with(['account:id,name', 'stage:id,name'])->where('status', 'open'))
            ->limit(120)->get()
            ->map(function ($d) use ($lastActivity) {
                $last = $lastActivity[$d->id] ?? null;
                return [
                    'id' => $d->id, 'title' => $d->title, 'account' => $d->account?->name, 'stage' => $d->stage?->name,
                    'amount' => (float) $d->amount, 'probability' => $d->probability,
                    'expected_close' => $d->expected_close_date?->toDateString(),
                    'days_in_stage' => $d->stage_changed_at?->diffInDays(now()),
                    'days_since_activity' => $last ? \Carbon\Carbon::parse($last)->diffInDays(now()) : null,
                ];
            })->all();

        $result = $this->ai->detectAnomalies(['today' => now()->toDateString(), 'deals' => $deals]);
        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Anomaly scan failed.');
        }

        return back()->with('forecast_anomalies', $result['flags'] ?? []);
    }

    public function analyze(Request $request): RedirectResponse
    {
        $owners = $this->visibility->visibleOwnerIds();
        $scope  = fn ($q) => $owners !== null ? $q->whereIn('owner_id', $owners) : $q;
        [$from, $to] = [now()->startOfMonth(), now()->endOfMonth()];

        $reps = $this->rollupByRep($owners, $from, $to);
        $weekFrom = now()->subDays(7);

        $payload = [
            'open_value' => (float) $scope(CrmDeal::where('status', 'open'))->sum('amount'),
            'weighted'   => (float) $scope(CrmDeal::where('status', 'open'))->sum(DB::raw('amount * probability / 100')),
            'won_mtd'    => (float) $scope(CrmDeal::where('status', 'won'))->whereBetween('closed_at', [$from, $to])->sum('amount'),
            'reps'       => $reps,
            'at_risk_deals' => $scope(CrmDeal::with('account:id,name')->where('status', 'open')->where('risk_level', 'high'))
                ->latest('updated_at')->limit(25)->get()
                ->map(fn ($d) => ['title' => $d->title, 'account' => $d->account?->name, 'amount' => (float) $d->amount, 'reason' => $d->risk_reason])->all(),
            'changed_this_week' => [
                'won'  => $this->countValue($scope(CrmDeal::where('status', 'won'))->where('closed_at', '>=', $weekFrom)),
                'lost' => $this->countValue($scope(CrmDeal::where('status', 'lost'))->where('closed_at', '>=', $weekFrom)),
            ],
        ];

        $result = $this->ai->forecastInsights($payload);
        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'AI forecast failed.');
        }

        return back()->with('forecast_insights', $result);
    }

    private function rollupByRep(?array $owners, $from, $to): array
    {
        // Visible owners, or the whole sales force when unrestricted.
        $ids = $owners ?? array_column($this->teams->salesForce(), 'id');

        $rows = [];
        foreach (array_unique($ids) as $id) {
            $name = optional(\App\Core\User\Models\User::with('person')->find($id))->person?->first_name ?: ('User #'.$id);
            $won  = (float) CrmDeal::where('owner_id', $id)->where('status', 'won')->whereBetween('closed_at', [$from, $to])->sum('amount');
            $weighted = (float) CrmDeal::where('owner_id', $id)->where('status', 'open')->sum(DB::raw('amount * probability / 100'));
            $target = $this->perf->targets($id, 'monthly')['revenue'] ?? 0;
            $rows[] = [
                'name' => $name, 'won' => $won, 'weighted' => round($weighted, 2), 'target' => (float) $target,
                'gap' => round((float) $target - $won, 2),
                'attainment' => $target > 0 ? round($won / (float) $target * 100) : null,
            ];
        }
        usort($rows, fn ($a, $b) => $b['won'] <=> $a['won']);
        return $rows;
    }

    private function countValue($query): array
    {
        $q = clone $query;
        return ['count' => $q->count(), 'value' => (float) (clone $query)->sum('amount')];
    }
}
