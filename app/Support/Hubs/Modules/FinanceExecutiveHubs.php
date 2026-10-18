<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Finance Executive is a single-screen module — registered for nav/tier resolution, with no hub landing to put in front of its one page.
 */
class FinanceExecutiveHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'finance_executive';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'executive_dashboard', 'name' => 'Executive Dashboard', 'path' => 'finance/executive/dashboard', 'icon' => 'chart-line', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [

        ];
    }
}
