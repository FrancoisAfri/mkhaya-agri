<?php

namespace App\Modules\Agri\Services;

use App\Modules\HR\Hr\Attendance\Services\HrSources;

use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\HR\Hr\Attendance\Models\Worker as FarmWorker;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\HR\Hr\Attendance\Models\Unavailability as WorkerUnavailability;
use App\Modules\Agri\Models\WorkOrderMachine;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Carbon;

/**
 * Phase 2 §2.4: a recommended order for the farm's open jobs, with every reason shown.
 *
 * Each job collects points for what makes it urgent (deadline, priority, crop stage, harvest
 * readiness, animals waiting, irrigation) and is held back by what would stop it (no crew, a
 * missing competency, a machine out of service, inputs not in the store). Blocked jobs go after
 * ready ones; a manager's pinned position beats everything. Nothing here moves a job by itself —
 * it is advice the manager reads, follows or overrides.
 *
 * Factors the farm has no data for yet (weather, storage capacity) are named as not connected,
 * never guessed.
 */
class WorkScheduler
{
    public const NOT_CONNECTED = ['Weather and field access: no weather source connected yet', 'Storage capacity: no storage records connected yet'];

    public function __construct(private readonly HrSources $hr, private readonly StockLedger $stock, private readonly FarmStores $stores) {}

    /** @return list<array<string, mixed>> */
    public function recommend(Farm $farm, int $days = 14): array
    {
        $horizon = now()->addDays($days);
        $jobs = WO::query()->where('farm_id', $farm->id)->whereIn('status', [WO::DRAFT, WO::APPROVED, WO::IN_PROGRESS, WO::PAUSED, WO::REJECTED])
            ->where('planned_start', '<=', $horizon)->with(['workers.worker.person', 'materials', 'machines.machine'])->get();
        $plans = CropPlan::query()->whereIn('id', $jobs->pluck('crop_plan_id')->filter()->unique())->get()->keyBy('id');
        $fields = FarmField::query()->whereIn('id', $jobs->pluck('field_id')->filter()->unique())->get()->keyBy('id');

        $rows = $jobs->map(fn (WO $wo) => $this->assess($wo, $plans[$wo->crop_plan_id] ?? null, $fields[$wo->field_id] ?? null, $jobs))->all();

        usort($rows, function ($a, $b) {
            // Pinned first, in the manager's order; then ready before blocked; then points; then start time.
            return [$a['pinned'] ?? PHP_INT_MAX, $a['blocked'] ? 1 : 0, -$a['score'], $a['starts']]
                <=> [$b['pinned'] ?? PHP_INT_MAX, $b['blocked'] ? 1 : 0, -$b['score'], $b['starts']];
        });
        foreach ($rows as $i => &$r) {
            $r['position'] = $i + 1;
        }

        return $rows;
    }

    private function assess(WO $wo, ?CropPlan $plan, ?FarmField $field, $all): array
    {
        $now = now();
        $factors = [];
        $blockers = [];
        $warnings = [];
        $add = function (string $label, int $points) use (&$factors) {
            $factors[] = ['label' => $label, 'points' => $points];
        };

        // Deadline.
        if ($wo->planned_end->lt($now)) {
            $add('Overdue since '.$wo->planned_end->format('D j M'), 40);
        } elseif ($wo->planned_end->lte($now->copy()->addDays(2))) {
            $add('Due within two days', 25);
        } elseif ($wo->planned_end->lte($now->copy()->addDays(7))) {
            $add('Due this week', 10);
        }
        if ($wo->status === WO::IN_PROGRESS) {
            $add('Already under way', 15);
        }

        // Priority.
        $p = ['urgent' => 30, 'high' => 15, 'normal' => 0, 'low' => -10][$wo->priority] ?? 0;
        if ($p) {
            $add(ucfirst($wo->priority).' priority', $p);
        }

        // Crop stage and harvest readiness.
        if ($plan) {
            if ($wo->category === 'harvesting' && $plan->planned_harvest_on && abs($plan->planned_harvest_on->diffInDays(today(), false)) <= 3) {
                $add("Harvest window for {$plan->code} (".$plan->planned_harvest_on->format('j M').')', 25);
            }
            if (in_array($wo->category, ['planting', 'land_preparation'], true) && $plan->planned_planting_on && abs($plan->planned_planting_on->diffInDays(today(), false)) <= 5) {
                $add("Planting window for {$plan->code} (".$plan->planned_planting_on->format('j M').')', 20);
            }
            if (in_array($wo->category, ['spraying', 'fertilising', 'weeding'], true) && in_array($plan->status, ['planted', 'harvesting'], true)) {
                $add("{$plan->code} is in the ground", 5);
            }
        }

        // Animals cannot wait.
        if (in_array($wo->category, ['feeding', 'vaccination', 'treatment'], true)) {
            $add('Animal care', 15);
            if ($wo->livestock_group_id && AnimalHealthRecord::query()->where('group_id', $wo->livestock_group_id)->whereNotNull('next_due_on')
                ->whereDate('next_due_on', '<=', today())->exists()) {
                $add('Herd treatment already due', 20);
            }
        }

        // Irrigation requirements.
        if ($wo->category === 'irrigation') {
            $add($field && $field->irrigation && $field->irrigation !== 'dryland' ? "Irrigated field ({$field->irrigation})" : 'Irrigation work', 10);
        }

        // People: a crew, available, holding what the work needs.
        $crew = $wo->workers->map(fn ($a) => $a->worker)->filter();
        if ($crew->isEmpty()) {
            $blockers[] = 'Nobody is assigned yet';
        } else {
            foreach ($crew as $w) {
                $away = WorkerUnavailability::query()->where('worker_id', $w->id)->where('starts_at', '<', $wo->planned_end)->where('ends_at', '>', $wo->planned_start)->exists()
                    || ($w->person && $this->hr->onLeave((int) $w->hr_person_id, $wo->planned_start, $wo->planned_end));
                if ($away) {
                    $warnings[] = "{$w->displayName()} is away during the job";
                }
                foreach ($wo->required_certifications ?? [] as $code) {
                    $held = $this->hr->holds((int) $w->hr_person_id, $code, $wo->planned_start);
                    if (! $held['ok']) {
                        $blockers[] = "{$w->displayName()}: {$held['reason']}";
                    }
                }
                if ($w->status !== FarmWorker::ACTIVE) {
                    $blockers[] = "{$w->displayName()} is {$w->status}";
                }
            }
        }

        // Machines: in service, not due, not wanted elsewhere at the same time.
        foreach ($wo->machines as $b) {
            $m = $b->machine;
            if (! $m) {
                continue;
            }
            if ($m->status !== FarmMachine::ACTIVE) {
                $blockers[] = "{$m->code} is in ".str_replace('_', ' ', $m->status);
            } elseif ($m->serviceDue(0, 0)) {
                $warnings[] = "{$m->code} is due for a service";
            }
            $clash = WorkOrderMachine::query()->where('machine_id', $m->id)->where('work_order_id', '!=', $wo->id)
                ->where('booked_from', '<', $b->booked_to)->where('booked_to', '>', $b->booked_from)
                ->whereHas('workOrder', fn ($q) => $q->whereIn('status', WO::OPEN))->with('workOrder')->first();
            if ($clash) {
                $warnings[] = "{$m->code} is also booked for {$clash->workOrder?->reference}";
            }
        }

        // Inputs in the store (this job's own reservation counts as available to it).
        $farm = $wo->farm;
        foreach ($wo->materials as $mat) {
            $need = (float) $mat->planned_quantity - (float) $mat->issued_quantity;
            if ($need <= 0 || ! $farm?->warehouse_id) {
                continue;
            }
            $item = FinStockItem::withoutGlobalScopes()->find($mat->stock_item_id);
            $free = $item ? $this->stock->available($item, $this->stores->storeFor($farm)->id, null, null, null, ['agri_work_order', $wo->id]) : 0;
            if ($item && $free + 0.0001 < $need) {
                $blockers[] = "{$item->name}: ".round(max(0, $free), 2)." of {$need} {$item->uom} in the store";
            }
        }

        // Travel: another job on the same field in the next day is worth doing in one trip.
        if ($wo->field_id && $all->where('id', '!=', $wo->id)->where('field_id', $wo->field_id)
            ->filter(fn ($o) => abs($o->planned_start->diffInHours($wo->planned_start)) <= 24)->isNotEmpty()) {
            $add('Same field as another job within a day — one trip', 3);
        }

        return [
            'id' => $wo->uuid, 'reference' => $wo->reference, 'title' => $wo->title, 'category' => $wo->category, 'status' => $wo->status,
            'priority' => $wo->priority, 'starts' => $wo->planned_start->format('Y-m-d H:i'), 'ends' => $wo->planned_end->format('Y-m-d H:i'),
            'field' => $field?->code, 'crop' => $plan?->code, 'score' => array_sum(array_column($factors, 'points')),
            'factors' => $factors, 'blockers' => array_values(array_unique($blockers)), 'warnings' => array_values(array_unique($warnings)),
            'blocked' => $blockers !== [], 'pinned' => $wo->schedule_rank, 'note' => $wo->schedule_note,
        ];
    }

    /** The manager's override: pin a job to a position, or clear the pin and let the advice decide. */
    public function pin(WO $wo, ?int $rank, ?string $note): WO
    {
        $wo->update(['schedule_rank' => $rank, 'schedule_note' => $rank ? $note : null]);

        return $wo;
    }
}
