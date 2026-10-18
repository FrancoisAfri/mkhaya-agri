<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\BomApproval;
use App\Modules\Operations\Manufacturing\Models\BomLine;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle of a bill of materials, and the service half of the acceptance criteria:
 *
 *   "An approved BOM revision cannot be silently overwritten. Historical production orders must
 *    retain the BOM version used when they were released."
 *
 * The database refuses the edit (triggers in the MFG-002 migration); this class provides the thing
 * to do instead. Revising an approved BOM copies it into a fresh draft at the next revision number,
 * lines and alternates included, and leaves the original exactly as it was. When the draft is
 * approved it takes over as default and the old one becomes SUPERSEDED — still readable, still
 * referenced by every order that was released against it.
 */
class BomVersioning
{
    /** Submit a draft for approval. Separated from approving so the two can be different people. */
    public function submit(Bom $bom, ?int $actorId, ?string $comment = null): Bom
    {
        if ($bom->status !== Bom::DRAFT) {
            throw new DomainException("Only a draft can be submitted; revision {$bom->revision} is {$bom->status}.");
        }

        $this->assertComplete($bom);
        $this->record($bom, BomApproval::SUBMITTED, $actorId, $comment);

        return $bom;
    }

    /**
     * Approve a draft, retire the revision it replaces, and make it the default.
     *
     * One transaction, because a half-applied approval leaves either two defaults or none, and
     * both make the next production order pick the wrong recipe.
     */
    public function approve(Bom $bom, ?int $actorId, ?string $comment = null): Bom
    {
        if ($bom->status !== Bom::DRAFT) {
            throw new DomainException("Revision {$bom->revision} is {$bom->status}, not a draft awaiting approval.");
        }

        $this->assertComplete($bom);

        /*
         * Self-approval is refused where the tenant asks for approval at all. An engineer
         * approving their own change is the control this requirement exists to impose, and it is
         * cheap to enforce here and impossible to enforce later.
         */
        if ($this->requiresApproval($bom) && $actorId !== null && (int) $bom->created_by === (int) $actorId) {
            throw new DomainException(
                'A bill of materials cannot be approved by the person who drafted it. '
                .'Turn off require_bom_approval for this factory if that is not wanted.'
            );
        }

        return DB::transaction(function () use ($bom, $actorId, $comment) {
            $previous = Bom::query()
                ->where('item_id', $bom->item_id)
                ->where('status', Bom::APPROVED)
                ->where('id', '!=', $bom->id)
                ->lockForUpdate()
                ->get();

            /*
             * Demote first, then promote. mfg_bom_one_default is a partial unique index over
             * approved defaults, so the new revision cannot become the default while the old one
             * still is -- the index is checked per statement, not at commit. The brief window in
             * which the product has no default is invisible: it all happens inside this
             * transaction, and a reader sees the state before or the state after.
             */
            foreach ($previous as $old) {
                $old->forceFill([
                    'status' => Bom::SUPERSEDED,
                    'is_default' => false,
                    'effective_to' => now()->toDateString(),
                    'superseded_by_id' => $bom->id,
                ])->save();

                $this->record($old, BomApproval::SUPERSEDED, $actorId,
                    "Superseded by revision {$bom->revision}.");
            }

            $bom->forceFill([
                'status' => Bom::APPROVED,
                'approved_by' => $actorId,
                'approved_at' => now(),
                'is_default' => true,
                'effective_from' => $bom->effective_from ?? now()->toDateString(),
            ])->save();

            $this->record($bom, BomApproval::APPROVED, $actorId, $comment);

            return $bom->refresh();
        });
    }

    public function reject(Bom $bom, ?int $actorId, string $reason): Bom
    {
        if ($bom->status !== Bom::DRAFT) {
            throw new DomainException("Revision {$bom->revision} is {$bom->status} and cannot be rejected.");
        }

        $this->record($bom, BomApproval::REJECTED, $actorId, $reason);

        return $bom;
    }

    /**
     * The way to change an approved BOM: copy it to the next revision as a draft.
     *
     * The original is not touched at all — not its lines, not its status, not its effective dates.
     * It stays the recipe that every order released against it was costed with.
     */
    public function revise(Bom $bom, ?int $actorId, ?string $reason = null): Bom
    {
        return DB::transaction(function () use ($bom, $actorId, $reason) {
            /*
             * Lock the rows, then take the maximum in PHP. Postgres refuses FOR UPDATE together
             * with an aggregate, so ->lockForUpdate()->max() raises rather than locking. Two people
             * revising at once must not both be given the same revision number, and the unique
             * index would otherwise be the only thing that noticed.
             */
            $nextRevision = (int) Bom::withoutGlobalScopes()
                ->where('item_id', $bom->item_id)
                ->lockForUpdate()
                ->pluck('revision')
                ->max() + 1;

            $draft = Bom::create([
                'company_id' => $bom->company_id,
                'item_id' => $bom->item_id,
                'revision' => $nextRevision,
                'name' => $bom->name,
                'status' => Bom::DRAFT,
                'yield_quantity' => $bom->yield_quantity,
                'yield_uom_id' => $bom->yield_uom_id,
                'batch_size' => $bom->batch_size,
                'process_loss_percent' => $bom->process_loss_percent,
                'is_default' => false,
                'notes' => $bom->notes,
                'created_by' => $actorId,
            ]);

            foreach ($bom->lines()->with('alternates')->get() as $line) {
                $copy = BomLine::create([
                    'company_id' => $line->company_id,
                    'bom_id' => $draft->id,
                    'line_no' => $line->line_no,
                    'component_stock_item_id' => $line->component_stock_item_id,
                    'quantity_per' => $line->quantity_per,
                    'uom_id' => $line->uom_id,
                    'scrap_percent' => $line->scrap_percent,
                    'is_phantom' => $line->is_phantom,
                    'line_kind' => $line->line_kind,
                    'alternate_group' => $line->alternate_group,
                    'is_optional' => $line->is_optional,
                    'notes' => $line->notes,
                ]);

                foreach ($line->alternates as $alternate) {
                    $copy->alternates()->create([
                        'company_id' => $alternate->company_id,
                        'stock_item_id' => $alternate->stock_item_id,
                        'ratio' => $alternate->ratio,
                        'priority' => $alternate->priority,
                        'requires_approval' => $alternate->requires_approval,
                        // Approval does not carry over: the new revision is a new decision.
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);
                }
            }

            $this->record($draft, BomApproval::REVISED, $actorId,
                $reason ?? "Drafted from revision {$bom->revision}.");

            return $draft->refresh();
        });
    }

    /** Retire a revision for good. Allowed on an approved one — it is not an edit of the recipe. */
    public function obsolete(Bom $bom, ?int $actorId, ?string $reason = null): Bom
    {
        if ($bom->status === Bom::OBSOLETE) {
            return $bom;
        }

        $bom->forceFill([
            'status' => Bom::OBSOLETE,
            'is_default' => false,
            'effective_to' => $bom->effective_to ?? now()->toDateString(),
        ])->save();

        $this->record($bom, BomApproval::OBSOLETED, $actorId, $reason);

        return $bom;
    }

    /**
     * A recipe with no components is not a recipe.
     *
     * Checked at submit and again at approve rather than only on save, because lines are added
     * after the header exists and an empty BOM would otherwise explode to nothing and report a
     * production order as fully covered by no material at all.
     */
    private function assertComplete(Bom $bom): void
    {
        $consumed = $bom->lines()->where('line_kind', '!=', BomLine::BY_PRODUCT)->count();

        if ($consumed === 0) {
            throw new DomainException(
                "BOM revision {$bom->revision} has no components. It would explode to nothing and "
                .'report every order as needing no material.'
            );
        }

        if (! $bom->item?->isManufactured()) {
            throw new DomainException(
                'Only a finished product, intermediate or subassembly can carry a bill of '
                .'materials. A raw material is bought, not made.'
            );
        }
    }

    /** Does this factory, or the company, insist on an approval step? */
    private function requiresApproval(Bom $bom): bool
    {
        $policy = FactoryPolicy::query()
            ->where('company_id', $bom->company_id)
            ->orderByRaw('factory_id IS NULL')
            ->first();

        return (bool) ($policy->require_bom_approval ?? true);
    }

    private function record(Bom $bom, string $action, ?int $actorId, ?string $comment): void
    {
        BomApproval::create([
            'company_id' => $bom->company_id,
            'bom_id' => $bom->id,
            'action' => $action,
            'actor_id' => $actorId,
            'acted_at' => now(),
            'comment' => $comment,
        ]);
    }
}
