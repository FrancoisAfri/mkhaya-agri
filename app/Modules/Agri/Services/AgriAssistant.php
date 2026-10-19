<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmDocument;
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
    ];

    private const KEYWORDS = [
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

        return $a + ['topic' => $topic, 'title' => self::TOPICS[$topic][0], 'refused' => false,
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
        $docs = FarmDocument::query()->where('status', 'active')->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', today()->addDays((int) config('agri.alert_horizon_days', 30)))->with('farm')->orderBy('expires_on')->get();
        $rows = $docs->map(fn ($d) => ['farm' => $d->farm->name, 'document' => $d->title, 'type' => str_replace('_', ' ', $d->document_type),
            'expires_on' => $d->expires_on->toDateString(), 'state' => $d->expiryState()])->all();
        $expired = $docs->filter(fn ($d) => $d->expiryState() === 'expired')->count();

        return ['columns' => ['farm', 'document', 'type', 'expires_on', 'state'], 'rows' => $rows, 'sources' => ['Farm compliance documents'],
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
