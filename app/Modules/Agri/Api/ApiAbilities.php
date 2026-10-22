<?php

namespace App\Modules\Agri\Api;

use App\Modules\Agri\Support\RoleCatalog as R;

/**
 * What a farm API token may be allowed to do, and the farm permissions each ability needs.
 *
 * A token carries the abilities (Sanctum checks them on every call); its service user carries the
 * matching farm permissions, so the same services, scopes and checks the screens use apply to it.
 * An endpoint needs both: an ability the token was issued with and the permission behind it.
 */
final class ApiAbilities
{
    public const MAP = [
        'farms:read' => [R::VIEW_FARMS],
        'crops:read' => [R::VIEW_FARMS, R::VIEW_CROPS],
        'activities:write' => [R::VIEW_FARMS, R::RECORD_ACTIVITIES],
        'harvests:read' => [R::VIEW_FARMS, R::VIEW_CROPS, R::VIEW_STOCK],
        'livestock:read' => [R::VIEW_FARMS, R::VIEW_LIVESTOCK],
        'livestock:write' => [R::VIEW_FARMS, R::VIEW_LIVESTOCK, R::MANAGE_LIVESTOCK],
        'stock:read' => [R::VIEW_FARMS, R::VIEW_STOCK],
        'work_orders:read' => [R::VIEW_FARMS, R::VIEW_WORK_ORDERS],
        'work_orders:write' => [R::VIEW_FARMS, R::VIEW_WORK_ORDERS, R::MANAGE_WORK_ORDERS],
        'weather:read' => [R::VIEW_FARMS],
        'sales:read' => [R::VIEW_FARMS, R::VIEW_SALES],
        'finance:read' => [R::VIEW_FARMS, R::VIEW_FINANCE],
        'webhooks:manage' => [],
    ];

    /** Abilities that change farm records: a token with any of them is given write access on its farms. */
    public const WRITES = ['activities:write', 'livestock:write', 'work_orders:write'];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::MAP);
    }

    /** @param list<string> $abilities @return list<string> */
    public static function permissionsFor(array $abilities): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($a) => self::MAP[$a] ?? [], $abilities ?: ['_']))));
    }
}
