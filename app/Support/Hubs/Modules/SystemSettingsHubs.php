<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * System Settings hubs: Platform (modules, admins, restoration), Workplace (holidays,
 * document types, offline guard) and Help. There is no dashboard page for this module, so
 * Company Identity is the direct entry.
 */
class SystemSettingsHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'system_settings';
    }

    public static function direct(): array
    {
        return [
            /*
             * Its own entry rather than a card inside the Platform hub.
             *
             * It was a card there, two levels down, beside module activation and system
             * restoration — housekeeping somebody does once. This is a page a finance or operations
             * lead opens whenever they want their data somewhere they can report on it, and
             * burying it under "Modules, admins and recovery" told them it was none of their
             * business.
             */
            ['key' => 'bi_export', 'name' => 'Data Export', 'path' => 'settings/bi-export',
                'icon' => 'chart-box-outline', 'tier' => self::SUPERUSER],
            ['key' => 'company_identity', 'name' => 'Company Identity', 'path' => 'settings/setup', 'icon' => 'cog', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'platform' => [
                'name' => 'Platform', 'path' => 'settings/platform-hub', 'icon' => 'server', 'tier' => self::SUPERUSER,
                'title' => 'system_settings.hubs.platform.title', 'subtitle' => 'system_settings.hubs.platform.subtitle',
                'cards' => [
                    self::card('modules', 'settings/category', 'view-grid-outline', self::SUPERUSER, 'indigo'),
                    self::card('platform_admins', 'settings/platform-admins', 'shield-account-outline', self::SUPERUSER, 'red'),
                    self::card('restoration', 'settings/restoration', 'restore', self::SUPERUSER, 'orange'),
                ],
            ],
            'workplace' => [
                'name' => 'Workplace', 'path' => 'settings/workplace-hub', 'icon' => 'building', 'tier' => self::SUPERUSER,
                'title' => 'system_settings.hubs.workplace.title', 'subtitle' => 'system_settings.hubs.workplace.subtitle',
                'cards' => [
                    self::card('public_holidays', 'settings/public-holidays', 'calendar-star', self::SUPERUSER, 'teal'),
                    self::card('document_types', 'settings/document-types', 'file-outline', self::SUPERUSER, 'blue'),
                    self::card('offline_policy', 'settings/offline-policy', 'wifi-off', self::SUPERUSER, 'blue-grey'),
                ],
            ],
            'help' => [
                'name' => 'Help', 'path' => 'settings/help-hub', 'icon' => 'book', 'tier' => self::SUPERUSER,
                'title' => 'system_settings.hubs.help.title', 'subtitle' => 'system_settings.hubs.help.subtitle',
                'cards' => [
                    self::card('how_to', 'how-to', 'book-open-page-variant', self::SUPERUSER, 'green'),
                ],
            ],
        ];
    }
}
