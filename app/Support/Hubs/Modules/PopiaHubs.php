<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * POPIA Compliance hubs: a single Registers hub for the breach, consent and PHI access registers. The dashboard stays a direct entry.
 */
class PopiaHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'popia_compliance';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'compliance', 'icon' => 'layout-dashboard', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'registers' => [
                'name' => 'Registers', 'path' => 'compliance/registers-hub', 'icon' => 'clipboard-check', 'tier' => self::SUPERUSER,
                'title' => 'popia_compliance.hubs.registers.title', 'subtitle' => 'popia_compliance.hubs.registers.subtitle',
                'cards' => [
                    self::card('breaches', 'compliance/breaches', 'alert-outline', self::SUPERUSER, 'red'),
                    self::card('consents', 'compliance/consents', 'clipboard-check-outline', self::SUPERUSER, 'green'),
                    self::card('phi_log', 'compliance/phi-log', 'eye-outline', self::SUPERUSER, 'blue'),
                ],
            ],
        ];
    }
}
