<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Carbon;

/**
 * The figures behind the Mkhaya Agri assistant (spec Module H).
 *
 * Read-only by construction: every topic is a query, and nothing here writes. Each topic names the
 * permission it needs and is answered only for a person who holds it, and every read goes through
 * the farm-access scope — so the assistant can never tell somebody about a farm, an animal or a
 * figure they could not open themselves. The language model never produces a number: AgriNarrator
 * only words what is computed here, and its wording is discarded if it invents one.
 */
class AgriAssistant
{
    /** topic => [question it answers, permissions (any one suffices)] */
    public const TOPICS = [
        'overview' => ['What needs my attention on the farms today?', [R::VIEW_FARMS]],
        'crop_status' => ['How are the crops doing this season?', [R::VIEW_CROPS]],
        'yield' => ['Which fields are yielding above or below plan?', [R::VIEW_CROPS]],
        'livestock' => ['How many animals do we have, and where?', [R::VIEW_LIVESTOCK]],
        'withdrawal' => ['Which animals may not be sold yet because of treatment?', [R::VIEW_LIVESTOCK]],
        'low_stock' => ['Which farm inputs are running low?', [R::VIEW_STOCK]],
        'machinery' => ['Which machines are due for a service?', [R::VIEW_MACHINERY]],
        'tasks' => ['Which tasks are overdue or waiting for approval?', [R::VIEW_TASKS]],
        'documents' => ['Which permits or certificates are expiring?', [R::VIEW_DOCUMENTS]],
        'profitability' => ['Which enterprises are most and least profitable this year?', [R::VIEW_FINANCE]],
        // Phase 2 §10.1
        'yield_risk' => ['Which fields are likely to fall below their yield target?', [R::VIEW_CROPS]],
        'harvest_cost' => ['What is the estimated cost of harvesting the remaining crop?', [R::VIEW_CROPS]],
        'stock_before_harvest' => ['Which stock items may run out before harvest?', [R::VIEW_STOCK]],
        'late_work_orders' => ['Which work orders are behind schedule?', [R::VIEW_WORK_ORDERS]],
        'labour_per_kg' => ['What is the labour cost per kilogram this season?', [R::VIEW_WORKFORCE]],
        'buyer_payments' => ['Which buyers have outstanding payments?', [R::VIEW_SALES, R::VIEW_FINANCE]],
        'livestock_care' => ['Which livestock groups require scheduled care?', [R::VIEW_LIVESTOCK]],
        'machine_risk' => ['Which machines should be inspected first?', [R::VIEW_MACHINERY]],
    ];

    private const KEYWORDS = [
        // The specific §10.1 questions first: they share words with the broader topics below.
        'stock_before_harvest' => ['before harvest', 'run out before', 'last until harvest'],
        'harvest_cost' => ['cost of harvesting', 'cost to harvest', 'harvesting cost', 'harvest the remaining'],
        'yield_risk' => ['below their yield target', 'below target', 'fall below', 'miss their yield', 'under target'],
        'labour_per_kg' => ['labour cost per', 'labor cost per', 'per kilogram', 'per kg'],
        'buyer_payments' => ['outstanding payment', 'unpaid', 'owe us', 'owes us', 'buyers have outstanding', 'debtors'],
        'late_work_orders' => ['behind schedule', 'running late', 'late work order', 'overdue work order', 'work orders behind'],
        'livestock_care' => ['scheduled care', 'vaccination', 'vaccinate', 'due for care', 'dipping', 'deworm'],
        'machine_risk' => ['inspected first', 'failure risk', 'break down', 'breakdown', 'likely to fail', 'risk of failure'],
        'withdrawal' => ['withdrawal', 'withholding', 'cannot be sold', 'can\'t be sold', 'not be sold', 'treated', 'treatment'],
        'yield' => ['yield', 'per hectare', 'per ha', 'tonnes', 'tons'],
        'profitability' => ['profit', 'margin', 'income', 'cost', 'expense', 'money', 'budget', 'spend'],
        'low_stock' => ['stock', 'running low', 'run out', 'fertiliser', 'fertilizer', 'seed', 'chemical', 'inputs', 'diesel', 'feed'],
        'machinery' => ['machine', 'tractor', 'service', 'harvester', 'implement', 'equipment'],
        'documents' => ['permit', 'licence', 'license', 'certificate', 'expir', 'document', 'compliance'],
        'tasks' => ['task', 'overdue', 'approval', 'approve', 'work order', 'job'],
        'livestock' => ['animal', 'cattle', 'cow', 'sheep', 'goat', 'pig', 'chicken', 'poultry', 'herd', 'flock', 'livestock', 'head'],
        'crop_status' => ['crop', 'plant', 'harvest', 'season', 'field', 'maize', 'wheat', 'soy'],
        'overview' => ['attention', 'today', 'overview', 'summary', 'alert', 'what should', 'problem'],
    ];

    public function __construct(
        private readonly Harvests $harvests,
        private readonly FarmProfitability $profitability,
        private readonly StockLedger $stock,
        private readonly Livestock $livestock,
    ) {}

    public function topicFor(string $question): ?string
    {
        $q = mb_strtolower($question);
        foreach (self::KEYWORDS as $topic => $words) {
            foreach ($words as $w) {
                if (str_contains($q, $w)) {
                    return $topic;
                }
            }
        }

        return null;
    }

    public function allowed(User $user, string $topic): bool
    {
        foreach (self::TOPICS[$topic][1] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{topic: string, question: string}> the topics this person may ask about */
    public function suggestions(User $user): array
    {
        $out = [];
        foreach (self::TOPICS as $topic => [$question]) {
            if ($this->allowed($user, $topic)) {
                $out[] = ['topic' => $topic, 'question' => $question];
            }
        }

        return $out;
    }

    public function answer(string $question, User $user, ?string $topic = null): array
    {
        $topic = $topic && isset(self::TOPICS[$topic]) ? $topic : $this->topicFor($question);
        if (! $topic) {
            return $this->empty(null, 'I answer questions from your farm records, for example: '.implode(' ', array_column($this->suggestions($user), 'question')));
        }
        if (! $this->allowed($user, $topic)) {
            return $this->empty($topic, 'Your permissions do not cover the records this question needs, so no figures are shown.', true);
        }
        $farms = Farm::query()->orderBy('name')->get();
        if ($farms->isEmpty()) {
            return $this->empty($topic, 'You do not have access to any farm yet, so there is nothing to report.');
        }
        $method = lcfirst(str_replace('_', '', ucwords($topic, '_')));
        $a = $this->{$method}($farms);

        // §10.1: every answer from farm data says the period it covers, the records it read and what it assumed.
        return $a + ['period' => 'As of '.today()->toDateString(), 'assumptions' => []] + ['topic' => $topic, 'title' => self::TOPICS[$topic][0], 'refused' => false,
            'farms' => $farms->pluck('name')->all(), 'as_of' => now()->toDateTimeString(), 'read_only' => true];
    }

    // ── Topics ──────────────────────────────────────────────────────────────────────────────

    private function overview($farms): array
    {
        $alerts = FarmAlert::query()->whereIn('status', [FarmAlert::OPEN, FarmAlert::ACKNOWLEDGED])
            ->whereIn('farm_id', $farms->pluck('id'))->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->limit(15)->get();
        $byFarm = $farms->keyBy('id');
        $rows = $alerts->map(fn ($a) => ['farm' => $byFarm[$a->farm_id]->name ?? '', 'severity' => $a->severity, 'alert' => $a->title,
            'detail' => $a->message, 'url' => $a->link])->all();
        $critical = $alerts->where('severity', 'critical')->count();

        return ['columns' => ['farm', 'severity', 'alert', 'detail'], 'rows' => $rows,
            'sources' => ['Open farm alerts (low stock, services, documents, tasks, withdrawals, scouting, harvests, budgets)'],
            'flags' => [], 'recommendations' => [],
            'summary' => $alerts->isEmpty() ? 'Nothing needs attention: there are no open alerts on your farms.'
                : "{$alerts->count()} open alert(s), {$critical} of them critical. The most urgent: {$alerts->first()->title}."];
    }

    private function cropStatus($farms): array
    {
        $plans = CropPlan::query()->whereIn('status', CropPlan::LIVE)->with(['cropType', 'field', 'farm', 'season'])->orderBy('planned_harvest_on')->get();
        $rows = $plans->map(fn (CropPlan $p) => ['farm' => $p->farm->name, 'plan' => $p->code, 'crop' => $p->cropType?->name,
            'field' => $p->field?->code, 'hectares' => round($p->hectares(), 2), 'status' => $p->status,
            'harvest_planned' => $p->planned_harvest_on?->toDateString(), 'url' => "/agri/crops/plans/{$p->uuid}"])->all();
        $late = $plans->where('status', CropPlan::PLANTED)->filter(fn ($p) => $p->planned_harvest_on->lt(today()))->count();
        $byStatus = $plans->countBy('status')->map(fn ($n, $s) => "{$n} {$s}")->implode(', ');
        $ha = round($plans->sum(fn ($p) => $p->hectares()), 2);

        return ['columns' => ['farm', 'plan', 'crop', 'field', 'hectares', 'status', 'harvest_planned'], 'rows' => $rows,
            'sources' => ["{$plans->count()} crop plan(s) not yet completed or cancelled"],
            'flags' => $late ? [$this->flag('WARNING', "{$late} planted crop(s) are past their planned harvest date.")] : [],
            'recommendations' => [],
            'summary' => $plans->isEmpty() ? 'There are no open crop plans.' : "{$plans->count()} crop plan(s) over {$ha} ha: {$byStatus}."];
    }

    private function yield($farms): array
    {
        $plans = CropPlan::query()->whereIn('status', [CropPlan::HARVESTING, CropPlan::COMPLETED])->with(['cropType', 'field', 'farm'])->get();
        $rows = $plans->map(function (CropPlan $p) {
            $y = $this->harvests->yieldFor($p);

            return ['farm' => $p->farm->name, 'plan' => $p->code, 'crop' => $p->cropType?->name, 'field' => $p->field?->code,
                'per_ha' => $y['per_ha'], 'expected_per_ha' => $y['expected_per_ha'], 'unit' => $y['unit'].'/ha',
                'variance_pct' => $y['variance_pct'], 'url' => "/agri/crops/plans/{$p->uuid}"];
        })->sortBy('variance_pct')->values();
        $noTarget = $rows->whereNull('expected_per_ha')->count();
        $worst = $rows->whereNotNull('variance_pct')->first();
        $best = $rows->whereNotNull('variance_pct')->last();

        return ['columns' => ['farm', 'plan', 'crop', 'field', 'per_ha', 'expected_per_ha', 'unit', 'variance_pct'], 'rows' => $rows->all(),
            'sources' => ["Harvest records on {$plans->count()} harvested crop plan(s)"],
            'flags' => $noTarget ? [$this->flag('INSUFFICIENT', "{$noTarget} plan(s) have no expected yield, so they cannot be compared with plan.")] : [],
            'recommendations' => [],
            'summary' => ! $worst ? 'No harvested crop has an expected yield to compare against yet.'
                : "Lowest against plan: {$worst['plan']} ({$worst['crop']}) at {$worst['per_ha']} {$worst['unit']}, {$worst['variance_pct']}% vs plan. ".
                  "Highest: {$best['plan']} at {$best['variance_pct']}% vs plan."];
    }

    private function livestock($farms): array
    {
        $groups = LivestockGroup::query()->where('status', LivestockGroup::ACTIVE)->with(['species', 'farm'])->get();
        $rows = $groups->map(fn (LivestockGroup $g) => ['farm' => $g->farm->name, 'group' => $g->code, 'name' => $g->name,
            'species' => $g->species?->name, 'head' => $g->currentHeadCount(), 'url' => "/agri/livestock/groups/{$g->uuid}"])->all();
        $loose = Animal::query()->where('status', Animal::ACTIVE)->whereNull('group_id')->count();
        $total = array_sum(array_column($rows, 'head')) + $loose;
        $bySpecies = collect($rows)->groupBy('species')->map(fn ($g, $s) => $g->sum('head').' '.$s)->implode(', ');

        return ['columns' => ['farm', 'group', 'name', 'species', 'head'], 'rows' => $rows,
            'sources' => ['Active livestock groups and individually registered animals'],
            'flags' => $loose ? [$this->flag('INFO', "{$loose} animal(s) are not in any group.")] : [],
            'recommendations' => [],
            'summary' => $total === 0 ? 'No livestock is registered.' : "{$total} head in total".($bySpecies ? ": {$bySpecies}" : '').($loose ? ", plus {$loose} ungrouped" : '').'.'];
    }

    private function withdrawal($farms): array
    {
        $records = AnimalHealthRecord::query()->whereDate('withdrawal_until', '>', today())->with(['animal', 'group', 'farm'])->orderBy('withdrawal_until')->get();
        $rows = $records->map(fn ($h) => ['farm' => $h->farm->name, 'animal_or_group' => $h->animal?->tag_number ?? $h->group?->code,
            'product' => $h->product_name, 'treated_on' => $h->treated_on->toDateString(), 'no_sale_until' => $h->withdrawal_until->toDateString()])->all();

        return ['columns' => ['farm', 'animal_or_group', 'product', 'treated_on', 'no_sale_until'], 'rows' => $rows,
            'sources' => ['Health records with a withdrawal period still running'], 'flags' => [], 'recommendations' => [],
            'summary' => $records->isEmpty() ? 'No animal is inside a withdrawal period.'
                : "{$records->count()} treatment(s) still in withdrawal; the last ends {$records->last()->withdrawal_until->toDateString()}."];
    }

    private function lowStock($farms): array
    {
        $rows = [];
        $items = FinStockItem::query()->where('is_active', true)->get();
        foreach ($farms->whereNotNull('warehouse_id') as $farm) {
            foreach ($items as $item) {
                $avail = $this->stock->available($item, (int) $farm->warehouse_id);
                $reorder = (float) $item->reorder_level;
                if ($reorder > 0 && $avail < $reorder) {
                    $rows[] = ['farm' => $farm->name, 'item' => $item->name, 'available' => round($avail, 2), 'reorder_level' => round($reorder, 2), 'uom' => $item->uom];
                }
            }
        }
        $none = $farms->whereNull('warehouse_id')->count();

        return ['columns' => ['farm', 'item', 'available', 'reorder_level', 'uom'], 'rows' => $rows,
            'sources' => ['Available stock in each farm store against the item reorder level'],
            'flags' => $none ? [$this->flag('INSUFFICIENT', "{$none} farm(s) have no store yet, so their stock is not known.")] : [],
            'recommendations' => $rows ? [['text' => 'Raise a purchase requisition for the items below their reorder level.', 'url' => '/agri/stock']] : [],
            'summary' => $rows ? count($rows).' input(s) are below their reorder level. Lowest first: '.$rows[0]['item'].' on '.$rows[0]['farm'].'.'
                : 'No farm input is below its reorder level.'];
    }

    private function machinery($farms): array
    {
        $machines = FarmMachine::query()->where('status', '!=', FarmMachine::DISPOSED)->with('farm')->get()->filter(fn ($m) => $m->serviceDue(14, 10));
        $rows = $machines->map(fn ($m) => ['farm' => $m->farm->name, 'machine' => "{$m->code} {$m->name}", 'hours' => (float) $m->hours_reading,
            'service_at_hours' => $m->next_service_hours !== null ? (float) $m->next_service_hours : null, 'service_by' => $m->next_service_on?->toDateString()])->values()->all();

        return ['columns' => ['farm', 'machine', 'hours', 'service_at_hours', 'service_by'], 'rows' => $rows,
            'sources' => ['Machine hour meters and service schedules'], 'flags' => [], 'recommendations' => [],
            'summary' => $rows ? count($rows).' machine(s) are due or nearly due for a service.' : 'No machine is due for a service in the next 14 days.'];
    }

    private function tasks($farms): array
    {
        $tasks = FarmTask::query()->where(fn ($q) => $q->where('status', FarmTask::SUBMITTED)
            ->orWhere(fn ($w) => $w->whereIn('status', FarmTask::PENDING)->whereDate('due_on', '<', today())))
            ->with('farm')->orderBy('due_on')->get();
        $rows = $tasks->map(fn ($t) => ['farm' => $t->farm->name, 'task' => $t->title, 'status' => $t->status, 'due' => $t->due_on?->toDateString(), 'priority' => $t->priority])->all();
        $waiting = $tasks->where('status', FarmTask::SUBMITTED)->count();

        return ['columns' => ['farm', 'task', 'status', 'due', 'priority'], 'rows' => $rows, 'sources' => ['Farm tasks'],
            'flags' => [], 'recommendations' => [],
            'summary' => $tasks->isEmpty() ? 'No task is overdue or waiting for approval.'
                : ($tasks->count() - $waiting)." overdue task(s) and {$waiting} waiting for approval."];
    }

    private function documents($farms): array
    {
        $names = $farms->pluck('name', 'id');
        $docs = FarmDocuments::expiring($farms->pluck('id')->all());
        $rows = $docs->map(fn ($x) => ['farm' => $names[$x['farm_id']] ?? null, 'document' => $x['document']->title, 'type' => str_replace('_', ' ', (string) $x['document']->document_type),
            'expires_on' => $x['document']->expiry_date->toDateString(), 'state' => $x['state']])->all();
        $expired = $docs->where('state', 'expired')->count();

        return ['columns' => ['farm', 'document', 'type', 'expires_on', 'state'], 'rows' => $rows, 'sources' => ['Farm records in Document Management'],
            'flags' => [], 'recommendations' => [],
            'summary' => $docs->isEmpty() ? 'No permit, licence or certificate expires in the next '.config('agri.alert_horizon_days', 30).' days.'
                : "{$docs->count()} document(s) expired or expiring, {$expired} already expired."];
    }

    private function profitability($farms): array
    {
        $from = today()->startOfYear()->toDateString();
        $to = today()->toDateString();
        $rows = [];
        foreach ($farms as $farm) {
            foreach ($this->profitability->forFarm($farm, $from, $to)['enterprises'] as $e) {
                if ($e['income'] != 0 || $e['costs'] != 0) {
                    $rows[] = ['farm' => $farm->name, 'enterprise' => $e['name'], 'income' => $e['income'], 'costs' => $e['costs'], 'gross_margin' => $e['gross_margin'], 'kind' => 'ACTUAL'];
                }
            }
        }
        usort($rows, fn ($a, $b) => $b['gross_margin'] <=> $a['gross_margin']);

        return ['columns' => ['farm', 'enterprise', 'income', 'costs', 'gross_margin'], 'rows' => $rows,
            'sources' => ["Posted journal lines carrying farm dimensions, {$from} to {$to}"],
            'flags' => $rows ? [] : [$this->flag('INSUFFICIENT', 'Nothing has been posted against a farm this year yet.')],
            'recommendations' => [],
            'period' => ['from' => $from, 'to' => $to, 'label' => 'this year'],
            'summary' => $rows ? "Most profitable: {$rows[0]['enterprise']} ({$rows[0]['farm']}) with a gross margin of ".number_format($rows[0]['gross_margin'], 2).
                '. Least: '.end($rows)['enterprise'].' at '.number_format(end($rows)['gross_margin'], 2).'.' : 'No income or cost has been posted against a farm this year.'];
    }

    // ── Phase 2 §10.1 ───────────────────────────────────────────────────────────────────────

    private function yieldRisk($farms): array
    {
        $rows = CropPlan::query()->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->whereNotNull('expected_yield')->with(['cropType', 'field', 'farm'])->get()
            ->map(function (CropPlan $p) {
                $f = \App\Modules\Agri\Models\HarvestForecast::query()->where('crop_plan_id', $p->id)->latest('id')->first();
                $target = (float) $p->expected_yield * $p->hectares();
                if (! $f || $target <= 0) {
                    return null;
                }
                $gap = round(((float) $f->quantity - $target) / $target * 100, 1);

                return ['farm' => $p->farm->name, 'plan' => $p->code, 'crop' => $p->cropType?->name, 'field' => $p->field?->code, 'target' => round($target, 1),
                    'forecast' => round((float) $f->quantity, 1), 'unit' => $p->yield_unit, 'vs_target_pct' => $gap,
                    'range' => $f->low !== null && $f->high !== null ? round((float) $f->low, 1).'–'.round((float) $f->high, 1) : null, 'method' => $f->method,
                    'forecast_on' => $f->made_on?->toDateString() ?? $f->created_at?->toDateString(), 'url' => "/agri/crops/plans/{$p->uuid}"];
            })->filter()->sortBy('vs_target_pct')->values();
        $below = $rows->where('vs_target_pct', '<', 0);
        $none = CropPlan::query()->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->count() - $rows->count();

        return ['columns' => ['farm', 'plan', 'crop', 'field', 'target', 'forecast', 'range', 'unit', 'vs_target_pct', 'forecast_on'], 'rows' => $rows->all(),
            'sources' => ['The latest harvest forecast of each growing crop', 'Expected yield × planted area on each crop plan'],
            'assumptions' => ['Target = expected yield per hectare × the plan\'s area.', 'A crop without a forecast or an expected yield is left out.'],
            'flags' => $none > 0 ? [$this->flag('INSUFFICIENT', "{$none} growing crop(s) have no forecast or no expected yield, so they cannot be judged.")] : [],
            'recommendations' => $below->take(3)->map(fn ($r) => "Scout {$r['field']} ({$r['plan']}): forecast {$r['vs_target_pct']}% against target.")->values()->all(),
            'drafts' => $below->take(3)->map(fn ($r) => ['kind' => 'work_order', 'title' => "Scout {$r['field']} for yield loss", 'farm' => $r['farm'],
                'payload' => ['category' => 'other', 'title' => "Scout {$r['field']} ({$r['plan']}) for yield loss", 'plan' => $r['plan']],
                'reason' => "Forecast {$r['forecast']} {$r['unit']} against a target of {$r['target']} ({$r['vs_target_pct']}%)."])->values()->all(),
            'summary' => $rows->isEmpty() ? 'No growing crop has both a forecast and a target yet.'
                : ($below->isEmpty() ? 'Every forecast crop is on or above its yield target.' : "{$below->count()} crop(s) are forecast below target; the furthest is {$below->first()['plan']} at {$below->first()['vs_target_pct']}%.")];
    }

    private function harvestCost($farms): array
    {
        $rows = CropPlan::query()->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->with(['cropType', 'farm'])->get()->map(function (CropPlan $p) {
            $in = (float) \App\Modules\Agri\Models\HarvestRecord::query()->where('crop_plan_id', $p->id)->where('destination', '!=', 'loss')->sum('quantity');
            $f = \App\Modules\Agri\Models\HarvestForecast::query()->where('crop_plan_id', $p->id)->latest('id')->first();
            $expected = $f?->quantity ?? ($p->expected_yield !== null ? (float) $p->expected_yield * $p->hectares() : null);
            if ($expected === null) {
                return null;
            }
            $left = max(0, (float) $expected - $in);
            // What harvesting has cost per unit so far on this crop: approved labour on harvesting jobs, over what came in.
            $woIds = \App\Modules\Agri\Models\FarmWorkOrder::query()->where('crop_plan_id', $p->id)->where('category', 'harvesting')->pluck('id');
            $spent = (float) \App\Modules\HR\Hr\Attendance\Models\Timesheet::query()->whereIn('work_order_id', $woIds)->where('status', 'approved')->sum('cost_amount')
                + (float) \App\Modules\HR\Hr\Attendance\Models\PieceworkEntry::query()->whereIn('work_order_id', $woIds)->where('status', 'approved')->sum('amount')
                + (float) \App\Modules\Agri\Models\FarmWorkOrder::query()->whereIn('id', $woIds)->sum('actual_machine_cost');
            $rate = $in > 0 && $spent > 0 ? $spent / $in : null;

            return ['farm' => $p->farm->name, 'plan' => $p->code, 'crop' => $p->cropType?->name, 'left' => round($left, 1), 'unit' => $p->yield_unit,
                'cost_per_unit_so_far' => $rate !== null ? round($rate, 2) : null, 'estimate' => $rate !== null ? round($rate * $left, 2) : null,
                'url' => "/agri/crops/plans/{$p->uuid}"];
        })->filter()->values();
        $priced = $rows->whereNotNull('estimate');

        return ['columns' => ['farm', 'plan', 'crop', 'left', 'unit', 'cost_per_unit_so_far', 'estimate'], 'rows' => $rows->all(),
            'sources' => ['Harvest forecasts and records', 'Approved time, piecework and machine cost on each crop\'s harvesting work orders'],
            'assumptions' => ['The rest of the crop costs what harvesting has cost per unit so far.', 'Left to harvest = latest forecast (else expected yield × area) less what is in.'],
            'flags' => $rows->count() > $priced->count() ? [$this->flag('INSUFFICIENT', ($rows->count() - $priced->count()).' crop(s) have no harvesting costs yet, so no estimate is given for them.')] : [],
            'recommendations' => [],
            'summary' => $priced->isEmpty() ? 'No crop has harvesting costs recorded yet, so the cost of the rest cannot be estimated.'
                : 'About R'.number_format($priced->sum('estimate'), 2).' to harvest what is left on '.$priced->count().' crop(s), at each crop\'s cost per unit so far.'];
    }

    private function stockBeforeHarvest($farms): array
    {
        $inventory = app(FarmInventory::class);
        $rows = collect();
        foreach ($farms as $farm) {
            $next = CropPlan::query()->where('farm_id', $farm->id)->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->where('planned_harvest_on', '>=', today())->min('planned_harvest_on');
            if (! $next) {
                continue;
            }
            $days = today()->diffInDays(Carbon::parse($next));
            foreach ($inventory->reorderSuggestions($farm, (int) $days) as $r) {
                if ($r['days_left'] < $days) {
                    $rows->push(['farm' => $farm->name, 'item' => $r['item'], 'item_id' => $r['item_id'], 'available' => $r['available'], 'per_day' => $r['per_day'],
                        'days_left' => $r['days_left'], 'harvest_from' => Carbon::parse($next)->toDateString(), 'order_by' => $r['order_by'], 'suggest' => $r['suggest'], 'uom' => $r['uom'] ?? null]);
                }
            }
        }

        return ['columns' => ['farm', 'item', 'available', 'per_day', 'days_left', 'harvest_from', 'order_by'], 'rows' => $rows->sortBy('days_left')->values()->all(),
            'sources' => ['The last 90 days\' issues from each farm store', 'What is in store and on order', 'Each farm\'s next planned harvest'],
            'assumptions' => ['Use continues at the last 90 days\' daily rate.', 'Stock on order arrives on time.'],
            'flags' => [], 'recommendations' => [],
            'drafts' => $rows->take(5)->map(fn ($r) => ['kind' => 'requisition', 'title' => "Requisition {$r['item']}", 'farm' => $r['farm'],
                'payload' => ['lines' => [['stock_item_id' => $r['item_id'], 'quantity' => $r['suggest']]], 'justification' => "Runs out in {$r['days_left']} days; harvest starts {$r['harvest_from']}."],
                'reason' => "{$r['item']}: {$r['days_left']} days left at {$r['per_day']}/day, harvest from {$r['harvest_from']}."])->values()->all(),
            'summary' => $rows->isEmpty() ? 'Nothing is forecast to run out before the next harvest.' : $rows->count().' item(s) may run out before harvest; the first is '.$rows->sortBy('days_left')->first()['item'].'.'];
    }

    private function lateWorkOrders($farms): array
    {
        $wos = \App\Modules\Agri\Models\FarmWorkOrder::query()->whereIn('status', [\App\Modules\Agri\Models\FarmWorkOrder::APPROVED, \App\Modules\Agri\Models\FarmWorkOrder::IN_PROGRESS, \App\Modules\Agri\Models\FarmWorkOrder::PAUSED])
            ->where('planned_end', '<', now())->with('farm')->orderBy('planned_end')->get();
        $rows = $wos->map(fn ($w) => ['farm' => $w->farm?->name, 'work_order' => $w->reference, 'title' => $w->title, 'status' => $w->status,
            'due' => $w->planned_end?->format('Y-m-d H:i'), 'late_by_hours' => (int) round($w->planned_end->diffInMinutes(now()) / 60), 'url' => "/agri/work-orders/{$w->uuid}"])->all();

        return ['columns' => ['farm', 'work_order', 'title', 'status', 'due', 'late_by_hours'], 'rows' => $rows,
            'sources' => ['Approved, started and paused work orders and their planned end'], 'assumptions' => ['Behind schedule = still open after its planned end.'],
            'flags' => [], 'recommendations' => [],
            'summary' => $wos->isEmpty() ? 'No work order is behind schedule.' : "{$wos->count()} work order(s) are past their planned end; the oldest is {$wos->first()->reference}."];
    }

    private function labourPerKg($farms): array
    {
        $season = \App\Modules\Agri\Models\CropSeason::query()->where('status', 'open')->orderBy('starts_on')->first();
        $from = $season?->starts_on ?? today()->startOfYear();
        $rows = collect();
        foreach ($farms as $farm) {
            foreach (app(WorkforceAnalytics::class)->costByCrop($farm, $from, today()) as $r) {
                $plan = CropPlan::query()->where('farm_id', $farm->id)->where('code', $r['crop'])->first();
                $kg = $plan ? \App\Modules\Agri\Models\HarvestRecord::query()->where('crop_plan_id', $plan->id)->get()
                    ->sum(fn ($h) => match ($h->unit) { 'kg' => (float) $h->quantity, 't' => (float) $h->quantity * 1000, default => 0 }) : 0;
                $rows->push(['farm' => $farm->name, 'plan' => $r['crop'], 'labour_cost' => $r['labour_cost'], 'harvested_kg' => round($kg, 1),
                    'per_kg' => $kg > 0 ? round($r['labour_cost'] / $kg, 3) : null]);
            }
        }
        $kg = $rows->sum('harvested_kg');
        $cost = $rows->where('harvested_kg', '>', 0)->sum('labour_cost');

        return ['columns' => ['farm', 'plan', 'labour_cost', 'harvested_kg', 'per_kg'], 'rows' => $rows->all(),
            'period' => $from->toDateString().' to '.today()->toDateString().($season ? " ({$season->name})" : ''),
            'sources' => ['Approved timesheets and piecework charged to each crop', 'Harvest records in kg or tonnes'],
            'assumptions' => ['All of a crop\'s labour this season is spread over what it has yielded so far.', 'Harvests recorded in other units are left out.'],
            'flags' => $rows->whereNull('per_kg')->count() ? [$this->flag('INSUFFICIENT', $rows->whereNull('per_kg')->count().' crop(s) have labour but no harvest in kg yet.')] : [],
            'recommendations' => [],
            'summary' => $kg > 0 ? 'R'.number_format($cost / $kg, 3).' of labour per kg harvested this season, over '.number_format($kg).' kg.' : 'Nothing has been harvested in kg this season yet.'];
    }

    private function buyerPayments($farms): array
    {
        $sales = app(FarmSales::class);
        // Buyers are the Accounting customers the farms have invoiced.
        $partyIds = \App\Modules\Finance\Accounting\Models\FinInvoice::query()->where('direction', 'receivable')
            ->whereIn(\Illuminate\Support\Facades\DB::raw("dimensions->>'agri_farm'"), collect($farms)->map(fn ($f) => (string) $f->id)->all())->distinct()->pluck('party_id');
        $rows = \App\Modules\Finance\Accounting\Models\FinParty::query()->whereIn('id', $partyIds)->get()->map(fn ($b) => ['buyer' => $b->name] + collect($sales->statement($b))->except('invoices')->all())
            ->filter(fn ($r) => $r['outstanding'] > 0)->sortByDesc('overdue')->values();

        return ['columns' => ['buyer', 'invoiced', 'paid', 'outstanding', 'overdue'], 'rows' => $rows->all(),
            'sources' => ['Finance sales invoices and receipts for each farm buyer'], 'assumptions' => ['Overdue = past the invoice due date and not fully paid.'],
            'flags' => [], 'recommendations' => [],
            'summary' => $rows->isEmpty() ? 'No farm buyer owes anything.' : $rows->count().' buyer(s) owe R'.number_format($rows->sum('outstanding'), 2).', R'.number_format($rows->sum('overdue'), 2).' of it overdue.'];
    }

    private function livestockCare($farms): array
    {
        $rows = collect();
        foreach ($farms as $farm) {
            foreach (app(LivestockIntelligence::class)->vaccinationsDue($farm, 14) as $r) {
                $rows->push(['farm' => $farm->name, 'programme' => $r['programme'], 'who' => $r['who'], 'due_on' => $r['due_on'], 'overdue' => $r['overdue'] ? 'yes' : '']);
            }
        }

        return ['columns' => ['farm', 'programme', 'who', 'due_on', 'overdue'], 'rows' => $rows->sortBy('due_on')->values()->all(),
            'sources' => ['Vaccination programmes and the treatments recorded against each group and animal'], 'assumptions' => ['Due in the next 14 days, or overdue.'],
            'flags' => [], 'recommendations' => [],
            'drafts' => $rows->take(5)->map(fn ($r) => ['kind' => 'work_order', 'title' => "{$r['programme']}: {$r['who']}", 'farm' => $r['farm'],
                'payload' => ['category' => 'vaccination', 'title' => "{$r['programme']}: {$r['who']}"], 'reason' => "{$r['programme']} due {$r['due_on']}."])->values()->all(),
            'summary' => $rows->isEmpty() ? 'No livestock care is due in the next two weeks.' : $rows->count().' scheduled treatment(s) due in the next two weeks or overdue.'];
    }

    private function machineRisk($farms): array
    {
        $risk = app(MachineRisk::class);
        $rows = collect();
        foreach ($farms as $farm) {
            foreach ($risk->forFarm($farm) as $a) {
                $rows->push(['farm' => $farm->name, 'machine' => "{$a['machine']['code']} {$a['machine']['name']}", 'machine_id' => $a['machine']['id'], 'score' => $a['score'], 'risk' => $a['risk'],
                    'confidence' => $a['confidence'], 'factors' => collect($a['factors'])->pluck('factor')->implode('; ')]);
            }
        }
        $rows = $rows->sortByDesc('score')->values();
        $high = $rows->where('risk', 'high');

        return ['columns' => ['farm', 'machine', 'score', 'risk', 'confidence', 'factors'], 'rows' => $rows->all(),
            'sources' => ['Service schedules, faults, pre-start checks and hour meters of each machine'],
            'assumptions' => ['A points score that ranks machines for inspection. It does not say when or whether a machine will fail.'],
            'flags' => $rows->where('confidence', 'low')->count() ? [$this->flag('LIMITED', $rows->where('confidence', 'low')->count().' machine(s) have little history, so their scores are rough.')] : [],
            'recommendations' => [],
            'drafts' => $high->take(5)->map(fn ($r) => ['kind' => 'inspection', 'title' => "Inspect {$r['machine']}", 'farm' => $r['farm'],
                'payload' => ['machine_id' => $r['machine_id']], 'reason' => "Risk {$r['score']}/100: {$r['factors']}."])->values()->all(),
            'summary' => $rows->isEmpty() ? 'No machines on your farms.' : ($high->isEmpty() ? 'No machine stands out for inspection.' : $high->count().' machine(s) should be inspected before their next job; first '.$high->first()['machine'].'.')];
    }

    private function empty(?string $topic, string $summary, bool $refused = false): array
    {
        return ['topic' => $topic, 'title' => $topic ? self::TOPICS[$topic][0] : 'Farm assistant', 'refused' => $refused, 'columns' => [], 'rows' => [],
            'sources' => [], 'flags' => [], 'recommendations' => [], 'summary' => $summary, 'read_only' => true];
    }

    private function flag(string $level, string $message): array
    {
        return ['level' => $level, 'message' => $message];
    }
}
