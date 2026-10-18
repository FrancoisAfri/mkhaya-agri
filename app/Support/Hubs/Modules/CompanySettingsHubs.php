<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Company Settings hubs: a single Structure hub covering the org structure and positions.
 *
 * Organisation Structure used to sit beside the hub as a direct entry as well, so the sidebar
 * offered two ways to the same page — one of them a level above the other, and neither explaining
 * why both existed. The hub is where it belongs, next to Positions, which is the other half of the
 * same subject.
 */
class CompanySettingsHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'company_settings';
    }

    public static function direct(): array
    {
        // Nothing direct: everything here is reached through the Structure hub below.
        return [];
    }

    public static function hubs(): array
    {
        return [
            'structure' => [
                'name' => 'Structure', 'path' => 'settings/structure-hub', 'icon' => 'id-badge', 'tier' => self::SUPERUSER,
                'title' => 'company_settings.hubs.structure.title', 'subtitle' => 'company_settings.hubs.structure.subtitle',
                'cards' => [
                    self::card('organization', 'settings/organization', 'sitemap', self::SUPERUSER, 'indigo'),
                    self::card('positions', 'settings/positions', 'badge-account-outline', self::SUPERUSER, 'teal'),
                ],
            ],
        ];
    }
}
