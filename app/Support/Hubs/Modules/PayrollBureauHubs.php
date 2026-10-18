<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Payroll Bureau is a single-screen module — registered for nav/tier resolution, with no hub landing to put in front of its one page.
 */
class PayrollBureauHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'payroll_bureau';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'bureau_companies', 'name' => 'Bureau Companies', 'path' => 'payroll/bureau', 'icon' => 'building', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [

        ];
    }
}
