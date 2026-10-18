<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Petty Cash hubs: Operations (floats and requests), Assurance (reconciliation, violations,
 * reports) and Configure.
 */
class PettyCashHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'petty_cash';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'petty-cash', 'icon' => 'layout-dashboard', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'operations' => [
                'name' => 'Operations', 'path' => 'petty-cash/operations-hub', 'icon' => 'cash-multiple', 'tier' => self::SUPERUSER,
                'title' => 'petty_cash.hubs.operations.title', 'subtitle' => 'petty_cash.hubs.operations.subtitle',
                'cards' => [
                    self::card('floats', 'petty-cash/floats', 'cash-multiple', self::SUPERUSER, 'indigo'),
                    self::card('new_float', 'petty-cash/floats/create', 'plus-circle-outline', self::SUPERUSER, 'green'),
                    self::card('requests', 'petty-cash/requests', 'file-document-outline', self::SUPERUSER, 'blue'),
                    self::card('new_request', 'petty-cash/requests/create', 'plus-circle-outline', self::SUPERUSER, 'teal'),
                ],
            ],
            'assurance' => [
                'name' => 'Assurance', 'path' => 'petty-cash/assurance-hub', 'icon' => 'scale-balance', 'tier' => self::SUPERUSER,
                'title' => 'petty_cash.hubs.assurance.title', 'subtitle' => 'petty_cash.hubs.assurance.subtitle',
                'cards' => [
                    self::card('reconciliations', 'petty-cash/reconciliations', 'scale-balance', self::SUPERUSER, 'cyan'),
                    self::card('violations', 'petty-cash/settings/violations', 'shield-alert-outline', self::SUPERUSER, 'orange'),
                    self::card('reports', 'petty-cash/reports', 'chart-bar', self::SUPERUSER, 'blue'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'petty-cash/configure-hub', 'icon' => 'cog', 'tier' => self::SUPERUSER,
                'title' => 'petty_cash.hubs.configure.title', 'subtitle' => 'petty_cash.hubs.configure.subtitle',
                'cards' => [
                    self::card('settings', 'petty-cash/settings', 'cog-outline', self::SUPERUSER, 'blue-grey'),
                ],
            ],
        ];
    }
}
