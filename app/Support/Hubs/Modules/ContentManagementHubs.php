<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Content Management hubs: Publish (news, announcements, events), Engage (spotlight, email
 * campaigns) and Insights. Shares the cms/ prefix with Resources, so hub paths are
 * namespaced cms/cm-*.
 */
class ContentManagementHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'content_management';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'cms/dashaboard-cm', 'icon' => 'chart-line', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'publish' => [
                'name' => 'Publish', 'path' => 'cms/cm-publish-hub', 'icon' => 'newspaper', 'tier' => self::SUPERUSER,
                'title' => 'content_management.hubs.publish.title', 'subtitle' => 'content_management.hubs.publish.subtitle',
                'cards' => [
                    self::card('news', 'cms/news', 'newspaper-variant-outline', self::SUPERUSER, 'indigo'),
                    self::card('announcements', 'cms/announcements', 'bullhorn-outline', self::SUPERUSER, 'blue'),
                    self::card('events', 'cms/events', 'calendar-month-outline', self::SUPERUSER, 'teal'),
                    self::card('ceo_message', 'cms/ceo-message', 'microphone-outline', self::SUPERUSER, 'deep-purple'),
                    self::card('internal_notice', 'cms/internal-notice', 'pin-outline', self::SUPERUSER, 'amber'),
                ],
            ],
            'engage' => [
                'name' => 'Engage', 'path' => 'cms/cm-engage-hub', 'icon' => 'heart', 'tier' => self::SUPERUSER,
                'title' => 'content_management.hubs.engage.title', 'subtitle' => 'content_management.hubs.engage.subtitle',
                'cards' => [
                    self::card('employee_team', 'cms/employee-team', 'trophy-outline', self::SUPERUSER, 'green'),
                    self::card('campaigns', 'cms/campaigns', 'email-open-outline', self::SUPERUSER, 'pink'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'cms/cm-insights-hub', 'icon' => 'chart-bar', 'tier' => self::SUPERUSER,
                'title' => 'content_management.hubs.insights.title', 'subtitle' => 'content_management.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'cms/reports-cm', 'chart-bar', self::SUPERUSER, 'blue'),
                ],
            ],
        ];
    }
}
