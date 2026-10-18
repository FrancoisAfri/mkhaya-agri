<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * User Management hubs: a single Access hub for rights and profile-update approvals. Manage Users stays a direct entry — it is the module's main screen.
 */
class UserManagementHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'user_management';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'manage_users', 'name' => 'Manage Users', 'path' => 'settings/manage-users', 'icon' => 'users', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'access' => [
                'name' => 'Access', 'path' => 'settings/user-access-hub', 'icon' => 'key', 'tier' => self::SUPERUSER,
                'title' => 'user_management.hubs.access.title', 'subtitle' => 'user_management.hubs.access.subtitle',
                'cards' => [
                    self::card('user_access', 'settings/user-access', 'key-outline', self::SUPERUSER, 'indigo'),
                    self::card('profile_approvals', 'settings/profile-updates-approval', 'account-check-outline', self::SUPERUSER, 'orange'),
                ],
            ],
        ];
    }
}
