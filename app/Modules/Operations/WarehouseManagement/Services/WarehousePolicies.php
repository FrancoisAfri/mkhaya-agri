<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Support\WarehousePolicySchema;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Warehouse policies as versions (WMS-240, WMS-241): checked before they apply, applied by a named
 * person at a recorded time, and restorable. The first version of a warehouse records what it had
 * before versioning began, so a rollback always has somewhere to go.
 */
class WarehousePolicies
{
    /** Merge, normalise and check. @return array{0: array, 1: array<string,string>, 2: array} policies, errors, changes */
    public function check(FinWarehouse $w, array $input): array
    {
        $current = $this->current($w);
        $next = array_intersect_key(array_replace($current, WarehousePolicySchema::normalise($input)), WarehousePolicySchema::defaults());

        return [$next, WarehousePolicySchema::errors($next), $this->diff($current, $next)];
    }

    public function current(FinWarehouse $w): array
    {
        return WarehousePolicySchema::normalise(array_intersect_key(array_replace(WarehousePolicySchema::defaults(), $w->policies ?? []), WarehousePolicySchema::defaults()));
    }

    public function draft(FinWarehouse $w, array $input, ?User $by, ?string $note = null): object
    {
        [$next, $errors, $changes] = $this->check($w, $input);
        $this->refuse($errors, $changes);
        $this->baseline($w, $by);

        return $this->version($w, $next, $changes, 'DRAFT', $note, $by);
    }

    public function activate(object $version, ?User $by): void
    {
        if ($version->status !== 'DRAFT') {
            throw new RuntimeException("Version {$version->version} is ".strtolower($version->status).', not a draft.');
        }
        $w = FinWarehouse::query()->findOrFail($version->warehouse_id);
        $policies = json_decode($version->policies, true);
        // Check again: the rules may have changed since it was drafted.
        $this->refuse(WarehousePolicySchema::errors($policies), ['x' => 1]);
        DB::transaction(function () use ($w, $version, $policies, $by) {
            DB::table('wms_policy_versions')->where('warehouse_id', $w->id)->where('status', 'ACTIVE')->update(['status' => 'SUPERSEDED', 'updated_at' => now()]);
            DB::table('wms_policy_versions')->where('id', $version->id)->update(['status' => 'ACTIVE', 'activated_by' => $by?->id, 'activated_at' => now(), 'updated_at' => now()]);
            $w->forceFill(['policies' => array_replace($w->policies ?? [], $policies)])->save();
        });
    }

    /** Apply at once (the warehouse form, the GLN screen): a version is still made and recorded. */
    public function apply(FinWarehouse $w, array $input, ?User $by, ?string $note = null): void
    {
        [$next, $errors, $changes] = $this->check($w, $input);
        if ($changes === []) {
            return;
        }
        $this->refuse($errors, $changes);
        $this->baseline($w, $by);
        $this->activate($this->version($w, $next, $changes, 'DRAFT', $note, $by), $by);
    }

    /** Put an earlier version back, as a new version: history is never rewritten. */
    public function restore(object $old, ?User $by): void
    {
        $w = FinWarehouse::query()->findOrFail($old->warehouse_id);
        $this->apply($w, json_decode($old->policies, true), $by, "Restored version {$old->version}");
    }

    public function discard(object $version): void
    {
        if ($version->status !== 'DRAFT') {
            throw new RuntimeException('Only a draft can be discarded.');
        }
        DB::table('wms_policy_versions')->where('id', $version->id)->update(['status' => 'DISCARDED', 'updated_at' => now()]);
    }

    private function baseline(FinWarehouse $w, ?User $by): void
    {
        if (! DB::table('wms_policy_versions')->where('warehouse_id', $w->id)->exists()) {
            DB::table('wms_policy_versions')->insert(['company_id' => $w->company_id, 'warehouse_id' => $w->id, 'version' => 1, 'policies' => json_encode($this->current($w)),
                'changes' => '{}', 'status' => 'ACTIVE', 'note' => 'As it was before policy versions began', 'created_by' => $by?->id, 'activated_by' => $by?->id,
                'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function version(FinWarehouse $w, array $policies, array $changes, string $status, ?string $note, ?User $by): object
    {
        $n = (int) DB::table('wms_policy_versions')->where('warehouse_id', $w->id)->max('version') + 1;
        $id = DB::table('wms_policy_versions')->insertGetId(['company_id' => $w->company_id, 'warehouse_id' => $w->id, 'version' => $n, 'policies' => json_encode($policies),
            'changes' => json_encode($changes), 'status' => $status, 'note' => $note, 'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('wms_policy_versions')->where('id', $id)->first();
    }

    private function refuse(array $errors, array $changes): void
    {
        if ($errors !== []) {
            throw new RuntimeException(collect($errors)->map(fn ($why, $k) => str_replace('_', ' ', $k)." {$why}")->implode('; ').'.');
        }
        if ($changes === []) {
            throw new RuntimeException('Nothing has changed.');
        }
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function diff(array $a, array $b): array
    {
        $out = [];
        foreach ($b as $k => $v) {
            if (($a[$k] ?? null) !== $v && json_encode($a[$k] ?? null) !== json_encode($v)) {
                $out[$k] = ['from' => $a[$k] ?? null, 'to' => $v];
            }
        }

        return $out;
    }
}
