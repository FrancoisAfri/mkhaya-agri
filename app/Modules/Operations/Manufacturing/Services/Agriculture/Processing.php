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
        foreach ($inputs as $i) {
            if ($source = $this->sourceCycle($i['lot'])) {
                if ($why = $this->cycles->blocks($source)) {
                    throw ValidationException::withMessages(['inputs' => "Lot {$i['lot']->code} comes from {$source->code}, which may not be released: ".implode(' ', $why)]);
                }
            }
        }

        return DB::transaction(function () use ($farm, $kind, $inputs, $outputs, $packaging, $data, $actor, $batch) {
            $op = $this->postHarvest->operate($farm, $kind, $inputs, $outputs, $packaging, $data, $actor);
            if ($batch) {
                $batch->update(['production_order_id' => $op->production_order_id, 'output_quantity' => (float) $batch->output_quantity + (float) $op->output_quantity,
                    'cost_to_date' => (float) $batch->cost_to_date + (float) ($op->output_value ?? 0), 'stage' => $op->quality_passed ? 'qa' : 'processing']);
                Record::create(['company_id' => $batch->company_id, 'cycle_id' => $batch->id, 'unit_id' => $batch->unit_id, 'kind' => 'harvest', 'recorded_at' => now(),
                    'quantity' => $op->output_quantity, 'cost' => $op->output_value, 'source' => 'manual', 'link_type' => 'packhouse_operation', 'link_id' => $op->id,
                    'details' => ['reference' => $op->reference, 'quality_passed' => $op->quality_passed, 'production_order_id' => $op->production_order_id], 'recorded_by' => $actor->id]);
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
        if (! $actor->can(RoleCatalog::DISPOSE)) {
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

    /** The production cycle the lot's produce came from, through its crop plan. */
    public function sourceCycle(ProduceLot $lot): ?Cycle
    {
        return $lot->crop_plan_id ? Cycle::query()->where('crop_plan_id', $lot->crop_plan_id)->latest('id')->first() : null;
    }
}
