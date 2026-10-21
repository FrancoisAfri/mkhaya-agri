<?php

namespace App\Modules\CRM\Support;

use App\Support\Hubs\ModuleHubs;

/**
 * Navigation hubs for the CRM module — collapses ~43 feature ribbons into a Dashboard + 7 hubs.
 * Tiers (READ=1 … SUPERUSER=5) mirror the app's ribbon tiers. A WRITE user is a front-line sales
 * rep: they get Workspace/Sell/Engage/Service/Insights but not Data Quality (supervisor) or
 * Configure (admin), and within a hub the supervisor/admin cards stay hidden. Nav (hub) icons are
 * Tabler names (as the header expects); card icons are MDI names (rendered by the Hub page).
 */
class CrmHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'crm';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'crm', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return static::consolidate([
            'workspace' => [
                'name' => 'Workspace', 'path' => 'crm/workspace', 'icon' => 'layout-grid', 'tier' => self::READ,
                'title' => 'crm.hubs.workspace.title', 'subtitle' => 'crm.hubs.workspace.subtitle',
                'cards' => [
                    self::card('my_dashboard', 'crm/my-dashboard', 'view-dashboard-outline', self::READ, 'blue'),
                    self::card('my_day', 'crm/my-day', 'calendar-today', self::WRITE, 'indigo'),
                    self::card('copilot', 'crm/copilot', 'robot-outline', self::WRITE, 'deep-purple'),
                    self::card('team_dashboard', 'crm/team-dashboard', 'account-group-outline', self::MODIFY, 'teal'),
                    self::card('executive_dashboard', 'crm/executive-dashboard', 'chart-box-outline', self::ADMIN, 'blue-grey'),
                ],
            ],
            'sell' => [
                'name' => 'Sell', 'path' => 'crm/sell', 'icon' => 'coin', 'tier' => self::WRITE,
                'title' => 'crm.hubs.sell.title', 'subtitle' => 'crm.hubs.sell.subtitle',
                'cards' => [
                    self::card('leads', 'crm/leads', 'account-plus-outline', self::WRITE, 'green'),
                    self::card('contacts', 'crm/contacts', 'card-account-details-outline', self::WRITE, 'blue'),
                    self::card('accounts', 'crm/accounts', 'office-building-outline', self::WRITE, 'indigo'),
                    self::card('deals', 'crm/deals', 'handshake-outline', self::WRITE, 'deep-purple'),
                    self::card('products', 'crm/products', 'package-variant-closed', self::WRITE, 'orange'),
                    self::card('account_health', 'crm/account-health', 'heart-pulse', self::MODIFY, 'red'),
                    self::card('forecast', 'crm/forecast', 'chart-timeline-variant', self::MODIFY, 'teal'),
                    self::card('targets', 'crm/targets', 'target', self::MODIFY, 'pink'),
                ],
            ],
            'engage' => [
                'name' => 'Engage', 'path' => 'crm/engage', 'icon' => 'send', 'tier' => self::WRITE,
                'title' => 'crm.hubs.engage.title', 'subtitle' => 'crm.hubs.engage.subtitle',
                'cards' => [
                    self::card('activities', 'crm/activities', 'format-list-checks', self::WRITE, 'blue'),
                    self::card('calls', 'crm/calls', 'phone-outline', self::WRITE, 'green'),
                    self::card('meetings', 'crm/meetings', 'calendar-account-outline', self::WRITE, 'indigo'),
                    self::card('discussions', 'crm/discussions', 'forum-outline', self::WRITE, 'teal'),
                    self::card('campaigns', 'crm/campaigns', 'bullhorn-outline', self::MODIFY, 'orange'),
                    self::card('sequences', 'crm/sequences', 'playlist-play', self::MODIFY, 'deep-purple'),
                    self::card('email_templates', 'crm/email-templates', 'email-outline', self::MODIFY, 'cyan'),
                    self::card('suppressions', 'crm/suppressions', 'email-off-outline', self::ADMIN, 'blue-grey'),
                ],
            ],
            'service' => [
                'name' => 'Service', 'path' => 'crm/service', 'icon' => 'heart-handshake', 'tier' => self::WRITE,
                'title' => 'crm.hubs.service.title', 'subtitle' => 'crm.hubs.service.subtitle',
                'cards' => [
                    self::card('complaints', 'crm/complaints', 'alert-circle-outline', self::WRITE, 'red'),
                    self::card('surveys', 'crm/surveys', 'clipboard-text-outline', self::MODIFY, 'blue'),
                    self::card('survey_templates', 'crm/survey-templates', 'clipboard-list-outline', self::MODIFY, 'indigo'),
                    self::card('survey_plans', 'crm/survey-plans', 'calendar-check-outline', self::MODIFY, 'teal'),
                    self::card('survey_ai', 'crm/surveys/recommendations', 'robot-happy-outline', self::MODIFY, 'deep-purple'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'crm/insights', 'icon' => 'chart-line', 'tier' => self::READ,
                'title' => 'crm.hubs.insights.title', 'subtitle' => 'crm.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'crm/reports', 'file-chart-outline', self::READ, 'blue'),
                    self::card('analytics', 'crm/analytics', 'chart-line', self::READ, 'green'),
                    self::card('metrics', 'crm/metrics', 'gauge', self::READ, 'orange'),
                    self::card('scheduled_reports', 'crm/scheduled-reports', 'calendar-clock-outline', self::MODIFY, 'teal'),
                ],
            ],
            'data_quality' => [
                'name' => 'Data Quality', 'path' => 'crm/data-quality', 'icon' => 'shield-check', 'tier' => self::MODIFY,
                'title' => 'crm.hubs.data_quality.title', 'subtitle' => 'crm.hubs.data_quality.subtitle',
                'cards' => [
                    self::card('approvals', 'crm/approvals', 'clipboard-check-outline', self::MODIFY, 'green'),
                    self::card('duplicates', 'crm/duplicates', 'content-duplicate', self::MODIFY, 'orange'),
                    self::card('data_hygiene', 'crm/data-hygiene', 'broom', self::MODIFY, 'teal'),
                    self::card('import', 'crm/import', 'database-import-outline', self::MODIFY, 'indigo'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'crm/configure', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'crm.hubs.configure.title', 'subtitle' => 'crm.hubs.configure.subtitle',
                'cards' => [
                    self::card('custom_fields', 'crm/custom-fields', 'form-textbox', self::ADMIN, 'blue'),
                    self::card('custom_objects', 'crm/objects', 'shape-outline', self::ADMIN, 'indigo'),
                    self::card('automations', 'crm/automations', 'robot-outline', self::ADMIN, 'deep-purple'),
                    self::card('integrations', 'crm/integrations', 'connection', self::ADMIN, 'cyan'),
                    self::card('mailboxes', 'crm/mailboxes', 'inbox-multiple-outline', self::ADMIN, 'teal'),
                    self::card('settings', 'crm/settings', 'cog-outline', self::ADMIN, 'blue-grey'),
                    self::card('webhooks', 'crm/webhooks', 'webhook', self::SUPERUSER, 'orange'),
                    self::card('audit', 'crm/audit', 'history', self::SUPERUSER, 'red'),
                ],
            ],
        ], [
            // Five sidebar ribbons at most: these hubs fold into their neighbours.
            'engage' => ['absorb' => ['service'], 'name' => 'Engage & Service'],
            'insights' => ['absorb' => ['data_quality', 'configure'], 'name' => 'Insights & Settings'],
        ]);
    }
}
