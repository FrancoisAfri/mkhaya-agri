<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\BomLine;
use App\Modules\Operations\Manufacturing\Models\Item;
use DateTimeInterface;
use DomainException;

/**
 * Turns a recipe into a material requirement (MFG-002, and the input to planning).
 *
 * Four things make this more than multiplication:
 *
 *  - **Levels.** A component that is itself manufactured explodes into its own components. A
 *    subassembly that is stocked does not — you issue it, you do not make it from scratch inside
 *    its parent's order.
 *  - **Phantoms.** A phantom grouping is never stocked or built, so the planner explodes straight
 *    through it to its children and never raises a requirement for the phantom itself.
 *  - **Scrap.** Added to the requirement, not subtracted from the yield. 10% scrap on 100 kg means
 *    issuing 111.11 kg so 100 kg survives.
 *  - **Batches.** Process loss and setup are paid per batch, so a recipe that yields 100 kg run
 *    for 250 kg is three batches, not 2.5 — you cannot run most processes part-way.
 *
 * Cycles are refused rather than followed. A BOM that contains itself, however indirectly, would
 * otherwise recurse until the request dies, and the error a planner sees would be a timeout rather
 * than the name of the loop.
 */
class BomExploder
{
    /** Safety net in case a cycle somehow evades the path check. */
    private const MAX_DEPTH = 32;

    /** The date child recipes are resolved against for the explosion in progress. */
    private ?DateTimeInterface $asOf = null;

    public function __construct(
        private readonly UomConverter $uoms,
        private readonly BomResolver $resolver,
    ) {}

    /**
     * Gross requirements for making $quantity of the BOM's product.
     *
     * @return array{
     *     batches: int,
     *     yield_per_batch: float,
     *     produced: float,
     *     requirements: list<array<string, mixed>>,
     *     by_products: list<array<string, mixed>>,
     * }
     */
    public function explode(Bom $bom, float $quantity, bool $wholeBatches = true, ?DateTimeInterface $asOf = null): array
    {
        // Held for the duration of the call so a phantom three levels down is resolved against the
        // same date as the top of the tree -- not against whatever is default today.
        $this->asOf = $asOf;

        if ($quantity <= 0) {
            throw new DomainException('Explode what quantity? A requirement for zero is not a plan.');
        }

        $yieldPerBatch = (float) ($bom->yield_quantity ?: 1);
        $batchSize = $bom->batch_size !== null ? (float) $bom->batch_size : $yieldPerBatch;

        if ($batchSize <= 0) {
            throw new DomainException("BOM revision {$bom->revision} has a batch size of zero.");
        }

        /*
         * Whole batches by default. A caller planning capacity wants to know it must run three
         * batches to cover 250 kg; a caller costing a sale wants the linear number. Both are
         * legitimate, so it is a parameter rather than a decision made here.
         */
        $batches = $wholeBatches ? (int) ceil($quantity / $batchSize) : 1;
        $multiplier = $wholeBatches
            ? ($batches * $batchSize) / $yieldPerBatch
            : $quantity / $yieldPerBatch;

        $flat = [];
        $byProducts = [];

        $this->walk($bom, $multiplier, 1, [$bom->item_id], $flat, $byProducts);

        return [
            'batches' => $batches,
            'yield_per_batch' => $yieldPerBatch,
            'produced' => $wholeBatches ? $batches * $batchSize : $quantity,
            'requirements' => array_values($flat),
            'by_products' => array_values($byProducts),
        ];
    }

    /**
     * Where a component is used — the impact analysis the spec asks for.
     *
     * Answers "if this material changes, what do we have to re-approve?". Only live revisions are
     * reported: a superseded BOM cannot be affected by a change, because nothing will be made from
     * it again.
     *
     * @return list<array<string, mixed>>
     */
    public function whereUsed(int $stockItemId, bool $liveOnly = true): array
    {
        $lines = BomLine::query()
            ->with(['bom.item', 'uom'])
            ->where('component_stock_item_id', $stockItemId)
            ->when($liveOnly, fn ($q) => $q->whereHas(
                'bom',
                fn ($b) => $b->whereIn('status', [Bom::DRAFT, Bom::APPROVED])
            ))
            ->get();

        return $lines->map(fn (BomLine $line) => [
            'bom_uuid' => $line->bom?->uuid,
            'bom_revision' => $line->bom?->revision,
            'bom_status' => $line->bom?->status,
            'parent_item_uuid' => $line->bom?->item?->uuid,
            'parent_item_kind' => $line->bom?->item?->kind,
            'line_no' => $line->line_no,
            'quantity_per' => (float) $line->quantity_per,
            'uom' => $line->uom?->code,
            'scrap_percent' => (float) $line->scrap_percent,
            'is_phantom' => (bool) $line->is_phantom,
        ])->all();
    }

    /**
     * Compare two revisions (MFG-002: "version comparison and impact analysis").
     *
     * Keyed on the component rather than on the line number, because renumbering lines is not a
     * change to the recipe and a diff that reports it as one is a diff nobody reads twice.
     *
     * @return array{added: list<array>, removed: list<array>, changed: list<array>, unchanged: int}
     */
    public function compare(Bom $left, Bom $right): array
    {
        $before = $this->linesByComponent($left);
        $after = $this->linesByComponent($right);

        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = 0;

        foreach ($after as $component => $line) {
            if (! isset($before[$component])) {
                $added[] = $line;

                continue;
            }

            $was = $before[$component];
            $differences = [];

            foreach (['quantity_per', 'scrap_percent', 'uom', 'line_kind', 'is_phantom', 'is_optional'] as $field) {
                if ($was[$field] !== $line[$field]) {
                    $differences[$field] = ['from' => $was[$field], 'to' => $line[$field]];
                }
            }

            if ($differences === []) {
                $unchanged++;

                continue;
            }

            $changed[] = ['component' => $component, 'item_code' => $line['item_code'], 'differences' => $differences];
        }

        foreach ($before as $component => $line) {
            if (! isset($after[$component])) {
                $removed[] = $line;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'unchanged' => $unchanged];
    }

    /** Recursive descent, accumulating into $flat keyed by component so repeats add up. */
    private function walk(Bom $bom, float $multiplier, int $depth, array $path, array &$flat, array &$byProducts): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DomainException(
                'Bill of materials is nested more than '.self::MAX_DEPTH.' levels deep, which is '
                .'almost certainly a loop rather than a product.'
            );
        }

        foreach ($bom->lines as $line) {
            $gross = $line->grossQuantityPer() * $multiplier;

            if (! $line->isConsumed()) {
                $this->accumulate($byProducts, $line, $gross, $depth);

                continue;
            }

            $childBom = $this->childBomFor($line);

            /*
             * A phantom MUST explode. If it has no BOM there is nothing behind the grouping, which
             * is a definition error rather than something to quietly treat as a material — a
             * phantom is never stocked, so a requirement for one can never be satisfied.
             */
            if ($line->is_phantom) {
                if (! $childBom) {
                    throw new DomainException(
                        "Line {$line->line_no} is marked phantom but has no approved BOM to "
                        .'explode into. A phantom is never stocked, so this requirement could '
                        .'never be met.'
                    );
                }

                $this->descend($childBom, $line, $gross, $depth, $path, $flat, $byProducts);

                continue;
            }

            /*
             * A manufactured component that is stocked is issued, not exploded: its own production
             * order makes it. Only a component with no stock of its own is made inside the parent.
             */
            $this->accumulate($flat, $line, $gross, $depth);
        }
    }

    private function descend(Bom $childBom, BomLine $line, float $gross, int $depth, array $path, array &$flat, array &$byProducts): void
    {
        if (in_array($childBom->item_id, $path, true)) {
            throw new DomainException(
                "Bill of materials loops: item {$childBom->item_id} already appears higher up this "
                .'explosion. A product cannot be a component of itself.'
            );
        }

        $childYield = (float) ($childBom->yield_quantity ?: 1);

        /*
         * The parent asks for the phantom in the parent line's unit; the child BOM yields in its
         * own. A recipe calling for 3 kg of a premix whose own BOM yields 500 g per batch needs
         * those two reconciled before dividing, or the requirement comes out 1000x wrong -- and
         * it comes out wrong silently, because both numbers look plausible on a screen.
         */
        $lineUom = $line->uom;
        $childUom = $childBom->yieldUom;

        if ($lineUom && $childUom && $lineUom->id !== $childUom->id) {
            $gross = $this->uoms->convert($gross, $lineUom, $childUom, $line->component_stock_item_id);
        }

        $this->walk(
            $childBom,
            $gross / $childYield,
            $depth + 1,
            [...$path, $childBom->item_id],
            $flat,
            $byProducts,
        );
    }

    /**
     * The recipe behind a phantom line, as it stood on the date being planned for.
     *
     * Asked of the resolver rather than read from the default flag: an order dated before the
     * recipe changed must be costed against the recipe in force then, all the way down the tree.
     */
    private function childBomFor(BomLine $line): ?Bom
    {
        $item = Item::query()->where('stock_item_id', $line->component_stock_item_id)->first();

        if (! $item) {
            return null;
        }

        return $this->resolver->applicable($item->id, $this->asOf)?->load(['lines.uom', 'yieldUom']);
    }

    private function accumulate(array &$into, BomLine $line, float $quantity, int $depth): void
    {
        $key = (int) $line->component_stock_item_id;

        if (! isset($into[$key])) {
            $into[$key] = [
                'stock_item_id' => $key,
                'item_code' => $line->componentCode(),
                'quantity' => 0.0,
                'uom' => $line->uom?->code,
                'line_kind' => $line->line_kind,
                'level' => $depth,
                'lines' => [],
            ];
        }

        $into[$key]['quantity'] += $quantity;
        $into[$key]['level'] = min($into[$key]['level'], $depth);
        $into[$key]['lines'][] = $line->uuid;
    }

    /** @return array<int, array<string, mixed>> */
    private function linesByComponent(Bom $bom): array
    {
        $out = [];

        foreach ($bom->lines()->with('uom')->get() as $line) {
            $out[(int) $line->component_stock_item_id] = [
                'component' => (int) $line->component_stock_item_id,
                'item_code' => $line->componentCode(),
                'line_no' => (int) $line->line_no,
                'quantity_per' => (float) $line->quantity_per,
                'scrap_percent' => (float) $line->scrap_percent,
                'uom' => $line->uom?->code,
                'line_kind' => $line->line_kind,
                'is_phantom' => (bool) $line->is_phantom,
                'is_optional' => (bool) $line->is_optional,
            ];
        }

        return $out;
    }
}
