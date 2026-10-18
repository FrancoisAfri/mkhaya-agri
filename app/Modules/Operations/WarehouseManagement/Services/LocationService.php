<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Models\LocationChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Shapes the warehouse floor (WMS-002, WMS-004): locations, their limits and whether they are open.
 *
 * Every write lands in the location history. Blocking is enforced by the ledger, not here -- this
 * only records the decision -- so a location blocked on this screen refuses stock from every road
 * into it.
 */
class LocationService
{
    private const AUDITED = [
        'code', 'name', 'kind', 'level', 'parent_id', 'barcode', 'is_active', 'status', 'status_reason',
        'restricted_until', 'max_units', 'max_weight_kg', 'max_volume_m3', 'max_pallets', 'length_cm',
        'width_cm', 'height_cm', 'requires_temperature_monitoring', 'temperature_min_c', 'temperature_max_c',
        'hazmat_allowed', 'handling_notes', 'pick_sequence',
    ];

    /** Fields a location form may set. Status has its own actions so it always carries a reason. */
    public const EDITABLE = [
        'code', 'name', 'kind', 'level', 'parent_id', 'barcode', 'max_units', 'max_weight_kg', 'max_volume_m3',
        'max_pallets', 'length_cm', 'width_cm', 'height_cm', 'requires_temperature_monitoring',
        'temperature_min_c', 'temperature_max_c', 'hazmat_allowed', 'handling_notes', 'pick_sequence',
    ];

    public function create(FinWarehouse $warehouse, array $data): FinStockBin
    {
        $data = array_intersect_key($data, array_flip(self::EDITABLE));
        $data['level'] = strtoupper($data['level'] ?? 'BIN');
        $data['kind'] = strtoupper($data['kind'] ?? 'STORAGE');
        $data['code'] = strtoupper(trim((string) ($data['code'] ?? '')));

        $this->validate($warehouse, $data, null);

        return DB::transaction(function () use ($warehouse, $data) {
            $bin = FinStockBin::create($data + [
                'company_id' => $warehouse->company_id, 'entity_id' => $warehouse->entity_id,
                'warehouse_id' => $warehouse->id, 'is_active' => true, 'status' => FinStockBin::OPEN,
                // A label must resolve to one location in the company, and bin codes repeat
                // across warehouses -- so the default barcode carries the warehouse code.
                'barcode' => ($data['barcode'] ?? null) ?: $warehouse->code.'.'.$data['code'],
            ]);
            $this->record($bin, LocationChange::CREATED, $this->diff([], $this->snapshot($bin)));

            return $bin;
        });
    }

    public function update(FinStockBin $bin, array $data): FinStockBin
    {
        $data = array_intersect_key($data, array_flip(self::EDITABLE));
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }
        if (isset($data['level'])) {
            $data['level'] = strtoupper($data['level']);
        }
        if (isset($data['level']) && $data['level'] !== 'BIN' && $data['level'] !== $bin->level && $this->quantityIn($bin) > 0.0001) {
            throw new RuntimeException("{$bin->code} holds stock, so it must stay a bin.");
        }

        $this->validate($bin->warehouse, array_merge($bin->only(['code', 'level', 'parent_id', 'kind']), $data), $bin);

        return DB::transaction(function () use ($bin, $data) {
            $before = $this->snapshot($bin);
            $bin->fill($data)->save();
            $this->record($bin, LocationChange::UPDATED, $this->diff($before, $this->snapshot($bin)));

            return $bin;
        });
    }

    public function block(FinStockBin $bin, string $reason): FinStockBin
    {
        return $this->setStatus($bin, FinStockBin::BLOCKED, $reason, null, LocationChange::BLOCKED);
    }

    /** A temporary restriction -- a cleaning slot, a pending repair -- that lifts itself at $until. */
    public function restrict(FinStockBin $bin, string $reason, ?Carbon $until = null): FinStockBin
    {
        if ($until !== null && $until->isPast()) {
            throw new RuntimeException('A restriction must end in the future.');
        }

        return $this->setStatus($bin, FinStockBin::RESTRICTED, $reason, $until, LocationChange::RESTRICTED);
    }

    public function reopen(FinStockBin $bin, string $reason): FinStockBin
    {
        return $this->setStatus($bin, FinStockBin::OPEN, $reason, null, LocationChange::REOPENED);
    }

    /**
     * Retire a location. One holding stock, or with open locations inside it, cannot be retired --
     * the stock would be on the books at an address nobody can pick from.
     */
    public function deactivate(FinStockBin $bin, ?string $reason = null): void
    {
        if ($this->quantityIn($bin) > 0.0001) {
            throw new RuntimeException("{$bin->code} still holds stock. Move it out before retiring the location.");
        }
        if ($bin->children()->where('is_active', true)->exists()) {
            throw new RuntimeException("{$bin->code} still contains active locations. Retire those first.");
        }

        DB::transaction(function () use ($bin, $reason) {
            $bin->update(['is_active' => false]);
            $this->record($bin, LocationChange::DEACTIVATED, ['is_active' => [true, false]], $reason);
        });
    }

    /**
     * Add a run of locations one level below $parent (or zones at the top): "add 12 bins to shelf
     * A-01-03". Codes continue from the highest existing number, so running it twice extends the
     * run rather than colliding with it.
     *
     * @return list<FinStockBin>
     */
    public function generate(FinWarehouse $warehouse, ?FinStockBin $parent, int $count, ?string $level = null, ?string $prefix = null, array $limits = []): array
    {
        if ($count < 1 || $count > 500) {
            throw new RuntimeException('Generate between 1 and 500 locations at a time.');
        }

        $level = strtoupper($level ?? ($parent ? $this->nextLevel($parent->level) : 'ZONE'));
        $stem = $prefix !== null && $prefix !== '' ? strtoupper($prefix) : ($parent ? $parent->code.'-' : '');

        $existing = FinStockBin::query()->where('warehouse_id', $warehouse->id)
            ->where('code', 'like', $stem.'%')->pluck('code')
            ->map(fn ($c) => (int) preg_replace('/\D/', '', substr($c, strlen($stem))))
            ->filter(fn ($n) => $n > 0);
        $start = ($existing->max() ?? 0) + 1;
        $width = max(2, strlen((string) ($start + $count - 1)));

        return DB::transaction(function () use ($warehouse, $parent, $count, $level, $stem, $start, $width, $limits) {
            $made = [];
            for ($i = 0; $i < $count; $i++) {
                $made[] = $this->create($warehouse, $limits + [
                    'code' => $stem.str_pad((string) ($start + $i), $width, '0', STR_PAD_LEFT),
                    'level' => $level,
                    'parent_id' => $parent?->id,
                    'kind' => $parent?->kind ?? 'STORAGE',
                ]);
            }

            return $made;
        });
    }

    public function quantityIn(FinStockBin $bin): float
    {
        $ids = $this->descendantIds($bin);

        return (float) FinStockPosition::query()->whereIn('bin_id', $ids)->sum('qty');
    }

    /** @return list<int> this location and everything inside it */
    public function descendantIds(FinStockBin $bin): array
    {
        $ids = [$bin->id];
        $frontier = [$bin->id];
        for ($depth = 0; $frontier !== [] && $depth < 5; $depth++) {
            $frontier = FinStockBin::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    private function nextLevel(?string $level): string
    {
        $i = array_search($level ?? 'BIN', FinStockBin::LEVELS, true);

        if ($i === false || $i >= count(FinStockBin::LEVELS) - 1) {
            throw new RuntimeException('A bin is the smallest location; nothing can be created inside it.');
        }

        return FinStockBin::LEVELS[$i + 1];
    }

    private function validate(FinWarehouse $warehouse, array $data, ?FinStockBin $self): void
    {
        if (($data['code'] ?? '') === '') {
            throw new RuntimeException('A location needs a code.');
        }
        if (! in_array($data['level'], FinStockBin::LEVELS, true)) {
            throw new RuntimeException("Unknown location level {$data['level']}.");
        }
        if (! in_array($data['kind'], FinStockBin::KINDS, true)) {
            throw new RuntimeException("Unknown location kind {$data['kind']}.");
        }

        $clash = FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('code', $data['code'])
            ->when($self, fn ($q) => $q->whereKeyNot($self->id))->exists();
        if ($clash) {
            throw new RuntimeException("Location code {$data['code']} is already used in {$warehouse->name}.");
        }

        if (! empty($data['parent_id'])) {
            $parent = FinStockBin::query()->find($data['parent_id']);
            if ($parent === null || $parent->warehouse_id !== $warehouse->id) {
                throw new RuntimeException('A location can only sit inside a location in the same warehouse.');
            }
            if (array_search($parent->level, FinStockBin::LEVELS, true) >= array_search($data['level'], FinStockBin::LEVELS, true)) {
                throw new RuntimeException("A {$data['level']} cannot sit inside a {$parent->level}.");
            }
            if ($self && in_array($parent->id, $this->descendantIds($self), true)) {
                throw new RuntimeException('A location cannot be moved inside itself.');
            }
        }

        foreach (['max_units', 'max_weight_kg', 'max_volume_m3', 'max_pallets', 'length_cm', 'width_cm', 'height_cm'] as $f) {
            if (isset($data[$f]) && $data[$f] !== null && (float) $data[$f] < 0) {
                throw new RuntimeException('Capacity and dimensions cannot be negative.');
            }
        }
        if (isset($data['temperature_min_c'], $data['temperature_max_c'])
            && $data['temperature_min_c'] !== null && $data['temperature_max_c'] !== null
            && (float) $data['temperature_min_c'] > (float) $data['temperature_max_c']) {
            throw new RuntimeException('The minimum temperature is above the maximum.');
        }
    }

    private function setStatus(FinStockBin $bin, string $status, string $reason, ?Carbon $until, string $action): FinStockBin
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say why. A blocked or reopened location with no reason is the first question at the next audit.');
        }

        return DB::transaction(function () use ($bin, $status, $reason, $until, $action) {
            $before = $this->snapshot($bin);
            $bin->update([
                'status' => $status,
                'status_reason' => $status === FinStockBin::OPEN ? null : $reason,
                'restricted_until' => $status === FinStockBin::RESTRICTED ? $until : null,
            ]);
            $this->record($bin, $action, $this->diff($before, $this->snapshot($bin)), $reason);

            return $bin;
        });
    }

    private function snapshot(FinStockBin $bin): array
    {
        return collect(self::AUDITED)->mapWithKeys(function ($f) use ($bin) {
            $v = $bin->getAttribute($f);

            return [$f => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v];
        })->all();
    }

    private function diff(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;
            if ((string) json_encode($old) !== (string) json_encode($value) && ! ($old === null && $value === null)) {
                $out[$field] = [$old, $value];
            }
        }

        return $out;
    }

    private function record(FinStockBin $bin, string $action, array $changes, ?string $reason = null): void
    {
        if ($changes === [] && $action === LocationChange::UPDATED) {
            return;
        }

        LocationChange::create([
            'company_id' => $bin->company_id, 'warehouse_id' => $bin->warehouse_id, 'bin_id' => $bin->id,
            'action' => $action, 'changes' => $changes, 'reason' => $reason, 'actor_id' => Auth::id(),
            'created_at' => now(),
        ]);
    }
}
