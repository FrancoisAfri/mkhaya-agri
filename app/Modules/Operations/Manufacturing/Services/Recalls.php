<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Recall;
use App\Modules\Operations\Manufacturing\Models\RecallAction;
use App\Modules\Operations\Manufacturing\Models\RecallLot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Product recall (MFG-010): find everything a lot went into, hold what is still here, account for
 * what has gone, record every action, and close only when every held quantity has been released
 * or disposed of.
 */
class Recalls
{
    public const SOURCE_TYPE = 'RECALL';

    public function __construct(
        private readonly Traceability $trace,
        private readonly StockLedger $stock,
        private readonly NumberSequences $numbers,
    ) {}

    /** Open a recall from a lot: the lot and every lot downstream of it are covered. */
    public function open(FinStockLot $origin, string $title, string $reason, string $severity, ?User $actor): Recall
    {
        if (blank($title) || blank($reason)) {
            throw new DomainException('A recall needs a title and a reason.');
        }
        if (! in_array($severity, Recall::SEVERITIES, true)) {
            throw new DomainException('Choose the recall class.');
        }

        $forward = $this->trace->forward($origin);

        return DB::transaction(function () use ($origin, $title, $reason, $severity, $actor, $forward) {
            $recall = Recall::create([
                'company_id' => $origin->company_id,
                'number' => $this->numbers->next($origin->company_id, 'RECALL'),
                'title' => $title, 'reason' => $reason, 'severity' => $severity, 'status' => Recall::OPEN,
                'origin_lot_id' => $origin->id, 'opened_by' => $actor?->id, 'opened_at' => now(),
            ]);

            foreach ($forward['nodes'] as $lotId => $node) {
                RecallLot::create([
                    'company_id' => $recall->company_id, 'recall_id' => $recall->id, 'lot_id' => $lotId, 'item_id' => $node['item_id'],
                    'depth' => $node['depth'], 'in_stock_at_open' => $node['in_stock'], 'dispatched_at_open' => $node['dispatched'],
                ]);
            }

            $this->action($recall, 'NOTE', null, null, null,
                sprintf('Opened: %d lot(s) affected%s.', count($forward['nodes']),
                    $forward['untracked'] ? '; '.count($forward['untracked']).' order(s) used the material without making a lot yet' : ''), $actor);

            return $recall;
        });
    }

    /** Put every usable quantity of every affected lot on hold, wherever it is. */
    public function holdAll(Recall $recall, ?User $actor): float
    {
        $this->assertOpen($recall);
        $total = 0.0;

        DB::transaction(function () use ($recall, $actor, &$total) {
            foreach ($recall->lots()->get() as $rl) {
                $held = 0.0;
                foreach ($this->positions($rl->lot_id, FinStockPosition::AVAILABLE) as $p) {
                    $this->stock->changeStatus($this->item($rl), $this->warehouse($p->warehouse_id), (float) $p->qty,
                        FinStockPosition::AVAILABLE, FinStockPosition::ON_HOLD,
                        ['lot_id' => $rl->lot_id, 'bin_id' => $p->bin_id, 'reason' => "Recall {$recall->number}"]);
                    $held += (float) $p->qty;
                }

                if ($held > 0) {
                    $rl->increment('held', $held);
                    $this->action($recall, 'HOLD', $rl->lot_id, $held, null, null, $actor);
                    $total += $held;
                }
            }
        });

        return round($total, 4);
    }

    /** Return held stock to use -- the lot was cleared. */
    public function release(Recall $recall, RecallLot $rl, float $qty, string $reason, ?User $actor): void
    {
        $this->assertOpen($recall);
        $this->assertHeld($rl, $qty, $reason);

        DB::transaction(function () use ($recall, $rl, $qty, $reason, $actor) {
            $this->fromHeld($rl, $qty, fn ($p, $take) => $this->stock->changeStatus($this->item($rl), $this->warehouse($p->warehouse_id), $take,
                FinStockPosition::ON_HOLD, FinStockPosition::AVAILABLE, ['lot_id' => $rl->lot_id, 'bin_id' => $p->bin_id, 'reason' => "Recall {$recall->number} released: {$reason}"]));
            $rl->increment('released', $qty);
            $this->action($recall, 'RELEASE', $rl->lot_id, $qty, null, $reason, $actor);
        });
    }

    /** Write held stock off as scrap, against the recall. */
    public function dispose(Recall $recall, RecallLot $rl, float $qty, string $reason, ?User $actor): void
    {
        $this->assertOpen($recall);
        $this->assertHeld($rl, $qty, $reason);

        DB::transaction(function () use ($recall, $rl, $qty, $reason, $actor) {
            $this->fromHeld($rl, $qty, fn ($p, $take) => $this->stock->scrap($this->item($rl), $take, $this->warehouse($p->warehouse_id), [
                'lot_id' => $rl->lot_id, 'bin_id' => $p->bin_id, 'status' => FinStockPosition::ON_HOLD,
                'source_type' => self::SOURCE_TYPE, 'source_id' => $recall->id, 'reference' => $recall->number,
            ]));
            $rl->increment('disposed', $qty);
            $this->action($recall, 'DISPOSED', $rl->lot_id, $qty, null, $reason, $actor);
        });
    }

    /** Anything else done under the recall: product retrieved from a customer, people told, a note. */
    public function record(Recall $recall, string $kind, ?RecallLot $rl, ?float $qty, ?string $reference, ?string $notes, ?User $actor): RecallAction
    {
        $this->assertOpen($recall);

        if (! in_array($kind, ['RETRIEVED', 'CUSTOMER_NOTIFIED', 'REGULATOR_NOTIFIED', 'NOTE'], true)) {
            throw new DomainException('Holds, releases and disposals are made with their own actions.');
        }
        if ($kind === 'RETRIEVED' && (! $rl || ! $qty || $qty <= 0)) {
            throw new DomainException('Say which lot and how much was retrieved.');
        }
        if (in_array($kind, ['CUSTOMER_NOTIFIED', 'REGULATOR_NOTIFIED'], true) && blank($reference)) {
            throw new DomainException('Say who was notified.');
        }

        return DB::transaction(function () use ($recall, $kind, $rl, $qty, $reference, $notes, $actor) {
            if ($kind === 'RETRIEVED') {
                $rl->increment('retrieved', $qty);
            }

            return $this->action($recall, $kind, $rl?->lot_id, $qty, $reference, $notes, $actor);
        });
    }

    /** Close, once nothing held under the recall is left undecided. */
    public function close(Recall $recall, string $notes, ?User $actor): Recall
    {
        $this->assertOpen($recall);

        if (blank($notes)) {
            throw new DomainException('Record how the recall was concluded.');
        }

        $undecided = $recall->lots()->get()->filter(fn ($rl) => (float) $rl->held - (float) $rl->released - (float) $rl->disposed > 0.00005);
        if ($undecided->isNotEmpty()) {
            throw new DomainException('Stock is still on hold under this recall; release or dispose of it before closing.');
        }

        $recall->update(['status' => Recall::CLOSED, 'closed_by' => $actor?->id, 'closed_at' => now(), 'closure_notes' => $notes]);
        $this->action($recall, 'NOTE', null, null, null, "Closed: {$notes}", $actor);

        return $recall;
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function action(Recall $recall, string $kind, ?int $lotId, ?float $qty, ?string $reference, ?string $notes, ?User $actor): RecallAction
    {
        return RecallAction::create([
            'company_id' => $recall->company_id, 'recall_id' => $recall->id, 'kind' => $kind, 'lot_id' => $lotId, 'qty' => $qty,
            'reference' => $reference, 'notes' => $notes, 'acted_by' => $actor?->id, 'acted_at' => now(),
        ]);
    }

    private function assertOpen(Recall $recall): void
    {
        if (! $recall->isOpen()) {
            throw new DomainException("Recall {$recall->number} is closed.");
        }
    }

    private function assertHeld(RecallLot $rl, float $qty, string $reason): void
    {
        $outstanding = (float) $rl->held - (float) $rl->released - (float) $rl->disposed;

        if ($qty <= 0 || $qty > $outstanding + 0.00005) {
            throw new DomainException(sprintf('Only %s of that lot is on hold under this recall.', rtrim(rtrim(number_format($outstanding, 4, '.', ''), '0'), '.')));
        }
        if (blank($reason)) {
            throw new DomainException('Give the reason.');
        }
    }

    /** Take a quantity from the lot's on-hold positions, one position at a time. */
    private function fromHeld(RecallLot $rl, float $qty, callable $take): void
    {
        $left = $qty;
        foreach ($this->positions($rl->lot_id, FinStockPosition::ON_HOLD) as $p) {
            if ($left <= 0.00005) {
                break;
            }
            $part = min($left, (float) $p->qty);
            $take($p, $part);
            $left -= $part;
        }

        if ($left > 0.00005) {
            throw new DomainException('Less of that lot is on hold than the recall expects; it may have been moved by somebody else.');
        }
    }

    private function positions(int $lotId, string $status)
    {
        return FinStockPosition::withoutGlobalScopes()->where('lot_id', $lotId)->where('status', $status)->where('qty', '>', 0)->orderBy('id')->get();
    }

    private function item(RecallLot $rl): FinStockItem
    {
        return FinStockItem::withoutGlobalScopes()->findOrFail($rl->item_id);
    }

    private function warehouse(int $id): FinWarehouse
    {
        return FinWarehouse::withoutGlobalScopes()->findOrFail($id);
    }
}
