<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\PackhouseOperation;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Services\PostHarvest;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Record;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grading, packing and processing farm produce — on the Manufacturing side, as production runs.
 *
 * A batch takes lots in and makes lots out; the run is a Manufacturing production order at the
 * farm's packhouse, so its genealogy is the stock ledger's and Traceability walks it both ways. A
 * failed quality check holds what was made in quarantine. Produce from a cycle under a withdrawal,
 * quarantine or hold cannot go in. A held lot is released only on a passing re-inspection, by
 * somebody who may dispose of quality holds — and never by the person who recorded the batch.
 */
class Processing
{
    public function __construct(
        private readonly PostHarvest $postHarvest,
        private readonly ProductionCycles $cycles,
        private readonly StockLedger $stock,
    ) {}

    /** @see PostHarvest::operate for the shape of inputs, outputs and packaging */
    public function run(Farm $farm, string $kind, array $inputs, array $outputs, array $packaging, array $data, User $actor, ?Cycle $batch = null): PackhouseOperation
    {
        $bom = $data['bom'] ?? null;
        unset($data['bom']);
        $batchQty = (float) ($data['batch_quantity'] ?? 0);
        unset($data['batch_quantity']);
        if ($bom) {
            $this->checkRecipe($bom, $batchQty ?: collect($outputs)->sum(fn ($o) => (float) $o['quantity']), $inputs);
        }
        // A batch on hold (a critical control point out of limits) still records what it makes, but
        // everything it makes is held until the hold is lifted.
        if ($batch && ($held = $this->cycles->blocks($batch))) {
            $data['quality_checks'] = [...($data['quality_checks'] ?? []), ['check' => 'Batch on hold', 'value' => mb_strimwidth(implode(' ', $held), 0, 180), 'ok' => false]];
        }
        foreach ($inputs as $i) {
            if ($source = $this->sourceCycle($i['lot'])) {
                if ($why = $this->cycles->blocks($source)) {
                    throw ValidationException::withMessages(['inputs' => "Lot {$i['lot']->code} comes from {$source->code}, which may not be released: ".implode(' ', $why)]);
                }
            }
        }

        return DB::transaction(function () use ($farm, $kind, $inputs, $outputs, $packaging, $data, $actor, $batch, $bom) {
            // Made to a recipe: the order keeps the exact revision, so a later revision never rewrites what this batch was.
            $op = $this->postHarvest->operate($farm, $kind, $inputs, $outputs, $packaging, $data + ($bom ? ['bom_id' => $bom->id] : []), $actor);
            if ($batch) {
                $batch->update(['production_order_id' => $op->production_order_id, 'output_quantity' => (float) $batch->output_quantity + (float) $op->output_quantity,
                    'cost_to_date' => (float) $batch->cost_to_date + (float) ($op->output_value ?? 0), 'stage' => $op->quality_passed ? 'qa' : 'processing']);
                Record::create(['company_id' => $batch->company_id, 'cycle_id' => $batch->id, 'unit_id' => $batch->unit_id, 'kind' => 'harvest', 'recorded_at' => now(),
                    'quantity' => $op->output_quantity, 'cost' => $op->output_value, 'source' => 'manual', 'link_type' => 'packhouse_operation', 'link_id' => $op->id,
                    'details' => array_filter(['reference' => $op->reference, 'quality_passed' => $op->quality_passed, 'production_order_id' => $op->production_order_id,
                        'recipe' => $bom ? "{$bom->name} rev {$bom->revision}" : null], fn ($v) => $v !== null), 'recorded_by' => $actor->id]);
            }

            return $op;
        });
    }

    /** Release a lot held by a failed check, on a passing re-inspection. */
    public function release(ProduceLot $lot, array $checks, User $actor, string $note): ProduceLot
    {
        if ($lot->status !== 'quarantined') {
            throw ValidationException::withMessages(['lot' => "Lot {$lot->code} is {$lot->status}, not held."]);
        }
        if (! $actor->can(RoleCatalog::DISPOSE) && ! $actor->can(\App\Modules\Agri\Support\RoleCatalog::MANAGE_PACKHOUSE)) {
            throw ValidationException::withMessages(['lot' => 'Releasing a quality hold needs the quality disposition permission.']);
        }
        if ((int) $lot->created_by === (int) $actor->id) {
            throw ValidationException::withMessages(['lot' => 'The person who recorded the batch does not release its hold.']);
        }
        if (! $checks || collect($checks)->contains(fn ($c) => empty($c['ok']))) {
            throw ValidationException::withMessages(['checks' => 'Record a re-inspection in which every check passes.']);
        }
        if (($source = $this->sourceCycle($lot)) && ($why = $this->cycles->blocks($source))) {
            throw ValidationException::withMessages(['lot' => 'The produce is still restricted: '.implode(' ', $why)]);
        }
        if (($batch = $this->madeInBatch($lot)) && ($why = $this->cycles->blocks($batch))) {
            throw ValidationException::withMessages(['lot' => "Batch {$batch->code} that made it is on hold: ".implode(' ', $why)]);
        }
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Say why the hold is released.']);
        }

        return DB::transaction(function () use ($lot, $checks, $actor, $note) {
            if ($lot->stock_lot_id) {
                $item = FinStockItem::withoutGlobalScopes()->findOrFail($lot->stock_item_id);
                foreach (FinStockPosition::withoutGlobalScopes()->where('lot_id', $lot->stock_lot_id)->where('status', FinStockPosition::QUARANTINE)->where('qty', '>', 0)->get() as $p) {
                    $this->stock->changeStatus($item, FinWarehouse::withoutGlobalScopes()->findOrFail($p->warehouse_id), (float) $p->qty, FinStockPosition::QUARANTINE, FinStockPosition::AVAILABLE,
                        ['lot_id' => $lot->stock_lot_id, 'bin_id' => $p->bin_id ?? null, 'reason' => "QA release: {$note}", 'idempotency_key' => "prd-release-{$lot->uuid}-{$p->id}"]);
                }
                $this->postHarvest->syncFromStock($lot->fresh());
            } else {
                $lot->update(['status' => 'available']);
            }
            activity()->performedOn($lot)->causedBy($actor)->withProperties(['checks' => $checks, 'note' => $note])->log('quality hold released');

            return $lot->fresh();
        });
    }

    /**
     * A batch made to a recipe uses only the recipe's components, each within the tolerance of what
     * the approved revision says for that batch size (quantity per yield, plus its scrap allowance).
     *
     * @param  list<array{lot: ProduceLot, quantity: float}>  $inputs
     */
    public function checkRecipe(\App\Modules\Operations\Manufacturing\Models\Bom $bom, float $batchQty, array $inputs): void
    {
        if ($bom->status !== \App\Modules\Operations\Manufacturing\Models\Bom::APPROVED) {
            throw ValidationException::withMessages(['bom_id' => "{$bom->name} revision {$bom->revision} is {$bom->status}; only an approved recipe is made to."]);
        }
        $tolerance = (float) config('manufacturing.agriculture.recipe_tolerance_pct', 10) / 100;
        $yield = max((float) $bom->yield_quantity, 0.000001);
        $lines = $bom->lines()->where('line_kind', '!=', 'PACKAGING')->get();
        $given = collect($inputs)->groupBy(fn ($i) => (int) $i['lot']->stock_item_id)->map(fn ($g) => $g->sum(fn ($i) => (float) $i['quantity']));
        $problems = [];
        foreach ($given->keys() as $itemId) {
            if (! $lines->contains(fn ($l) => (int) $l->component_stock_item_id === $itemId)) {
                $problems[] = FinStockItem::withoutGlobalScopes()->whereKey($itemId)->value('name').' is not in the recipe.';
            }
        }
        foreach ($lines->where('is_optional', false) as $l) {
            $need = (float) $l->quantity_per * $batchQty / $yield * (1 + (float) $l->scrap_percent / 100);
            $got = (float) ($given[(int) $l->component_stock_item_id] ?? 0);
            if ($need > 0 && abs($got - $need) / $need > $tolerance) {
                $problems[] = sprintf('%s: the recipe needs %s for %s made; %s was used.', FinStockItem::withoutGlobalScopes()->whereKey($l->component_stock_item_id)->value('name'),
                    round($need, 3), $batchQty, round($got, 3));
            }
        }
        if ($problems) {
            throw ValidationException::withMessages(['inputs' => "Not to recipe ({$bom->name} rev {$bom->revision}): ".implode(' ', $problems)]);
        }
    }

    /** The processing batch a lot was made in, through the run that made it. */
    public function madeInBatch(ProduceLot $lot): ?Cycle
    {
        $ops = DB::table('produce_lot_links')->where('child_lot_id', $lot->id)->pluck('operation_id')->filter()->unique();

        return $ops->isEmpty() ? null : Cycle::query()->whereIn('id', Record::query()->where('link_type', 'packhouse_operation')->whereIn('link_id', $ops)->pluck('cycle_id'))->first();
    }

    /** The production cycle the lot's produce came from, through its crop plan. */
    public function sourceCycle(ProduceLot $lot): ?Cycle
    {
        return $lot->crop_plan_id ? Cycle::query()->where('crop_plan_id', $lot->crop_plan_id)->latest('id')->first() : null;
    }
}
