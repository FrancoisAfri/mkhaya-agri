<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmBudgetLine;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\HR\Hr\Attendance\Models\RosteredShift as FarmShift;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\HarvestForecast;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\MachineFault;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use App\Modules\Agri\Models\WorkOrderMachine;
use App\Modules\Agri\Models\WorkOrderWorker;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Support\Collection;

/**
 * Phase 2 §13: a dashboard for each kind of person on the farm — owner, operations manager,
 * agronomist, supervisor, finance, worker — built from the same blocks and the same rules.
 *
 * Every figure says what it is: real-time (the state now), actual (approved or posted — what the
 * books and approved timesheets say), estimate (worked out from actuals by a stated method) or
 * forecast (about the future). And where it came from, with a link to the records behind it.
 * Financial figures are the ledger's, through FarmProfitability, so they reconcile to Finance.
 */
class FarmDashboards
{
    public const ROLES = [
        'owner' => ['Farm owner', [R::VIEW_FINANCE]],
        'operations' => ['Operations manager', [R::VIEW_WORK_ORDERS]],
        'agronomist' => ['Agronomist', [R::VIEW_CROPS]],
        'supervisor' => ['Supervisor', [R::RECORD_ATTENDANCE, R::MANAGE_WORK_ORDERS]],
        'finance' => ['Finance', [R::VIEW_FINANCE]],
        'worker' => ['My day', [R::VIEW_FARMS]],
    ];

    private const CRITICAL = ['planting', 'harvesting', 'spraying', 'irrigation'];

    /** @return list<array{key: string, title: string}> the dashboards this person may open */
    public function rolesFor(User $user): array
    {
        return collect(self::ROLES)->filter(fn ($r) => collect($r[1])->contains(fn ($p) => $user->can($p)))
            ->map(fn ($r, $k) => ['key' => $k, 'title' => $r[0]])->values()->all();
    }

    /** Row ids are for links, not for reading: they leave the table (and the export) here. */
    private function withoutKeys(array $section): array
    {
        if (! empty($section['rows'])) {
            $section['rows'] = array_map(fn ($r) => array_filter((array) $r, fn ($k) => ! in_array($k, ['id', 'uuid'], true) && ! str_ends_with((string) $k, '_id'), ARRAY_FILTER_USE_KEY),
                collect($section['rows'])->all());
        }

        return $section;
    }

    public function build(string $role, User $user, Collection $farms, string $from, string $to): array
    {
        $ids = $farms->pluck('id')->all();
        $sections = match ($role) {
            'owner' => [$this->financial($farms, $from, $to), $this->production($ids), $this->workforce($ids, $from, $to), $this->assets($ids, $from, $to)],
            'operations' => [$this->production($ids), $this->workOrders($ids), $this->workforce($ids, $from, $to), $this->assets($ids, $from, $to)],
            'agronomist' => [$this->production($ids), $this->agronomy($ids)],
            'supervisor' => [$this->today($ids), $this->workOrders($ids)],
            'finance' => [$this->financial($farms, $from, $to), $this->budgets($ids, $from, $to)],
            'worker' => [$this->mine($user)],
            default => [],
        };

        return ['role' => $role, 'title' => self::ROLES[$role][0], 'period' => [$from, $to], 'sections' => array_map(fn ($sec) => $this->withoutKeys($sec), array_values(array_filter($sections))),
            'alerts' => in_array($role, ['owner', 'operations', 'finance', 'supervisor'], true) ? $this->alerts($user, $farms) : [],
            'legend' => ['real_time' => 'The state right now', 'actual' => 'Approved or posted records', 'estimate' => 'Worked out from actuals by the stated method', 'forecast' => 'About the future; may change']];
    }

    private function m(string $label, $value, ?string $unit, string $nature, string $source, ?string $link = null): array
    {
        return ['label' => $label, 'value' => is_float($value) ? round($value, 2) : $value, 'unit' => $unit, 'nature' => $nature, 'source' => $source, 'link' => $link];
    }

    private function financial(Collection $farms, string $from, string $to): array
    {
        $rows = app(FarmProfitability::class)->summary($farms->values(), $from, $to);
        $income = array_sum(array_column($rows, 'income'));
        $costs = array_sum(array_column($rows, 'costs'));
        $flow = app(FarmCashFlow::class)->forecast((int) ($farms->first()?->company_id ?? 0), 13);

        return ['key' => 'financial', 'title' => 'Financial performance', 'metrics' => [
            $this->m('Revenue', $income, 'ZAR', 'actual', 'Posted journals with farm dimensions', '/agri/finance'),
            $this->m('Costs', $costs, 'ZAR', 'actual', 'Posted journals with farm dimensions', '/agri/finance'),
            $this->m('Margin', $income - $costs, 'ZAR', 'actual', 'Revenue less costs, same journals', '/agri/finance'),
            $this->m('Lowest cash in 13 weeks', (float) $flow['lowest'], 'ZAR', 'forecast', 'Cash-flow forecast with its stated assumptions', '/agri/finance/intelligence/cashflow'),
        ], 'rows' => $rows];
    }

    private function production(array $ids): array
    {
        $plans = CropPlan::query()->whereIn('farm_id', $ids)->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING, CropPlan::COMPLETED])->whereNotNull('expected_yield')->get();
        $rows = $plans->map(function ($p) {
            $target = (float) $p->expected_yield * $p->hectares();
            $in = (float) HarvestRecord::query()->where('crop_plan_id', $p->id)->where('destination', '!=', 'loss')->sum('quantity');
            $f = HarvestForecast::query()->where('crop_plan_id', $p->id)->latest('id')->value('quantity');

            return ['plan' => $p->code, 'target' => round($target, 1), 'harvested' => round($in, 1), 'forecast' => $f !== null ? round((float) $f, 1) : null, 'unit' => $p->yield_unit,
                'vs_target_pct' => $target > 0 ? round((($f ?? $in) - $target) / $target * 100, 1) : null, 'link' => "/agri/crops/plans/{$p->uuid}"];
        });

        return ['key' => 'production', 'title' => 'Production: yield against target', 'metrics' => [
            $this->m('Crops harvested or growing with a target', $plans->count(), null, 'real_time', 'Crop plans with an expected yield', '/agri/crops'),
            $this->m('Forecast below target', $rows->filter(fn ($r) => $r['forecast'] !== null && $r['vs_target_pct'] < 0)->count(), 'crops', 'forecast', 'Latest harvest forecast against expected yield × area', '/agri/crops/intelligence/harvest'),
        ], 'rows' => $rows->values()->all()];
    }

    private function workforce(array $ids, string $from, string $to): array
    {
        $sheets = FarmTimesheet::query()->whereIn('farm_id', $ids)->whereBetween('work_date', [$from, $to]);
        $approved = (clone $sheets)->where('status', 'approved');
        $planned = FarmShift::query()->whereIn('farm_id', $ids)->where('status', '!=', 'cancelled')->whereBetween('starts_at', [$from.' 00:00:00', $to.' 23:59:59'])->withCount('assignments')->get()->sum('assignments_count');
        $worked = (clone $sheets)->count();
        $labour = (float) $approved->sum('cost_amount') + (float) PieceworkEntry::query()->whereIn('farm_id', $ids)->where('status', 'approved')->whereBetween('work_date', [$from, $to])->sum('amount');

        return ['key' => 'workforce', 'title' => 'Workforce: attendance and labour cost', 'metrics' => [
            $this->m('Attendance against roster', $planned > 0 ? round($worked / $planned * 100, 1) : null, '%', 'actual', 'Timesheets against rostered assignments', '/agri/attendance'),
            $this->m('Labour cost', $labour, 'ZAR', 'actual', 'Approved timesheets and piecework', '/agri/workforce/analytics'),
            $this->m('Hours approved', round((float) $approved->sum('worked_minutes') / 60, 1), 'h', 'actual', 'Approved timesheets', '/agri/attendance'),
        ]];
    }

    private function assets(array $ids, string $from, string $to): array
    {
        $machines = FarmMachine::query()->whereIn('farm_id', $ids)->where('status', '!=', FarmMachine::DISPOSED)->get();
        $down = MachineFault::query()->whereIn('farm_id', $ids)->whereNotNull('down_from')->where('down_from', '<=', $to.' 23:59:59')
            ->where(fn ($q) => $q->whereNull('down_to')->orWhere('down_to', '>=', $from))->get();

        return ['key' => 'assets', 'title' => 'Assets: availability and downtime', 'metrics' => [
            $this->m('Machines available now', $machines->isEmpty() ? null : round($machines->filter(fn ($m) => ! $m->isUnavailable())->count() / $machines->count() * 100, 1), '%', 'real_time', 'Machine statuses', '/agri/machinery'),
            $this->m('Out of service now', $machines->filter(fn ($m) => $m->isUnavailable())->count(), 'machines', 'real_time', 'Machine statuses', '/agri/machinery/fleet/maintenance'),
            $this->m('Downtime in the period', round($down->sum(fn ($f) => $f->downtimeHours()), 1), 'h', 'actual', 'Faults that stopped a machine', '/agri/machinery/fleet/maintenance'),
        ]];
    }

    private function workOrders(array $ids): array
    {
        $open = WO::query()->whereIn('farm_id', $ids)->whereIn('status', [WO::APPROVED, WO::IN_PROGRESS, WO::PAUSED])->get();

        return ['key' => 'work_orders', 'title' => 'Work orders', 'metrics' => [
            $this->m('Open', $open->count(), 'jobs', 'real_time', 'Approved, started and paused jobs', '/agri/work-orders'),
            $this->m('Past their planned end', $open->filter(fn ($w) => $w->planned_end?->lt(now()))->count(), 'jobs', 'real_time', 'Open jobs past their planned end', '/agri/work-orders'),
            $this->m('Starting in 48 h with nobody assigned', $this->unstaffed($ids)->count(), 'jobs', 'real_time', 'Approved jobs with no worker', '/agri/work-orders'),
            $this->m('Awaiting verification', WO::query()->whereIn('farm_id', $ids)->where('status', WO::VERIFICATION)->count(), 'jobs', 'real_time', 'Jobs handed in', '/agri/work-orders'),
        ]];
    }

    private function agronomy(array $ids): array
    {
        return ['key' => 'agronomy', 'title' => 'Crop health and field work', 'metrics' => [
            $this->m('Open scouting observations', CropObservation::query()->whereIn('farm_id', $ids)->where('status', 'open')->count(), null, 'real_time', 'Scouting', '/agri/crops/intelligence/scouting'),
            $this->m('Serious and open', CropObservation::query()->whereIn('farm_id', $ids)->where('status', 'open')->whereIn('severity', ['high', 'critical'])->count(), null, 'real_time', 'Scouting', '/agri/crops/intelligence/scouting'),
            $this->m('Field activities due this week', \App\Modules\Agri\Models\FieldActivity::query()->whereIn('farm_id', $ids)->where('status', 'planned')->whereDate('planned_on', '<=', today()->addDays(7))->count(), null, 'real_time', 'Planned field activities', '/agri/crops'),
        ]];
    }

    private function budgets(array $ids, string $from, string $to): array
    {
        $rows = collect();
        foreach (Farm::query()->whereIn('id', $ids)->get() as $farm) {
            foreach (app(FarmBudgets::class)->comparison($farm, $from, $to) as $l) {
                if ($l['status'] === 'approved') {
                    $rows->push(['farm' => $farm->name] + collect($l)->only(['category', 'budget', 'actual', 'committed', 'forecast', 'variance'])->all());
                }
            }
        }

        return ['key' => 'budgets', 'title' => 'Budget against actual', 'metrics' => [
            $this->m('Approved budget', (float) $rows->sum('budget'), 'ZAR', 'actual', 'Approved budget lines', '/agri/finance/intelligence/budgets'),
            $this->m('Actual', (float) $rows->sum('actual'), 'ZAR', 'actual', 'Posted journals with each line\'s dimensions', '/agri/finance/intelligence/budgets'),
            $this->m('Forecast to period end', (float) $rows->sum('forecast'), 'ZAR', 'forecast', 'Actual + committed + run rate', '/agri/finance/intelligence/budgets'),
        ], 'rows' => $rows->all()];
    }

    private function today(array $ids): array
    {
        $shifts = FarmShift::query()->whereIn('farm_id', $ids)->where('status', '!=', 'cancelled')->whereDate('starts_at', today())->withCount('assignments')->get();

        return ['key' => 'today', 'title' => 'Today', 'metrics' => [
            $this->m('Shifts today', $shifts->count(), null, 'real_time', 'Roster', '/agri/roster'),
            $this->m('People rostered', (int) $shifts->sum('assignments_count'), null, 'real_time', 'Roster', '/agri/roster'),
            $this->m('Clocked in', FarmTimesheet::query()->whereIn('farm_id', $ids)->whereDate('work_date', today())->count(), null, 'real_time', 'Attendance', '/agri/attendance'),
            $this->m('Pre-start checks today', \App\Modules\Agri\Models\MachinePrestartCheck::query()->whereIn('farm_id', $ids)->whereDate('checked_on', today())->count(), null, 'real_time', 'Pre-start checks', '/agri/machinery/fleet/prestart'),
        ]];
    }

    private function mine(User $user): array
    {
        $workerIds = \App\Modules\HR\Hr\Attendance\Models\Worker::query()->whereHas('person', fn ($q) => $q->withoutGlobalScopes()->where('user_id', $user->id))->pluck('id');
        $jobs = WO::query()->whereIn('id', WorkOrderWorker::query()->whereIn('worker_id', $workerIds)->pluck('work_order_id'))->whereIn('status', [WO::APPROVED, WO::IN_PROGRESS])->get();

        return ['key' => 'mine', 'title' => 'My work', 'metrics' => [
            $this->m('My open jobs', $jobs->count(), null, 'real_time', 'Work orders you are on', '/agri/my-work'),
            $this->m('My tasks', \App\Modules\Agri\Models\FarmTask::query()->where('assigned_to', $user->id)->whereIn('status', \App\Modules\Agri\Models\FarmTask::PENDING)->count(), null, 'real_time', 'Farm tasks', '/agri/my-work'),
            $this->m('My hours this week (approved)', round((float) FarmTimesheet::query()->whereIn('worker_id', $workerIds)->where('status', 'approved')->where('work_date', '>=', today()->startOfWeek())->sum('worked_minutes') / 60, 1), 'h', 'actual', 'Your approved timesheets', '/agri/my-work'),
        ]];
    }

    private function unstaffed(array $ids): Collection
    {
        return WO::query()->whereIn('farm_id', $ids)->where('status', WO::APPROVED)->whereBetween('planned_start', [now(), now()->addHours(48)])
            ->whereDoesntHave('workers')->get();
    }

    /**
     * §13 operational alerts, each with what it is based on and where to look.
     *
     * @return list<array{kind: string, title: string, detail: string, nature: string, link: ?string}>
     */
    public function alerts(User $user, Collection $farms): array
    {
        $ids = $farms->pluck('id')->all();
        $out = [];
        // Harvest forecast below buyer commitments.
        foreach (CropPlan::query()->whereIn('farm_id', $ids)->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->get() as $p) {
            $owed = (float) HarvestCommitment::query()->where('crop_plan_id', $p->id)->whereIn('status', ['open', 'short'])->get()->sum(fn ($c) => max(0, $c->quantity - $c->delivered_quantity));
            $f = HarvestForecast::query()->where('crop_plan_id', $p->id)->latest('id')->value('quantity');
            $in = (float) HarvestRecord::query()->where('crop_plan_id', $p->id)->where('destination', '!=', 'loss')->sum('quantity');
            if ($owed > 0 && $f !== null && (float) $f - $in < $owed) {
                $out[] = ['kind' => 'harvest_short', 'title' => "{$p->code}: forecast short of commitments", 'nature' => 'forecast', 'link' => "/agri/crops/plans/{$p->uuid}",
                    'detail' => 'Forecast still to come '.round((float) $f - $in, 1)." {$p->yield_unit}, owed to buyers {$owed}."];
            }
        }
        // Critical inventory expected to run out.
        if ($user->can(R::VIEW_STOCK)) {
            foreach ($farms as $farm) {
                foreach (app(FarmInventory::class)->reorderSuggestions($farm) as $r) {
                    if ($r['days_left'] < $r['lead_time_days']) {
                        $out[] = ['kind' => 'stock_out', 'title' => "{$r['item']} runs out in {$r['days_left']} days", 'nature' => 'forecast', 'link' => '/agri/stock/control/reorder',
                            'detail' => "{$farm->name}: shorter than its {$r['lead_time_days']}-day lead time, at {$r['per_day']}/day."];
                    }
                }
            }
        }
        // Overdue or unstaffed work orders.
        foreach (WO::query()->whereIn('farm_id', $ids)->whereIn('status', [WO::APPROVED, WO::IN_PROGRESS])->where('planned_end', '<', now())->get() as $w) {
            $out[] = ['kind' => 'wo_overdue', 'title' => "{$w->reference} is overdue", 'nature' => 'real_time', 'link' => "/agri/work-orders/{$w->uuid}", 'detail' => "Planned to end {$w->planned_end->format('Y-m-d H:i')}."];
        }
        foreach ($this->unstaffed($ids) as $w) {
            $out[] = ['kind' => 'wo_unstaffed', 'title' => "{$w->reference} has nobody assigned", 'nature' => 'real_time', 'link' => "/agri/work-orders/{$w->uuid}", 'detail' => "Starts {$w->planned_start->format('Y-m-d H:i')}."];
        }
        // Equipment unavailable during a critical activity.
        foreach (WorkOrderMachine::query()->whereIn('farm_id', $ids)->whereNotNull('conflict')->where('booked_to', '>', now())->with(['workOrder' => fn ($q) => $q->withoutGlobalScopes()])->get() as $b) {
            if ($b->workOrder && in_array($b->workOrder->category, self::CRITICAL, true)) {
                $out[] = ['kind' => 'equipment', 'title' => "{$b->workOrder->reference} ({$b->workOrder->category}) relies on unavailable equipment", 'nature' => 'real_time',
                    'link' => "/agri/work-orders/{$b->workOrder->uuid}", 'detail' => $b->conflict];
            }
        }
        // Labour cost exceeding budget.
        if ($user->can(R::VIEW_FINANCE)) {
            foreach (FarmBudgetLine::query()->whereIn('farm_id', $ids)->where('category', 'labour')->where('status', 'approved')->where('period_end', '>=', today())->get() as $l) {
                $cmp = app(FarmBudgets::class)->comparison(Farm::query()->find($l->farm_id), $l->period_start->toDateString(), $l->period_end->toDateString());
                $row = collect($cmp)->firstWhere('id', $l->uuid);
                if ($row && $row['actual'] > $row['budget']) {
                    $out[] = ['kind' => 'labour_budget', 'title' => 'Labour over budget'.($l->description ? ": {$l->description}" : ''), 'nature' => 'actual', 'link' => '/agri/finance/intelligence/budgets',
                        'detail' => 'Actual R'.number_format($row['actual'], 2).' against R'.number_format($row['budget'], 2).'.'];
                }
            }
        }
        // Compliance documents nearing expiry.
        foreach (FarmDocuments::expiring(collect($ids)->all(), 30) as ['document' => $d, 'state' => $state]) {
            $out[] = ['kind' => 'document', 'title' => "{$d->title} ".($state === 'expired' ? 'has expired' : 'expires '.$d->expiry_date->toDateString()), 'nature' => 'real_time',
                'link' => FarmDocuments::url($d), 'detail' => str_replace('_', ' ', (string) $d->document_type)];
        }
        // Cash-flow risk.
        if ($user->can(R::VIEW_FINANCE) && $farms->isNotEmpty()) {
            $flow = app(FarmCashFlow::class)->forecast((int) $farms->first()->company_id, 13);
            if ($flow['first_shortage']) {
                $out[] = ['kind' => 'cash', 'title' => "Cash runs short in the week of {$flow['first_shortage']}", 'nature' => 'forecast', 'link' => '/agri/finance/intelligence/cashflow',
                    'detail' => 'Lowest forecast balance R'.number_format($flow['lowest'], 2).' within 13 weeks, on the forecast\'s stated assumptions.'];
            }
        }

        return $out;
    }
}
