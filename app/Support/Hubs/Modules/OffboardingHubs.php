<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Offboarding hubs: Insights (analytics and reports) and Configure (templates). Offboarding Plans stays a direct entry — it is the module's working screen.
 */
class OffboardingHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'offboarding';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'plans', 'name' => 'Offboarding Plans', 'path' => 'offboarding', 'icon' => 'layout-dashboard', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'insights' => [
                'name' => 'Insights', 'path' => 'offboarding/insights-hub', 'icon' => 'chart-bar', 'tier' => self::SUPERUSER,
                'title' => 'offboarding.hubs.insights.title', 'subtitle' => 'offboarding.hubs.insights.subtitle',
                'cards' => [
                    self::card('analytics', 'offboarding/analytics', 'chart-bar', self::SUPERUSER, 'deep-purple'),
                    self::card('reports', 'offboarding/reports', 'file-chart-outline', self::SUPERUSER, 'blue'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'offboarding/configure-hub', 'icon' => 'cog', 'tier' => self::SUPERUSER,
                'title' => 'offboarding.hubs.configure.title', 'subtitle' => 'offboarding.hubs.configure.subtitle',
                'cards' => [
                    self::card('templates', 'offboarding/templates', 'file-document-outline', self::SUPERUSER, 'blue-grey'),
                ],
            ],
        ];
    }
}
