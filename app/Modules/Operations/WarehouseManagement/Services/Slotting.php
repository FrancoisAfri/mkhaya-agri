<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Slotting (WMS-133): put what is picked most where it is quickest to reach, without breaking the
 * rules about what may be stored where. A proposal is advice; one that moves more than a handful of
 * products must be simulated before it is approved, it is approved by somebody other than whoever
 * proposed it, and applying it creates put-away tasks -- the moves are done and scanned like any other.
 */
class Slotting
{
    use ScopesRawQueries;

    public const REVIEW_ONLY_UP_TO = 5;

    public function __construct(private readonly PutawayAdvisor $advisor, private readonly PutawayTasks $tasks) {}

    public function propose(FinWarehouse $warehouse, User $by, int $maxMoves = 20, int $days = 90): string
    {
        $metres = (float) ($warehouse->policies['metres_per_slot'] ?? 1.5);
        $velocity = DB::table('wms_pick_tasks as t')->join('wms_pick_waves as w', 'w.id', '=', 't.wave_id')->where('w.warehouse_id', $warehouse->id)
            ->where('t.created_at', '>=', now()->subDays($days))->groupBy('t.item_id')->selectRaw('t.item_id, COUNT(*) AS picks')->orderByDesc('picks')->pluck('picks', 'item_id');
        if ($velocity->isEmpty()) {
            throw new RuntimeException('There are no picks in the period to slot by.');
        }
        $home = DB::table('fin_stock_positions as p')->join('fin_stock_bins as b', 'b.id', '=', 'p.bin_id')->where('p.warehouse_id', $warehouse->id)
            ->where('p.qty', '>', 0)->whereIn('p.item_id', $velocity->keys())->where('p.status', 'AVAILABLE')
            ->orderByDesc('p.qty')->get(['p.item_id', 'p.bin_id', 'p.lot_id', 'p.qty', 'b.code', 'b.pick_sequence'])->unique('item_id')->keyBy('item_id');
        $free = FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('level', 'BIN')->where('is_active', true)->whereNotNull('pick_sequence')
            ->whereNotExists(fn ($q) => $q->from('fin_stock_positions as p')->whereColumn('p.bin_id', 'fin_stock_bins.id')->where('p.qty', '>', 0))
            ->orderBy('pick_sequence')->get();
        $moves = [];
        foreach ($velocity as $itemId => $picks) {
            if (count($moves) >= $maxMoves || $free->isEmpty()) {
                break;
            }
            $at = $home[$itemId] ?? null;
            if (! $at || $at->pick_sequence === null) {
                continue;
            }
            $item = FinStockItem::query()->find($itemId);
            $target = $free->first(fn ($b) => $b->pick_sequence < $at->pick_sequence
                && $this->advisor->refusalFor($b, $item, (float) $at->qty, $at->lot_id, 'AVAILABLE') === null);
            if (! $target) {
                continue;
            }
            $free = $free->reject(fn ($b) => $b->id === $target->id)->values();
            $saved = ($at->pick_sequence - $target->pick_sequence) * $metres * 2 * $picks / ($days / 30);
            $moves[] = ['item_id' => (int) $itemId, 'item' => $item->code, 'from_bin_id' => (int) $at->bin_id, 'from' => $at->code, 'from_seq' => (int) $at->pick_sequence,
                'to_bin_id' => $target->id, 'to' => $target->code, 'to_seq' => (int) $target->pick_sequence, 'qty' => (float) $at->qty, 'lot_id' => $at->lot_id,
                'picks' => (int) $picks, 'metres_saved_per_month' => round($saved, 1),
                'why' => "Picked {$picks} times in {$days} days; moves {$at->code} → {$target->code}, closer to the start of the pick path."];
        }
        if ($moves === []) {
            throw new RuntimeException('Fast movers are already in the best places their storage rules allow.');
        }
        $uuid = (string) Str::uuid();
        DB::table('wms_slotting_proposals')->insert(['uuid' => $uuid, 'company_id' => $warehouse->company_id, 'warehouse_id' => $warehouse->id, 'status' => 'DRAFT',
            'moves' => json_encode($moves), 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);

        return $uuid;
    }

    public function simulate(string $uuid, User $by): array
    {
        $p = $this->proposal($uuid);
        $mapping = collect(json_decode($p->moves, true))->mapWithKeys(fn ($m) => [$m['item_id'] => $m['to_seq']])->all();
        $sim = app(Simulator::class)->layout(FinWarehouse::query()->findOrFail($p->warehouse_id), $mapping, $by);
        DB::table('wms_slotting_proposals')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $uuid)->update(['status' => 'SIMULATED', 'simulation' => json_encode($sim['results'] + ['simulation' => $sim['uuid']]), 'updated_at' => now()]);

        return $sim;
    }

    public function decide(string $uuid, bool $approve, ?string $note, User $by): void
    {
        $p = $this->proposal($uuid);
        if (! in_array($p->status, ['DRAFT', 'SIMULATED'], true)) {
            throw new RuntimeException('This proposal has already been decided.');
        }
        if ((int) $p->created_by === $by->id) {
            throw new RuntimeException('A slotting change is approved by somebody other than whoever proposed it.');
        }
        if ($approve && $p->status === 'DRAFT' && count(json_decode($p->moves, true)) > self::REVIEW_ONLY_UP_TO) {
            throw new RuntimeException('More than '.self::REVIEW_ONLY_UP_TO.' moves: simulate the new layout before approving it.');
        }
        DB::table('wms_slotting_proposals')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $uuid)->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'approved_by' => $by->id,
            'approved_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
    }

    /** Turn an approved proposal into put-away tasks. @return int tasks created */
    public function apply(string $uuid, User $by): int
    {
        $p = $this->proposal($uuid);
        if ($p->status !== 'APPROVED') {
            throw new RuntimeException('Only an approved proposal can be applied.');
        }
        $warehouse = FinWarehouse::query()->findOrFail($p->warehouse_id);

        return DB::transaction(function () use ($p, $warehouse, $uuid) {
            $n = 0;
            foreach (json_decode($p->moves, true) as $m) {
                $there = (float) DB::table('fin_stock_positions')->where('item_id', $m['item_id'])->where('bin_id', $m['from_bin_id'])->where('status', 'AVAILABLE')
                    ->when($m['lot_id'], fn ($q, $v) => $q->where('lot_id', $v), fn ($q) => $q->whereNull('lot_id'))->value('qty');
                if ($there <= 0) {
                    continue; // it has moved since; nothing to do
                }
                $task = $this->tasks->create($warehouse, FinStockItem::query()->findOrFail($m['item_id']), $there, [
                    'lot_id' => $m['lot_id'], 'from_bin_id' => $m['from_bin_id'], 'source_type' => 'SLOTTING', 'source_id' => $p->id, 'priority' => 30]);
                $task->update(['suggested_bin_id' => $m['to_bin_id']]);
                $n++;
            }
            DB::table('wms_slotting_proposals')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $uuid)->update(['status' => 'APPLIED', 'updated_at' => now()]);

            return $n;
        });
    }

    private function proposal(string $uuid): object
    {
        return DB::table('wms_slotting_proposals')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $uuid)->when($this->tenantId(), fn ($q, $c) => $q->where('company_id', $c))->first()
            ?? throw new RuntimeException('No such proposal.');
    }
}
