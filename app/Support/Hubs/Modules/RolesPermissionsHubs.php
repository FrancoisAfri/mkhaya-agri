<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Roles & Permissions hubs: a single Access Control hub covering roles and permissions. Roles stays a direct entry.
 */
class RolesPermissionsHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'roles_permissions';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'roles', 'name' => 'Roles', 'path' => 'settings/roles', 'icon' => 'fa-user-tag', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'access_control' => [
                'name' => 'Access Control', 'path' => 'settings/access-control-hub', 'icon' => 'lock', 'tier' => self::SUPERUSER,
                'title' => 'roles_permissions.hubs.access_control.title', 'subtitle' => 'roles_permissions.hubs.access_control.subtitle',
                'cards' => [
                    self::card('roles', 'settings/roles', 'account-details', self::SUPERUSER, 'indigo'),
                    self::card('permissions', 'settings/permissions', 'lock-outline', self::SUPERUSER, 'red'),
                ],
            ],
        ];
    }
}
