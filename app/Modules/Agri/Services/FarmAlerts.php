<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmDocument;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmTask;
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

        foreach ($farms as $farm) {
            $seen = [];
            foreach ($this->conditions($farm, $horizon) as $c) {
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

        foreach ($f(FarmDocument::query())->where('status', 'active')->whereNotNull('expires_on')->get() as $d) {
            $state = $d->expiryState();
            if (in_array($state, ['expired', 'expiring'], true)) {
                $out[] = $this->c('document_expiry', $state === 'expired' ? 'critical' : 'warning',
                    ($state === 'expired' ? 'Expired: ' : 'Expiring: ').$d->title,
                    ucfirst(str_replace('_', ' ', $d->document_type)).' '.($state === 'expired' ? 'expired on ' : 'expires on ').$d->expires_on->toDateString().'.',
                    '/agri/documents?farm='.$farm->uuid, $d->expires_on->toDateString(), "document_expiry:{$d->id}");
            }
        }

        foreach ($f(FarmTask::query())->whereIn('status', FarmTask::PENDING)->whereDate('due_on', '<', $today)->get() as $t) {
            $out[] = $this->c('task_overdue', $t->priority === 'urgent' ? 'critical' : 'warning', "Overdue task: {$t->title}",
                'Was due '.$t->due_on->toDateString().'.', '/agri/tasks?farm='.$farm->uuid, $t->due_on->toDateString(), "task_overdue:{$t->id}");
        }

        foreach ($f(AnimalHealthRecord::query())->whereDate('withdrawal_until', '>', $today)->with(['animal', 'group'])->get() as $h) {
            $who = $h->animal?->tag_number ?? $h->group?->code ?? 'livestock';
            $out[] = $this->c('withdrawal', 'info', "Withdrawal period: {$who}",
                "No sale, slaughter or milk before {$h->withdrawal_until->toDateString()} ({$h->product_name}).",
                '/agri/livestock?farm='.$farm->uuid, $h->withdrawal_until->toDateString(), "withdrawal:{$h->id}");
        }

        foreach ($f(AnimalHealthRecord::query())->whereNotNull('next_due_on')->whereDate('next_due_on', '<=', $today->copy()->addDays(7))
            ->whereDate('next_due_on', '>=', $today->copy()->subDays(30))->with(['animal', 'group'])->get() as $h) {
            $who = $h->animal?->tag_number ?? $h->group?->code ?? 'livestock';
            $out[] = $this->c('health_due', $h->next_due_on->lt($today) ? 'warning' : 'info', "Health follow-up due: {$who}",
                ucfirst($h->event_type).' follow-up due '.$h->next_due_on->toDateString().'.',
                '/agri/livestock?farm='.$farm->uuid, $h->next_due_on->toDateString(), "health_due:{$h->id}");
        }

        foreach ($f(CropObservation::query())->where('status', 'open')->whereIn('severity', ['high', 'critical'])->get() as $o) {
            $out[] = $this->c('observation', $o->severity === 'critical' ? 'critical' : 'warning',
                ucfirst(str_replace('_', ' ', $o->kind)).' reported ('.$o->severity.')', mb_strimwidth($o->description, 0, 200, '…'),
                '/agri/crops?farm='.$farm->uuid, $o->observed_on->toDateString(), "observation:{$o->id}");
        }

        foreach ($f(CropPlan::query())->where('status', CropPlan::PLANTED)->whereDate('planned_harvest_on', '<=', $today->copy()->addDays(7))->get() as $p) {
            $out[] = $this->c('harvest_due', $p->planned_harvest_on->lt($today) ? 'warning' : 'info', "Harvest due: {$p->code}",
                'Planned harvest '.$p->planned_harvest_on->toDateString().'.', '/agri/crops/plans/'.$p->uuid, $p->planned_harvest_on->toDateString(), "harvest_due:{$p->id}");
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
