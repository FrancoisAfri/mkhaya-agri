<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Operations\WarehouseManagement\Models\Count;
use App\Modules\Operations\WarehouseManagement\Models\CountLine;
use App\Modules\Operations\WarehouseManagement\Models\PickTask;
use App\Modules\Operations\WarehouseManagement\Models\PutawayTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Work done without a signal (WMS-153): counting, picking and put-away.
 *
 * The device caches its own assigned tasks -- never a stock balance -- and records what was done
 * with an identifier it makes and the time it happened. When it reconnects, each action arrives
 * here, perhaps more than once. The first arrival is applied through the same services a connected
 * screen uses, so every rule is checked against the stock as it is *now*; a repeat gets the first
 * answer; an action that no longer fits (the count closed, the task finished elsewhere, the stock
 * gone) is kept as a conflict for a supervisor to apply or discard. Nothing is forced through.
 */
class OfflineSync
{
    public const KINDS = ['COUNT_ENTRY', 'PICK_CONFIRM', 'PUTAWAY_COMPLETE'];

    /** What a device may hold for the floor: its own tasks, the minimum to do them, no balances. */
    public function bundle(User $user): array
    {
        $counts = Count::query()->whereIn('status', [Count::OPEN, Count::COUNTING])
            ->where(fn ($q) => $q->whereNull('team')->orWhereJsonContains('team', $user->id))->with('warehouse:id,code')->limit(20)->get();

        return [
            'issued_at' => now()->toIso8601String(),
            // What of this person's offline work did not go through, so the device can say so.
            'problems' => DB::table('wms_offline_actions')->where('user_id', $user->id)->whereIn('status', ['CONFLICT', 'REJECTED'])
                ->where('received_at', '>=', now()->subDays(7))->orderByDesc('id')->limit(20)->get(['client_ref', 'kind', 'status', 'message', 'recorded_at'])
                ->map(fn ($a) => (array) $a)->values(),
            'counts' => $counts->map(fn (Count $c) => ['id' => $c->uuid, 'number' => $c->number, 'warehouse' => $c->warehouse?->code,
                'lines' => $c->lines()->whereIn('status', [CountLine::OPEN, CountLine::RECOUNT])->with(['bin:id,code,barcode', 'item:id,code,name,uom', 'lot:id,lot_number'])->get()
                    // Blind: what the books expect never leaves the server.
                    ->map(fn ($l) => ['id' => $l->id, 'round' => $l->round, 'bin' => $l->bin?->code, 'bin_barcode' => $l->bin?->barcode, 'item' => $l->item?->code,
                        'name' => $l->item?->name, 'uom' => $l->item?->uom, 'lot' => $l->lot?->lot_number])->values()])->values(),
            'picks' => PickTask::query()->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                ->whereHas('wave', fn ($q) => $q->where('assigned_to', $user->id))->with(['bin:id,code,barcode', 'item:id,code,name,uom', 'lot:id,lot_number', 'order:id,number'])
                ->orderBy('sequence')->limit(200)->get()
                ->map(fn ($t) => ['id' => $t->uuid, 'bin' => $t->bin?->code, 'bin_barcode' => $t->bin?->barcode, 'item' => $t->item?->code, 'name' => $t->item?->name,
                    'uom' => $t->item?->uom, 'lot' => $t->lot?->lot_number, 'qty' => (float) $t->qty, 'order' => $t->order?->number])->values(),
            'putaways' => PutawayTask::query()->whereIn('status', [PutawayTask::OPEN, PutawayTask::IN_PROGRESS])->where('assigned_to', $user->id)
                ->with(['item:id,code,name,uom', 'fromBin:id,code', 'suggestedBin:id,code,barcode'])->limit(100)->get()
                ->map(fn ($t) => ['id' => $t->uuid, 'number' => $t->number, 'item' => $t->item?->code, 'name' => $t->item?->name, 'uom' => $t->item?->uom,
                    'qty' => (float) $t->qty - (float) $t->done_qty, 'from' => $t->fromBin?->code, 'to' => $t->suggestedBin?->code, 'to_barcode' => $t->suggestedBin?->barcode])->values(),
        ];
    }

    /** @param array{client_ref:string, kind:string, recorded_at:string, payload:array} $action */
    public function apply(array $action, User $user): array
    {
        $ref = trim((string) ($action['client_ref'] ?? ''));
        if ($ref === '' || strlen($ref) > 64) {
            throw new RuntimeException('Every offline action carries the reference the device gave it.');
        }
        if (! in_array($action['kind'] ?? null, self::KINDS, true)) {
            throw new RuntimeException('Only counting, picking and put-away work offline.');
        }
        if ($seen = DB::table('wms_offline_actions')->where('client_ref', $ref)->first()) {
            return ['status' => 'DUPLICATE', 'first_status' => $seen->status, 'message' => $seen->message];
        }
        $recorded = Carbon::parse($action['recorded_at'] ?? now());
        [$status, $message] = $this->run($action['kind'], $action['payload'] ?? [], $ref, $user);
        DB::table('wms_offline_actions')->insert(['company_id' => $user->company_id, 'user_id' => $user->id, 'client_ref' => $ref, 'kind' => $action['kind'],
            'payload' => json_encode($action['payload'] ?? []), 'recorded_at' => $recorded, 'received_at' => now(), 'status' => $status, 'message' => $message,
            'created_at' => now(), 'updated_at' => now()]);

        return ['status' => $status, 'message' => $message];
    }

    /** A supervisor applies a conflicted action as it stands now, or discards it. */
    public function resolve(int $id, string $decision, User $by): array
    {
        $a = DB::table('wms_offline_actions')->where('id', $id)->where('status', 'CONFLICT')->first() ?? throw new RuntimeException('That action is not waiting for review.');
        if ($decision === 'discard') {
            DB::table('wms_offline_actions')->where('id', $id)->update(['status' => 'DISCARDED', 'resolved_by' => $by->id, 'resolved_at' => now(), 'updated_at' => now()]);

            return ['status' => 'DISCARDED'];
        }
        $owner = User::query()->findOrFail($a->user_id);
        [$status, $message] = $this->run($a->kind, json_decode($a->payload, true), $a->client_ref.':retry', $owner);
        if ($status !== 'APPLIED') {
            throw new RuntimeException("It still does not fit: {$message}");
        }
        DB::table('wms_offline_actions')->where('id', $id)->update(['status' => 'RESOLVED', 'message' => $message, 'resolved_by' => $by->id, 'resolved_at' => now(), 'updated_at' => now()]);

        return ['status' => 'RESOLVED'];
    }

    /** @return array{0:string, 1:?string} */
    private function run(string $kind, array $p, string $ref, User $user): array
    {
        try {
            DB::transaction(function () use ($kind, $p, $ref, $user) {
                match ($kind) {
                    'COUNT_ENTRY' => app(Counting::class)->record(CountLine::query()->findOrFail((int) $p['line_id']), (float) $p['qty'], $user, $ref, $p['serials'] ?? null),
                    'PICK_CONFIRM' => app(Picking::class)->confirm(PickTask::query()->where('uuid', $p['task'])->firstOrFail(), (float) $p['qty'], $user,
                        array_filter(['bin_scan' => $p['bin_scan'] ?? null, 'item_scan' => $p['item_scan'] ?? null]), (bool) ($p['short'] ?? false), $p['note'] ?? null),
                    'PUTAWAY_COMPLETE' => $this->putaway($p, $user),
                };
            });

            return ['APPLIED', null];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ['REJECTED', 'The task or line no longer exists.'];
        } catch (RuntimeException $e) {
            return ['CONFLICT', $e->getMessage()];
        }
    }

    private function putaway(array $p, User $user): void
    {
        $task = PutawayTask::query()->where('uuid', $p['task'])->firstOrFail();
        if (! in_array($task->status, [PutawayTask::OPEN, PutawayTask::IN_PROGRESS], true)) {
            throw new RuntimeException("{$task->number} was already finished.");
        }
        $key = trim((string) ($p['to_bin'] ?? ''));
        $bin = FinStockBin::query()->where('warehouse_id', $task->warehouse_id)
            ->where(fn ($q) => $q->where('barcode', $key)->orWhere('code', strtoupper($key)))->first()
            ?? throw new RuntimeException("No location {$key} in this warehouse.");
        app(PutawayTasks::class)->complete($task, $bin, (float) $p['qty'], $user, array_filter(['bin_scan' => $key, 'item_scan' => $p['item_scan'] ?? null]));
    }
}
