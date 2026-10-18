<?php

namespace App\Modules\Operations\WarehouseManagement\Support;

use App\Core\Role\Models\Role;
use Spatie\Permission\Models\Permission;

/**
 * The warehouse roles WMS-200 names, as starting points (WMS-200).
 *
 * Roles are platform-wide here (Spatie teams are off), so these are templates every company can
 * grant; a company that needs different duties copies one into its own role. Installing never
 * overwrites a preset somebody has changed unless asked to. The platform administrator and the
 * company administrator are the existing `super admin` and `administrator` roles, which the module
 * seeder already gives every warehouse permission.
 *
 * Approval limits are not roles: they are the approval policies' value bands and the reason codes'
 * thresholds. Warehouse limits are each warehouse's `restrict_to_staff` and its staff list. A third-
 * party logistics operator sees only the clients granted to them (ClientAccess).
 */
class RolePresets
{
    public const PREFIX = 'WMS · ';

    /** @return array<string, list<string>> role name => permissions */
    public static function all(): array
    {
        $c = RoleCatalog::class;

        return [
            'Warehouse manager' => array_values(array_diff(RoleCatalog::all(), [RoleCatalog::INTEGRATIONS])),
            'Receiving clerk' => [$c::VIEW, $c::RECEIVE, $c::MOVE_STOCK, $c::SUPPLIER_RETURNS, $c::QUALITY],
            'Inventory controller' => [$c::VIEW, $c::ADJUST_STOCK, $c::APPROVE_ADJUSTMENTS, $c::COUNT, $c::MANAGE_COUNTS, $c::MOVE_STOCK, $c::BLOCK_LOCATIONS,
                $c::CHANGE_ITEM_STATUS, $c::MANAGE_REPLENISHMENT, $c::LOSSES, $c::OVERRIDE_PUTAWAY, $c::APPROVE_TRANSFERS],
            'Picker and packer' => [$c::VIEW, $c::PICK, $c::PACK, $c::MOVE_STOCK, $c::COUNT],
            'Dispatch clerk' => [$c::VIEW, $c::PACK, $c::DISPATCH],
            'Procurement officer' => [$c::VIEW, $c::PLAN, $c::SUPPLIER_RETURNS, $c::APPROVE_RECEIVING_EXCEPTIONS],
            'Sales and fulfilment user' => [$c::VIEW, $c::MANAGE_ORDERS, $c::RETURNS],
            'Finance officer' => [$c::VIEW, $c::ACCOUNTING, $c::APPROVE_ACCOUNTING, $c::CREDIT_RETURNS, $c::APPROVE_LOSS],
            'Quality officer' => [$c::VIEW, $c::QUALITY, $c::MANAGE_QUALITY, $c::RECALLS, $c::CHANGE_ITEM_STATUS],
            'Auditor' => [$c::VIEW, $c::AUDIT],
            'Third-party logistics operator' => [$c::VIEW, $c::RECEIVE, $c::MOVE_STOCK, $c::PICK, $c::PACK, $c::DISPATCH, $c::COUNT],
            'Read-only reporting user' => [$c::VIEW],
        ];
    }

    /** @return array{created: list<string>, refreshed: list<string>} */
    public static function install(bool $refresh = false): array
    {
        $out = ['created' => [], 'refreshed' => []];
        foreach (RoleCatalog::all() as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        foreach (self::all() as $name => $perms) {
            $role = Role::query()->where('name', self::PREFIX.$name)->where('guard_name', 'web')->first();
            if (! $role) {
                $role = Role::create(['name' => self::PREFIX.$name, 'guard_name' => 'web']);
                $role->syncPermissions($perms);
                $out['created'][] = $name;
            } elseif ($refresh) {
                $role->syncPermissions($perms);
                $out['refreshed'][] = $name;
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $out;
    }
}
