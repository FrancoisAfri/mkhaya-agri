<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Assets\Actions\CreateWorkOrderAction;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Models\AssetWorkOrder;
use App\Modules\Assets\Models\AssetWorkOrderTask;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\DowntimeReason;
use App\Modules\Operations\Manufacturing\Models\FaultCode;
use App\Modules\Operations\Manufacturing\Models\MachineProfile;
use App\Modules\Operations\Manufacturing\Models\OperationTimeLog;
use App\Modules\Operations\Manufacturing\Models\PmRule;
use App\Modules\Operations\Manufacturing\Models\PmWorkOrder;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Machine maintenance for the plant (MFG-013), built on the asset register.
 *
 * Work orders are asset work orders -- the asset module records their tasks, parts, labour and
 * cost. This service decides when one is due (calendar days, hours the machine actually ran,
 * or a run of breakdowns), raises them, records breakdowns against fault codes, and holds a
 * machine so the floor cannot use it until somebody returns it to service.
 */
class Maintenance
{
    public function __construct(
        private readonly CreateWorkOrderAction $workOrders,
        private readonly ShopFloor $floor,
    ) {}

    /** The machines at the plant's work centres. */
    public function machines(): Collection
    {
        $ids = DB::table('mfg_work_centre_machines')->distinct()->pluck('asset_id');

        return Assets::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    public function profile(Assets $asset): MachineProfile
    {
        return MachineProfile::query()->firstOrCreate(['asset_id' => $asset->id], ['company_id' => $asset->company_id]);
    }

    /** Work centres a machine belongs to. */
    public function centresOf(Assets $asset): Collection
    {
        return WorkCentre::query()->whereIn('id', DB::table('mfg_work_centre_machines')->where('asset_id', $asset->id)->pluck('work_centre_id'))->get();
    }

    /**
     * Hours a machine has run: its baseline meter plus the measured run time of every operation
     * worked at its work centres. A centre with several machines counts its run time against each,
     * which over-counts rather than misses a service.
     */
    public function runHours(Assets $asset, ?Carbon $since = null): float
    {
        $minutes = (float) OperationTimeLog::query()
            ->join('mfg_production_order_operations as op', 'op.id', '=', 'mfg_operation_time_logs.order_operation_id')
            ->whereIn('op.work_centre_id', $this->centresOf($asset)->pluck('id'))
            ->when($since, fn ($q) => $q->where('mfg_operation_time_logs.started_at', '>=', $since))
            ->sum('mfg_operation_time_logs.minutes');

        return round(($since ? 0 : (float) $this->profile($asset)->run_hours_baseline) + $minutes / 60, 2);
    }

    // ── Holds ────────────────────────────────────────────────────────────────────────────────

    public function hold(Assets $asset, string $kind, string $reason, ?User $actor): MachineProfile
    {
        if (! in_array($kind, [MachineProfile::MAINTENANCE, MachineProfile::SAFETY], true) || blank($reason)) {
            throw new DomainException('Say whether it is a maintenance or a safety hold, and why.');
        }

        $profile = $this->profile($asset);
        $profile->update(['hold_status' => $kind, 'hold_reason' => $reason, 'held_by' => $actor?->id, 'held_at' => now(),
            'released_by' => null, 'released_at' => null, 'release_notes' => null]);

        return $profile;
    }

    /**
     * Return a held machine to service. Refused while a corrective work order on it is still
     * open: a machine that is still being repaired is not ready to run.
     */
    public function release(Assets $asset, string $notes, ?User $actor): MachineProfile
    {
        $profile = $this->profile($asset);

        if (! $profile->isHeld()) {
            throw new DomainException('That machine is not on hold.');
        }
        if ($profile->requires_return_to_service && blank($notes)) {
            throw new DomainException('Record the return-to-service check.');
        }
        $open = AssetWorkOrder::query()->where('asset_id', $asset->id)->where('type', 'corrective')
            ->whereNotIn('status', ['completed', 'cancelled'])->pluck('work_order_number');
        if ($open->isNotEmpty()) {
            throw new DomainException('Repairs are still open on this machine: '.$open->implode(', ').'. Complete them first.');
        }

        $profile->update(['hold_status' => MachineProfile::NONE, 'released_by' => $actor?->id, 'released_at' => now(), 'release_notes' => $notes]);

        return $profile;
    }

    /**
     * Whether a work centre has a machine that may be used. A centre with machines registered and
     * every one of them held cannot run; a centre with no machines registered is not stopped.
     */
    public function assertUsable(?WorkCentre $centre): void
    {
        if (! $centre) {
            return;
        }

        $assetIds = DB::table('mfg_work_centre_machines')->where('work_centre_id', $centre->id)->pluck('asset_id');
        if ($assetIds->isEmpty()) {
            return;
        }

        $held = MachineProfile::query()->whereIn('asset_id', $assetIds)->where('hold_status', '!=', MachineProfile::NONE)->get();
        if ($held->count() === $assetIds->count()) {
            $why = $held->map(fn ($p) => $p->hold_reason)->filter()->unique()->implode('; ');
            throw new DomainException("Every machine at {$centre->code} is on hold".($why ? ": {$why}" : '').'. It must be returned to service first.');
        }
    }

    // ── Breakdowns ───────────────────────────────────────────────────────────────────────────

    /**
     * A machine has broken down: stop the centre against the breakdown reason and the fault code,
     * raise a corrective work order, and hold the machine if the fault makes it unsafe to run.
     */
    public function reportBreakdown(WorkCentre $centre, Assets $asset, ?FaultCode $fault, ?string $notes, bool $hold, ?User $actor): DowntimeEvent
    {
        if (! $centre->machines()->whereKey($asset->id)->exists()) {
            throw new DomainException('That machine is not at this work centre.');
        }

        $reason = DowntimeReason::query()->where('category', 'BREAKDOWN')->where('is_active', true)->orderBy('id')->first();
        if (! $reason) {
            throw new DomainException('There is no active breakdown reason code. Add one under Downtime.');
        }

        return DB::transaction(function () use ($centre, $asset, $fault, $notes, $hold, $actor, $reason) {
            $event = $this->floor->startDowntime($centre, $reason, $actor, $notes, null, $asset->id);

            $wo = $this->workOrders->execute([
                'asset_id' => $asset->id, 'type' => 'corrective', 'priority' => 'high',
                'title' => 'Breakdown: '.$asset->name.($fault ? " — {$fault->code} {$fault->name}" : ''),
                'description' => trim("Reported at {$centre->code}.".($notes ? " {$notes}" : '')),
                'scheduled_for' => now()->toDateString(),
            ], $actor?->id);

            $event->update(['fault_code_id' => $fault?->id, 'work_order_id' => $wo->id]);

            if ($hold) {
                $this->hold($asset, MachineProfile::MAINTENANCE, 'Breakdown'.($fault ? ": {$fault->code}" : ''), $actor);
            }

            return $event->refresh();
        });
    }

    // ── Preventive ───────────────────────────────────────────────────────────────────────────

    /**
     * Raise a work order for every rule that is due and has none open. Returns what was raised.
     *
     * @return list<array{rule: string, work_order: string, trigger: string}>
     */
    public function generateDue(?int $companyId = null): array
    {
        $raised = [];

        $rules = PmRule::query()->where('is_active', true)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->with('asset')->get();

        foreach ($rules as $rule) {
            if (! $rule->asset) {
                continue;
            }
            $trigger = $this->dueBecause($rule);
            if ($trigger === null || $this->hasOpenWorkOrder($rule)) {
                continue;
            }

            $raised[] = DB::transaction(function () use ($rule, $trigger) {
                $wo = $this->workOrders->execute([
                    'asset_id' => $rule->asset_id, 'type' => $rule->work_order_type, 'priority' => $rule->priority,
                    'title' => "{$rule->title} — {$rule->asset->name}", 'description' => "Preventive maintenance due: {$trigger}.",
                    'scheduled_for' => now()->toDateString(), 'assigned_to' => $rule->assign_to,
                ], $rule->created_by);

                foreach (array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $rule->tasks)))) as $i => $task) {
                    AssetWorkOrderTask::create(['company_id' => $wo->company_id, 'work_order_id' => $wo->id,
                        'description' => $task, 'sequence' => $i + 1, 'is_done' => false]);
                }

                PmWorkOrder::create(['company_id' => $rule->company_id, 'rule_id' => $rule->id, 'work_order_id' => $wo->id, 'trigger' => $trigger]);
                $rule->update(['last_triggered_at' => now(), 'run_hours_at_last' => $this->runHours($rule->asset)]);

                return ['rule' => $rule->title, 'work_order' => $wo->work_order_number, 'trigger' => $trigger];
            });
        }

        return $raised;
    }

    /** Why a rule is due now, or null if it is not. */
    public function dueBecause(PmRule $rule): ?string
    {
        return match ($rule->kind) {
            'TIME' => $rule->interval_days && ($rule->last_triggered_at ?? $rule->created_at)->copy()->addDays($rule->interval_days)->lte(now())
                ? "{$rule->interval_days} days" : null,
            'USAGE' => $rule->interval_run_hours && ($ran = $this->runHours($rule->asset) - (float) ($rule->run_hours_at_last ?? 0)) >= (float) $rule->interval_run_hours
                ? number_format($ran, 1).' run hours' : null,
            'CONDITION' => $this->conditionMet($rule),
            default => null,
        };
    }

    /** How far through its interval a rule is, for the screen. */
    public function progress(PmRule $rule): ?array
    {
        return match ($rule->kind) {
            'TIME' => $rule->interval_days ? ['next' => ($rule->last_triggered_at ?? $rule->created_at)->copy()->addDays($rule->interval_days)->toDateString()] : null,
            'USAGE' => $rule->interval_run_hours ? ['used' => round($this->runHours($rule->asset) - (float) ($rule->run_hours_at_last ?? 0), 1),
                'of' => (float) $rule->interval_run_hours] : null,
            'CONDITION' => ['breakdowns' => $this->breakdowns($rule), 'of' => $rule->breakdowns_threshold],
            default => null,
        };
    }

    private function conditionMet(PmRule $rule): ?string
    {
        $count = $this->breakdowns($rule);

        return $rule->breakdowns_threshold && $count >= $rule->breakdowns_threshold
            ? "{$count} breakdowns in {$rule->window_days} days" : null;
    }

    private function breakdowns(PmRule $rule): int
    {
        $since = now()->subDays($rule->window_days ?? 30);
        if ($rule->last_triggered_at && $rule->last_triggered_at->gt($since)) {
            $since = $rule->last_triggered_at;
        }

        return DowntimeEvent::query()->where('asset_id', $rule->asset_id)->where('started_at', '>=', $since)
            ->whereIn('reason_id', DowntimeReason::query()->where('category', 'BREAKDOWN')->select('id'))->count();
    }

    private function hasOpenWorkOrder(PmRule $rule): bool
    {
        return AssetWorkOrder::query()->whereIn('id', PmWorkOrder::query()->where('rule_id', $rule->id)->select('work_order_id'))
            ->whereNotIn('status', ['completed', 'cancelled'])->exists();
    }

    // ── Reporting ────────────────────────────────────────────────────────────────────────────

    /** A machine's maintenance cost, downtime and failures over a period. */
    public function summary(Assets $asset, Carbon $from, Carbon $to): array
    {
        $orders = AssetWorkOrder::query()->where('asset_id', $asset->id)->where(fn ($q) => $q->whereBetween('completed_at', [$from, $to])
            ->orWhere(fn ($w) => $w->whereNull('completed_at')->whereBetween('created_at', [$from, $to])))->get();
        $stops = DowntimeEvent::query()->where('asset_id', $asset->id)->whereBetween('started_at', [$from, $to])->with('reason')->get();

        return [
            'maintenance_cost' => round((float) $orders->sum('total_cost'), 2),
            'work_orders' => $orders->count(),
            'open_work_orders' => $orders->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'downtime_minutes' => round($stops->sum(fn ($e) => $e->duration()), 1),
            'breakdowns' => $stops->filter(fn ($e) => $e->reason?->category === 'BREAKDOWN')->count(),
            'run_hours' => $this->runHours($asset),
        ];
    }

    /** Work orders past their date and not finished. */
    public function overdue(): Collection
    {
        return AssetWorkOrder::query()->whereIn('asset_id', DB::table('mfg_work_centre_machines')->pluck('asset_id'))
            ->whereNotIn('status', ['completed', 'cancelled'])->whereDate('scheduled_for', '<', now()->toDateString())
            ->with('asset:id,name')->orderBy('scheduled_for')->get();
    }
}
