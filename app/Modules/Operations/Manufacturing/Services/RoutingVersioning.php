<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Routing;
use App\Modules\Operations\Manufacturing\Models\RoutingApproval;
use App\Modules\Operations\Manufacturing\Models\RoutingOperation;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle of a routing (MFG-003: "version and approve routing changes"). The same shape as a
 * BOM's, because the reason is the same: an approved routing is a record that orders were planned
 * and costed against, so it is never edited -- it is revised, and the old revision is kept.
 */
class RoutingVersioning
{
    public function submit(Routing $routing, ?int $actorId, ?string $comment = null): Routing
    {
        $this->assertDraft($routing);
        $this->assertComplete($routing);
        $this->record($routing, 'SUBMITTED', $actorId, $comment);

        return $routing;
    }

    public function approve(Routing $routing, ?int $actorId, ?string $comment = null): Routing
    {
        $this->assertDraft($routing);
        $this->assertComplete($routing);

        if ($this->requiresSeparateApprover($routing) && $actorId !== null && (int) $routing->created_by === (int) $actorId) {
            throw new DomainException('A routing cannot be approved by the person who drafted it.');
        }

        return DB::transaction(function () use ($routing, $actorId, $comment) {
            $previous = Routing::query()
                ->where('item_id', $routing->item_id)->where('status', Routing::APPROVED)
                ->where('id', '!=', $routing->id)->lockForUpdate()->get();

            // Demote before promoting: the partial unique index allows one approved default per product.
            foreach ($previous as $old) {
                $old->forceFill([
                    'status' => Routing::SUPERSEDED, 'is_default' => false,
                    'effective_to' => now()->toDateString(), 'superseded_by_id' => $routing->id,
                ])->save();
                $this->record($old, 'SUPERSEDED', $actorId, "Superseded by revision {$routing->revision}.");
            }

            $routing->forceFill([
                'status' => Routing::APPROVED, 'approved_by' => $actorId, 'approved_at' => now(),
                'is_default' => true, 'effective_from' => $routing->effective_from ?? now()->toDateString(),
            ])->save();

            $this->record($routing, 'APPROVED', $actorId, $comment);

            return $routing->refresh();
        });
    }

    public function reject(Routing $routing, ?int $actorId, string $reason): Routing
    {
        $this->assertDraft($routing);
        $this->record($routing, 'REJECTED', $actorId, $reason);

        return $routing;
    }

    /** Copy an approved routing to the next revision as a draft. The original is not touched. */
    public function revise(Routing $routing, ?int $actorId, ?string $reason = null): Routing
    {
        return DB::transaction(function () use ($routing, $actorId, $reason) {
            $next = (int) Routing::withoutGlobalScopes()->where('item_id', $routing->item_id)
                ->lockForUpdate()->pluck('revision')->max() + 1;

            $draft = Routing::create([
                'company_id' => $routing->company_id, 'item_id' => $routing->item_id, 'revision' => $next,
                'name' => $routing->name, 'status' => Routing::DRAFT,
                'min_batch_size' => $routing->min_batch_size, 'max_batch_size' => $routing->max_batch_size,
                'standard_yield_percent' => $routing->standard_yield_percent,
                'yield_tolerance_percent' => $routing->yield_tolerance_percent,
                'is_default' => false, 'notes' => $routing->notes, 'created_by' => $actorId,
            ]);

            foreach ($routing->operations()->with('alternates')->get() as $op) {
                $copy = RoutingOperation::create(
                    ['routing_id' => $draft->id, 'uuid' => null]
                    + \Illuminate\Support\Arr::except($op->getAttributes(), ['id', 'uuid', 'routing_id', 'created_at', 'updated_at'])
                );

                foreach ($op->alternates as $alt) {
                    $copy->alternates()->create([
                        'company_id' => $alt->company_id, 'work_centre_id' => $alt->work_centre_id,
                        'priority' => $alt->priority, 'run_minutes' => $alt->run_minutes,
                    ]);
                }
            }

            $this->record($draft, 'REVISED', $actorId, $reason ?? "Drafted from revision {$routing->revision}.");

            return $draft->refresh();
        });
    }

    public function obsolete(Routing $routing, ?int $actorId, ?string $reason = null): Routing
    {
        if ($routing->status === Routing::OBSOLETE) {
            return $routing;
        }

        $routing->forceFill([
            'status' => Routing::OBSOLETE, 'is_default' => false,
            'effective_to' => $routing->effective_to ?? now()->toDateString(),
        ])->save();

        $this->record($routing, 'OBSOLETED', $actorId, $reason);

        return $routing;
    }

    /**
     * A routing that cannot be run is not approvable. Checked at submit and again at approve,
     * because operations are added after the header exists.
     */
    public function assertComplete(Routing $routing): void
    {
        $ops = $routing->operations()->get();

        if ($ops->isEmpty()) {
            throw new DomainException("Routing revision {$routing->revision} has no operations.");
        }

        if (! $routing->item?->isManufactured()) {
            throw new DomainException('Only a finished product, intermediate or subassembly can have a routing.');
        }

        if ($routing->min_batch_size !== null && $routing->max_batch_size !== null
            && (float) $routing->min_batch_size > (float) $routing->max_batch_size) {
            throw new DomainException('The minimum batch size is larger than the maximum.');
        }

        foreach ($ops as $op) {
            $label = "Operation {$op->sequence} ({$op->name})";

            if (! $op->is_subcontracted && $op->work_centre_id === null && blank($op->machine_type)) {
                throw new DomainException("{$label} says neither where it happens nor on what kind of machine.");
            }

            if ($op->is_subcontracted && $op->subcontract_lead_days === null) {
                throw new DomainException("{$label} is subcontracted but has no lead time, so the order cannot be scheduled around it.");
            }

            if ($op->is_approval_gate && blank($op->gate_permission)) {
                throw new DomainException("{$label} is an approval gate but names no permission, so nobody could pass it.");
            }
        }
    }

    private function assertDraft(Routing $routing): void
    {
        if ($routing->status !== Routing::DRAFT) {
            throw new DomainException("Routing revision {$routing->revision} is {$routing->status}, not a draft.");
        }
    }

    /** Engineering approval policy is one switch for BOMs and routings: both are the recipe. */
    private function requiresSeparateApprover(Routing $routing): bool
    {
        $policy = FactoryPolicy::query()->where('company_id', $routing->company_id)->orderByRaw('factory_id IS NULL')->first();

        return (bool) ($policy->require_bom_approval ?? true);
    }

    private function record(Routing $routing, string $action, ?int $actorId, ?string $comment): void
    {
        RoutingApproval::create([
            'company_id' => $routing->company_id, 'routing_id' => $routing->id, 'action' => $action,
            'actor_id' => $actorId, 'acted_at' => now(), 'comment' => $comment,
        ]);
    }
}
