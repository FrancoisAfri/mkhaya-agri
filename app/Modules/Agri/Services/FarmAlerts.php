<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Services\PostHarvest;
use App\Modules\Agri\Services\ProduceDispatches;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Farm alerts (spec Module I/J): the conditions somebody must act on, raised once each.
 *
 * Every condition has a dedupe key (kind + record). A scan refreshes the open alert for a
 * condition that still holds rather than adding another, and resolves the ones whose condition has
 * cleared — so a daily scan never piles up duplicates, and an alert disappears when the problem
 * does. An acknowledged alert stays acknowledged while its condition holds.
 */
class FarmAlerts
{
    public function __construct(private readonly StockLedger $stock) {}

    /** Scan every farm of the current organisation. Runs as the system: farm access does not narrow it. */
    public function scan(?int $companyId = null): array
    {
        $horizon = (int) config('agri.alert_horizon_days', 30);
        $farms = Farm::withoutGlobalScope('farm_access')->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', Farm::ACTIVE)->get();
        $raised = 0;
        $resolved = 0;

        $home = $farms->sortBy('id')->first();
        foreach ($farms as $farm) {
            $seen = [];
            $conditions = $this->conditions($farm, $horizon);
            if ($home && $farm->id === $home->id) {
                array_push($conditions, ...$this->organisationConditions((int) $farm->company_id, $farm));
            }
            foreach ($conditions as $c) {
                $seen[] = $c['dedupe_key'];
                $raised += $this->upsert($farm, $c) ? 1 : 0;
            }
            $resolved += FarmAlert::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)
                ->whereIn('status', [FarmAlert::OPEN, FarmAlert::ACKNOWLEDGED])
                ->when($seen, fn ($q) => $q->whereNotIn('dedupe_key', $seen))
                ->update(['status' => FarmAlert::RESOLVED, 'resolved_at' => now(), 'updated_at' => now()]);
        }

        return ['farms' => $farms->count(), 'raised' => $raised, 'resolved' => $resolved];
    }

    /** Scan at most once every few minutes per organisation, so a dashboard visit is never stale for long. */
    public function scanIfStale(int $companyId, int $minutes = 10): void
    {
        Cache::remember("agri_alert_scan:{$companyId}", now()->addMinutes($minutes), function () use ($companyId) {
            $this->scan($companyId);

            return now()->toIso8601String();
        });
    }

    /** @return list<array{kind: string, severity: string, title: string, message: ?string, link: ?string, due_on: ?string, dedupe_key: string}> */
    public function conditions(Farm $farm, int $horizon): array
    {
        $out = [];
        $today = today();
        $f = fn ($q) => $q->withoutGlobalScope('farm_access')->where('farm_id', $farm->id);

        // Low stock in the farm store.
        if ($farm->warehouse_id) {
            $items = FinStockItem::withoutGlobalScopes()->where('company_id', $farm->company_id)
                ->where('reorder_level', '>', 0)->where('is_active', true)->get();
            foreach ($items as $item) {
                $avail = $this->stock->available($item, (int) $farm->warehouse_id);
                $hasHistory = DB::table('fin_stock_movements')->where('item_id', $item->id)->where('warehouse_id', $farm->warehouse_id)->exists();
                if ($hasHistory && $avail < (float) $item->reorder_level) {
                    $out[] = $this->c('low_stock', $avail <= 0 ? 'critical' : 'warning', "Low stock: {$item->name}",
                        sprintf('%s %s left in the farm store; reorder level is %s.', round($avail, 2), $item->uom, round((float) $item->reorder_level, 2)),
                        '/agri/stock?farm='.$farm->uuid, null, "low_stock:{$farm->id}:{$item->id}");
                }
            }
        }

        foreach ($f(FarmMachine::query())->where('status', '!=', FarmMachine::DISPOSED)->get() as $m) {
            if ($m->serviceDue(14, 10)) {
                $overdue = ($m->next_service_hours !== null && (float) $m->hours_reading >= (float) $m->next_service_hours)
                    || ($m->next_service_on && $m->next_service_on->lt($today));
                $out[] = $this->c('service_due', $overdue ? 'critical' : 'warning', "Service due: {$m->code} {$m->name}",
                    trim(($m->next_service_hours !== null ? "Due at {$m->next_service_hours} h (now {$m->hours_reading} h). " : '').
                        ($m->next_service_on ? 'Due by '.$m->next_service_on->toDateString().'.' : '')),
                    '/agri/machinery?farm='.$farm->uuid, $m->next_service_on?->toDateString(), "service_due:{$m->id}");
            }
        }

        // Farm records (Document Management) that have expired or are about to.
        foreach (FarmDocuments::expiring([$farm->id], $horizon) as ['document' => $d, 'state' => $state]) {
            $out[] = $this->c('document_expiry', $state === 'expired' ? 'critical' : 'warning',
                ($state === 'expired' ? 'Expired: ' : 'Expiring: ').$d->title,
                ucfirst(str_replace('_', ' ', (string) $d->document_type)).' '.($state === 'expired' ? 'expired on ' : 'expires on ').$d->expiry_date->toDateString().'.',
                FarmDocuments::url($d), $d->expiry_date->toDateString(), "document_expiry:{$d->id}");
        }

        foreach ($f(FarmTask::query())->whereIn('status', FarmTask::PENDING)->whereDate('due_on', '<', $today)->get() as $t) {
            $out[] = $this->c('task_overdue', $t->priority === 'urgent' ? 'critical' : 'warning', "Overdue task: {$t->title}",
                'Was due '.$t->due_on->toDateString().'.', '/agri/tasks?farm='.$farm->uuid, $t->due_on->toDateString(), "task_overdue:{$t->id}");
        }

        // Scouting that is due a follow-up, and fields nobody may enter yet after a spray (re-entry interval).
        foreach ($f(\App\Modules\Agri\Models\CropObservation::query())->where('status', 'open')->where('action_required', true)
            ->whereNotNull('follow_up_on')->whereDate('follow_up_on', '<=', $today)->get() as $o) {
            $out[] = $this->c('scouting_follow_up', in_array($o->severity, ['high', 'critical'], true) ? 'critical' : 'warning',
                'Scouting follow-up due: '.str_replace('_', ' ', $o->kind), mb_strimwidth((string) $o->description, 0, 200),
                '/manufacturing/agriculture/crops/scouting?farm='.$farm->uuid, $o->follow_up_on->toDateString(), "scouting_follow_up:{$o->id}");
        }
        $sprayed = \App\Modules\Agri\Models\ActivityInput::withoutGlobalScope('farm_access')->where('activity_inputs.farm_id', $farm->id)
            ->whereNotNull('activity_inputs.reentry_hours')->whereNull('activity_inputs.reversal_of_id')
            ->join('field_activities as a', 'a.id', '=', 'activity_inputs.activity_id')->whereNotNull('a.completed_at')
            ->where('a.completed_at', '>=', now()->subDays(14))->get(['activity_inputs.id', 'activity_inputs.reentry_hours', 'a.completed_at', 'a.field_id', 'a.title']);
        foreach ($sprayed as $r) {
            $until = \Illuminate\Support\Carbon::parse($r->completed_at)->addHours((int) $r->reentry_hours);
            if ($until->isFuture()) {
                $code = $r->field_id ? \App\Modules\Agri\Models\FarmField::withoutGlobalScopes()->whereKey($r->field_id)->value('code') : 'the field';
                $out[] = $this->c('reentry', 'warning', "No entry: {$code} until ".$until->format('D H:i'),
                    "Re-entry interval after {$r->title}. Workers stay out without protective equipment until then.", null,
                    $until->toDateString(), "reentry:{$r->id}");
            }
        }

        // Irrigation problems nobody has resolved, and buyer commitments due or short.
        foreach ($f(\App\Modules\Agri\Models\IrrigationException::query())->whereNull('resolved_at')->get() as $x) {
            $out[] = $this->c('irrigation_issue', in_array($x->kind, ['leak', 'no_water', 'fault'], true) ? 'critical' : 'warning',
                'Irrigation: '.str_replace('_', ' ', $x->kind), mb_strimwidth((string) $x->note, 0, 200), '/manufacturing/agriculture/crops/irrigation?farm='.$farm->uuid, null, "irrigation_issue:{$x->id}");
        }
        foreach ($f(\App\Modules\Agri\Models\HarvestCommitment::query())->whereIn('status', ['open', 'short'])
            ->whereDate('deliver_on', '<=', $today->copy()->addDays(3))->get() as $c) {
            $left = round($c->quantity - $c->delivered_quantity, 3);
            $out[] = $this->c('commitment_due', $c->deliver_on->lt($today) ? 'critical' : 'warning',
                ($c->deliver_on->lt($today) ? 'Short: ' : 'Due: ')."{$c->buyer} {$left} {$c->unit}",
                'To deliver by '.$c->deliver_on->toDateString().($c->grade ? ", grade {$c->grade}" : '').'.', '/manufacturing/agriculture/crops/harvest?farm='.$farm->uuid,
                $c->deliver_on->toDateString(), "commitment_due:{$c->id}");
        }

        // Livestock: vaccinations overdue under a programme, and animals or groups in quarantine.
        // One alert per programme, naming who is overdue — not one per animal, which would bury everything else.
        foreach (collect(app(LivestockIntelligence::class)->vaccinationsDue($farm, 0))->where('overdue', true)->groupBy('programme') as $programme => $due) {
            $who = $due->pluck('who');
            $out[] = $this->c('vaccination_due', 'warning', "Vaccination overdue: {$programme} ({$who->count()})",
                $who->take(12)->implode(', ').($who->count() > 12 ? ' and '.($who->count() - 12).' more' : '').' — due since '.$due->min('due_on').'.',
                '/manufacturing/agriculture/livestock?farm='.$farm->uuid, $due->min('due_on'), 'vaccination_due:'.$farm->id.':'.md5($programme));
        }
        foreach ([\App\Modules\Agri\Models\Animal::class, \App\Modules\Agri\Models\LivestockGroup::class] as $cls) {
            foreach ($f($cls::query())->whereDate('quarantine_until', '>=', $today)->get() as $q) {
                $who = $q instanceof \App\Modules\Agri\Models\Animal ? $q->tag_number : "group {$q->code}";
                $out[] = $this->c('quarantine', 'critical', "Quarantine: {$who}", 'No movement off the farm or sale until '.$q->quarantine_until->toDateString().'.',
                    '/manufacturing/agriculture/livestock?farm='.$farm->uuid, $q->quarantine_until->toDateString(), 'quarantine:'.class_basename($cls).":{$q->id}");
            }
        }

        // Jobs past their planned finish, and problems from the field nobody has resolved yet.
        foreach ($f(\App\Modules\Agri\Models\FarmWorkOrder::query())->whereIn('status', \App\Modules\Agri\Models\FarmWorkOrder::OPEN)
            ->where('status', '!=', 'draft')->where('planned_end', '<', now())->get() as $wo) {
            $out[] = $this->c('work_order_overdue', $wo->priority === 'urgent' ? 'critical' : 'warning', "Overdue job: {$wo->reference} {$wo->title}",
                'Planned to finish '.$wo->planned_end->format('Y-m-d H:i').'; now '.str_replace('_', ' ', $wo->status).'.',
                "/agri/work-orders/{$wo->uuid}", $wo->planned_end->toDateString(), "work_order_overdue:{$wo->id}");
        }
        foreach ($f(\App\Modules\Agri\Models\WorkOrderLog::query())->where('kind', 'issue')->whereNull('resolved_at')
            ->whereIn('issue_type', ['incident', 'hazard', 'equipment_failure'])->with('workOrder')->get() as $l) {
            $out[] = $this->c('field_issue', $l->severity === 'high' ? 'critical' : 'warning',
                ucfirst(str_replace('_', ' ', $l->issue_type)).' reported on '.($l->workOrder?->reference ?? 'a job'),
                mb_strimwidth($l->note ?: 'Reported from the field.', 0, 200), $l->workOrder ? "/agri/work-orders/{$l->workOrder->uuid}" : null,
                $l->logged_at?->toDateString(), "field_issue:{$l->id}");
        }

        foreach ($f(AnimalHealthRecord::query())->whereDate('withdrawal_until', '>', $today)->with(['animal', 'group'])->get() as $h) {
            $who = $h->animal?->tag_number ?? $h->group?->code ?? 'livestock';
            $out[] = $this->c('withdrawal', 'info', "Withdrawal period: {$who}",
                "No sale, slaughter or milk before {$h->withdrawal_until->toDateString()} ({$h->product_name}).",
                '/manufacturing/agriculture/livestock?farm='.$farm->uuid, $h->withdrawal_until->toDateString(), "withdrawal:{$h->id}");
        }

        foreach ($f(AnimalHealthRecord::query())->whereNotNull('next_due_on')->whereDate('next_due_on', '<=', $today->copy()->addDays(7))
            ->whereDate('next_due_on', '>=', $today->copy()->subDays(30))->with(['animal', 'group'])->get() as $h) {
            $who = $h->animal?->tag_number ?? $h->group?->code ?? 'livestock';
            $out[] = $this->c('health_due', $h->next_due_on->lt($today) ? 'warning' : 'info', "Health follow-up due: {$who}",
                ucfirst($h->event_type).' follow-up due '.$h->next_due_on->toDateString().'.',
                '/manufacturing/agriculture/livestock?farm='.$farm->uuid, $h->next_due_on->toDateString(), "health_due:{$h->id}");
        }

        foreach ($f(CropObservation::query())->where('status', 'open')->whereIn('severity', ['high', 'critical'])->get() as $o) {
            $out[] = $this->c('observation', $o->severity === 'critical' ? 'critical' : 'warning',
                ucfirst(str_replace('_', ' ', $o->kind)).' reported ('.$o->severity.')', mb_strimwidth($o->description, 0, 200, '…'),
                '/manufacturing/agriculture/crops?farm='.$farm->uuid, $o->observed_on->toDateString(), "observation:{$o->id}");
        }

        foreach ($f(CropPlan::query())->where('status', CropPlan::PLANTED)->whereDate('planned_harvest_on', '<=', $today->copy()->addDays(7))->get() as $p) {
            $out[] = $this->c('harvest_due', $p->planned_harvest_on->lt($today) ? 'warning' : 'info', "Harvest due: {$p->code}",
                'Planned harvest '.$p->planned_harvest_on->toDateString().'.', '/manufacturing/agriculture/crops/plans/'.$p->uuid, $p->planned_harvest_on->toDateString(), "harvest_due:{$p->id}");
        }

        // Spend above budget on a crop plan.
        $plans = $f(CropPlan::query())->whereNotNull('budget_amount')->whereIn('status', CropPlan::WORKABLE)->get();
        if ($plans->isNotEmpty()) {
            $spent = DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.company_id', $farm->company_id)->where('a.type', 'expense')
                ->whereIn(DB::raw("l.dimensions->>'agri_enterprise'"), $plans->map(fn ($p) => 'crop_plan:'.$p->id)->all())
                ->groupBy(DB::raw("l.dimensions->>'agri_enterprise'"))
                ->selectRaw("l.dimensions->>'agri_enterprise' as e, SUM(l.base_debit - l.base_credit) as spent")->pluck('spent', 'e');
            foreach ($plans as $p) {
                $s = (float) ($spent['crop_plan:'.$p->id] ?? 0);
                if ($s > (float) $p->budget_amount) {
                    $out[] = $this->c('budget_overrun', 'warning', "Over budget: {$p->code}",
                        sprintf('Spent %s against a budget of %s.', number_format($s, 2), number_format((float) $p->budget_amount, 2)),
                        '/agri/finance?farm='.$farm->uuid, null, "budget_overrun:{$p->id}");
                }
            }
        }

        // Phase 2 §12.1: compliance obligations overdue, without evidence, or due soon.
        foreach (app(FarmCompliance::class)->conditions($farm) as $c) {
            $out[] = $c;
        }
        // Phase 2 §11.2: weather that meets an approved rule and something planned; provider warnings.
        foreach (app(WeatherIntelligence::class)->conditions($farm) as $c) {
            $out[] = $c;
        }
        // Every other part of the farm: machines, cold stores, produce, recalls, deliveries, the roster and time, irrigation.
        foreach ([fn () => $this->machines($farm, $f, $today, $horizon), fn () => $this->produce($farm, $f, $today), fn () => $this->people($farm, $f, $today),
            fn () => $this->irrigationDue($farm)] as $area) {
            try {
                array_push($out, ...$area());
            } catch (\Throwable $e) {
                report($e);   // one area failing never stops the others being checked
            }
        }

        return $out;
    }

    /** Machines stopped by a fault, open serious faults, and warranties about to end. */
    private function machines(Farm $farm, callable $f, Carbon $today, int $horizon): array
    {
        $out = [];
        $code = fn (?int $id) => FarmMachine::withoutGlobalScopes()->whereKey($id)->first(['fleet_number', 'name']);
        foreach ($f(\App\Modules\Agri\Models\MachineFault::query())->where('status', '!=', 'resolved')->whereIn('severity', ['major', 'critical'])->get() as $x) {
            $m = $code($x->machine_id);
            $out[] = $this->c('machine_fault', $x->stops_machine ? 'critical' : 'warning', ($x->stops_machine ? 'Machine stopped: ' : 'Machine fault: ')."{$m?->code} {$m?->name}",
                $x->description, '/agri/machinery/fleet/maintenance?farm='.$farm->uuid, null, "machine_fault:{$x->id}");
        }
        foreach ($f(FarmMachine::query())->where('status', '!=', FarmMachine::DISPOSED)->whereNotNull('warranty_until')
            ->whereBetween('warranty_until', [$today->toDateString(), $today->copy()->addDays($horizon)->toDateString()])->get() as $m) {
            $out[] = $this->c('warranty', 'info', "Warranty ends {$m->warranty_until->toDateString()}: {$m->code} {$m->name}",
                'Claim anything still under warranty before then.', '/agri/machinery?farm='.$farm->uuid, $m->warranty_until->toDateString(), "warranty:{$m->id}");
        }

        return $out;
    }

    /** Zones past their allowed depletion in the water balance with no irrigation planned in the next two days. */
    private function irrigationDue(Farm $farm): array
    {
        $out = [];
        foreach (app(WaterBalance::class)->pastThreshold($farm) as $z) {
            $where = $z['field'].($z['zone'] ? " · {$z['zone']}" : '');
            $out[] = $this->c('irrigation_due', $z['depletion'] >= ($z['raw'] + $z['taw']) / 2 ? 'critical' : 'warning', "Irrigation due: {$where}",
                sprintf('Root zone %s mm dry against %s mm readily available water on %s, and no irrigation is planned in the next two days.', round($z['depletion'], 1), round($z['raw'], 1), $z['on']),
                '/manufacturing/agriculture/crops/intelligence/irrigation?farm='.$farm->uuid, $z['on'], "irrigation_due:{$z['scope']}");
        }

        return $out;
    }

    /** Cold stores out of range with nothing done, produce held back, recalls still open, and deliveries due. */
    private function produce(Farm $farm, callable $f, Carbon $today): array
    {
        $out = [];
        foreach ($f(\App\Modules\Agri\Models\StorageReading::query())->where('out_of_range', true)->whereNull('corrective_action')
            ->where('recorded_at', '>=', $today->copy()->subDays(14))->get() as $r) {
            $store = \App\Modules\Agri\Models\FarmStore::withoutGlobalScopes()->whereKey($r->store_id)->value('name');
            $out[] = $this->c('cold_store', 'critical', "Out of range: {$store}", "{$r->deviation} at ".$r->recorded_at?->format('Y-m-d H:i').'. Record what was done about it.',
                '/manufacturing/agriculture/packhouse/storage?farm='.$farm->uuid, null, "cold_store:{$r->id}");
        }
        $held = $f(\App\Modules\Agri\Models\ProduceLot::query())->where('status', 'quarantined')->where('remaining', '>', 0)->get();
        if ($held->isNotEmpty()) {
            $out[] = $this->c('quarantine', 'warning', $held->count().' produce lot(s) held in quarantine', $held->pluck('code')->take(8)->implode(', ').' — release, rework or write off.',
                '/manufacturing/agriculture/packhouse/lots?farm='.$farm->uuid, null, "quarantine:{$farm->id}");
        }
        foreach (app(PostHarvest::class)->recallsFor($farm)->where('status', \App\Modules\Operations\Manufacturing\Models\Recall::OPEN)->get() as $r) {
            $out[] = $this->c('recall', 'critical', "Recall open: {$r->number}", $r->title.' — '.$r->reason, "/manufacturing/quality/recalls/{$r->uuid}", null, "recall:{$r->id}");
        }
        foreach (ProduceDispatches::ordersFor($farm)->whereIn('status', ['open', 'part_delivered'])->whereNotNull('deliver_on')
            ->whereDate('deliver_on', '<=', $today->copy()->addDays(2))->with('party:id,name')->get() as $o) {
            $late = $o->deliver_on->lt($today);
            $out[] = $this->c('delivery_due', $late ? 'warning' : 'info', ($late ? 'Delivery overdue: ' : 'Delivery due: ')."{$o->number} for {$o->party?->name}",
                'Due '.$o->deliver_on->toDateString().'. Pick it from the packhouse dispatch screen.', '/manufacturing/agriculture/packhouse/dispatch?farm='.$farm->uuid, $o->deliver_on->toDateString(), "delivery_due:{$o->id}");
        }

        return $out;
    }

    /** The roster in the coming week, and time and piecework waiting for a decision. */
    private function people(Farm $farm, callable $f, Carbon $today): array
    {
        $out = [];
        $week = [$today->copy()->startOfDay(), $today->copy()->addDays(7)->endOfDay()];
        foreach ($f(\App\Modules\HR\Hr\Attendance\Models\RosteredShift::query())->where('status', '!=', 'cancelled')->whereBetween('starts_at', $week)
            ->withCount('activeAssignments')->get() as $s) {
            if ($s->min_staff && $s->active_assignments_count < $s->min_staff) {
                $out[] = $this->c('roster_gap', 'warning', "Short-staffed: {$s->title} ".$s->starts_at->format('D j M H:i'),
                    "{$s->active_assignments_count} of {$s->min_staff} people assigned.", '/attendance/roster?farm='.$farm->uuid, $s->starts_at->toDateString(), "roster_gap:{$s->id}");
            }
        }
        $drafts = $f(\App\Modules\HR\Hr\Attendance\Models\RosteredShift::query())->where('status', 'draft')->whereBetween('starts_at', [$week[0], $today->copy()->addDays(2)->endOfDay()])->count();
        if ($drafts) {
            $out[] = $this->c('roster_draft', 'warning', "{$drafts} shift(s) in the next two days are not published", 'Nobody has been told about them yet.',
                '/attendance/roster?farm='.$farm->uuid, null, "roster_draft:{$farm->id}");
        }
        $count = fn (string $cls, array $where, string $dateCol) => $f($cls::query())->where($where)->whereDate($dateCol, '<', $today->copy()->subDays(2))->count();
        $waiting = [
            ['timesheets', $count(\App\Modules\HR\Hr\Attendance\Models\Timesheet::class, ['status' => 'complete'], 'work_date'), 'timesheet(s) waiting for approval', '/attendance/timesheets'],
            ['piecework', $count(\App\Modules\HR\Hr\Attendance\Models\PieceworkEntry::class, ['status' => 'submitted'], 'work_date'), 'piecework entr(ies) waiting for approval', '/attendance/piecework'],
            ['corrections', $f(\App\Modules\HR\Hr\Attendance\Models\Correction::query())->where('status', 'requested')->count(), 'clock correction(s) waiting for review', '/attendance/timesheets'],
            ['shift_swap', $f(\App\Modules\HR\Hr\Attendance\Models\ShiftSwap::query())->where('status', 'requested')->count(), 'shift swap(s) waiting for a decision', '/attendance/roster'],
        ];
        foreach ($waiting as [$kind, $n, $what, $path]) {
            if ($n) {
                $out[] = $this->c($kind, 'warning', "{$n} {$what}", 'Payroll and labour costs wait on them.', "{$path}?farm={$farm->uuid}", null, "{$kind}:{$farm->id}");
            }
        }

        return $out;
    }

    /**
     * What concerns the whole organisation rather than one farm — cash running short, a payroll
     * handoff that does not match, messages from devices and services that failed. Raised on the
     * organisation's first farm, so the owner and finance see them with the rest.
     */
    public function organisationConditions(int $companyId, Farm $home): array
    {
        $out = [];
        try {
            $cash = app(FarmCashFlow::class)->forecast($companyId, 13);
            if ($cash['first_shortage'] ?? null) {
                $out[] = $this->c('cash', 'critical', 'Cash runs short in the week of '.$cash['first_shortage'],
                    'The 13-week forecast goes below zero (lowest R'.number_format((float) ($cash['lowest'] ?? 0), 0, '.', ' ').').', '/agri/finance/intelligence/cashflow', null, "cash:{$companyId}");
            }
        } catch (\Throwable $e) {
            report($e);
        }
        $mismatch = \App\Modules\HR\Hr\Attendance\Models\PayrollHandoffLine::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'mismatch')->count();
        $unsent = \App\Modules\HR\Hr\Attendance\Models\PayrollHandoff::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'prepared')
            ->where('created_at', '<', now()->subDays(3))->count();
        if ($mismatch || $unsent) {
            $out[] = $this->c('payroll', $mismatch ? 'critical' : 'warning', $mismatch ? "{$mismatch} payroll handoff line(s) do not match what payroll paid" : "{$unsent} payroll handoff(s) prepared but not sent",
                'Approved time and piecework are not yet settled with payroll.', '/attendance/payroll-handoff', null, "payroll:{$companyId}");
        }
        $failed = \App\Modules\Agri\Models\IntegrationEvent::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', ['failed', 'dead'])->get(['status', 'integration']);
        if ($failed->isNotEmpty()) {
            $dead = $failed->where('status', 'dead')->count();
            $out[] = $this->c('integration', $dead ? 'critical' : 'warning', $failed->count().' integration message(s) failed',
                $failed->pluck('integration')->unique()->implode(', ').($dead ? " — {$dead} gave up after retries." : ' — they are being retried.'), '/agri/integrations', null, "integration:{$companyId}");
        }

        return $out;
    }

    private function upsert(Farm $farm, array $c): bool
    {
        $existing = FarmAlert::withoutGlobalScope('farm_access')->where('company_id', $farm->company_id)->where('dedupe_key', $c['dedupe_key'])->first();
        if ($existing) {
            $reopen = $existing->status === FarmAlert::RESOLVED;
            $existing->forceFill(array_diff_key($c, ['dedupe_key' => 1]) + [
                'last_seen_at' => now(),
                'status' => $reopen ? FarmAlert::OPEN : $existing->status,
                'resolved_at' => $reopen ? null : $existing->resolved_at,
            ])->saveQuietly();

            return $reopen;
        }
        FarmAlert::withoutEvents(fn () => FarmAlert::create($c + [
            'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'status' => FarmAlert::OPEN, 'last_seen_at' => now(),
            'uuid' => (string) \Illuminate\Support\Str::uuid7(),
        ]));

        return true;
    }

    private function c(string $kind, string $severity, string $title, ?string $message, ?string $link, ?string $due, string $key): array
    {
        return ['kind' => $kind, 'severity' => $severity, 'title' => mb_strimwidth($title, 0, 200, '…'), 'message' => $message ? mb_strimwidth($message, 0, 500, '…') : null,
            'link' => $link, 'due_on' => $due, 'dedupe_key' => $key];
    }
}
