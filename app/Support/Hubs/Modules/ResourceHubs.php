<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Resources hubs: Library (handbook, SOPs, templates, training), Governance (legal, health &
 * safety, branding) and Insights. Shares the cms/ prefix with Content Management, so hub
 * paths are namespaced cms/resources-*.
 */
class ResourceHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'resources';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'cms/dashaboard-resources', 'icon' => 'chart-line', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'library' => [
                'name' => 'Library', 'path' => 'cms/resources-library-hub', 'icon' => 'bookshelf', 'tier' => self::SUPERUSER,
                'title' => 'resources.hubs.library.title', 'subtitle' => 'resources.hubs.library.subtitle',
                'cards' => [
                    self::card('employee_handbook', 'cms/employee-handbook', 'book-open-page-variant', self::SUPERUSER, 'indigo'),
                    self::card('sops', 'cms/sops', 'clipboard-list-outline', self::SUPERUSER, 'blue'),
                    self::card('templates', 'cms/templates', 'content-duplicate', self::SUPERUSER, 'teal'),
                    self::card('training_material', 'cms/training-material', 'school-outline', self::SUPERUSER, 'green'),
                    self::card('forms', 'cms/forms', 'file-sign', self::SUPERUSER, 'cyan'),
                    self::card('downloads', 'cms/downloads', 'download-outline', self::SUPERUSER, 'blue-grey'),
                ],
            ],
            'governance' => [
                'name' => 'Governance', 'path' => 'cms/resources-governance-hub', 'icon' => 'scale-balanced', 'tier' => self::SUPERUSER,
                'title' => 'resources.hubs.governance.title', 'subtitle' => 'resources.hubs.governance.subtitle',
                'cards' => [
                    self::card('nda_legal', 'cms/nda-legal', 'scale-balance', self::SUPERUSER, 'deep-purple'),
                    self::card('health_safety', 'cms/health-safety', 'shield-half-full', self::SUPERUSER, 'red'),
                    self::card('corporate_branding', 'cms/corporate-branding', 'palette-outline', self::SUPERUSER, 'pink'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'cms/resources-insights-hub', 'icon' => 'chart-bar', 'tier' => self::SUPERUSER,
                'title' => 'resources.hubs.insights.title', 'subtitle' => 'resources.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'cms/reports-resources', 'chart-bar', self::SUPERUSER, 'blue'),
                ],
            ],
        ];
    }
}
