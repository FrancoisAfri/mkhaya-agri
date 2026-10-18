<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Contract Management hubs. Collapses the 15 flat ribbons into a Dashboard plus four
 * role-tiered hubs — Register (day-to-day contract work), Workflow (requests, approvals,
 * appraisals), Insights (reports/analytics) and Configure (settings). Viewing is READ,
 * authoring is WRITE, approving/appraising is MODIFY (manager), reporting & configuration
 * are ADMIN. Feature routes are untouched; only the navigation is reorganised and gated.
 */
class ContractManagementHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'contract_management';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'contracts', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'register' => [
                'name' => 'Register', 'path' => 'contracts/register-hub', 'icon' => 'file-text', 'tier' => self::READ,
                'title' => 'contract_management.hubs.register.title', 'subtitle' => 'contract_management.hubs.register.subtitle',
                'cards' => [
                    self::card('contracts', 'contracts/list', 'file-document-multiple-outline', self::READ, 'blue'),
                    self::card('create', 'contracts/create', 'file-plus-outline', self::WRITE, 'green'),
                    self::card('bulk_upload', 'contracts/bulk-upload', 'upload-multiple', self::WRITE, 'indigo'),
                    self::card('search', 'contracts/search', 'file-search-outline', self::READ, 'teal'),
                    self::card('templates', 'contracts/templates', 'file-cog-outline', self::WRITE, 'deep-purple'),
                    self::card('clauses', 'contracts/clauses', 'format-list-bulleted-type', self::WRITE, 'orange'),
                    self::card('copilot', 'contracts/copilot', 'robot-happy-outline', self::WRITE, 'cyan'),
                ],
            ],
            'workflow' => [
                'name' => 'Workflow', 'path' => 'contracts/workflow-hub', 'icon' => 'route', 'tier' => self::WRITE,
                'title' => 'contract_management.hubs.workflow.title', 'subtitle' => 'contract_management.hubs.workflow.subtitle',
                'cards' => [
                    self::card('requests', 'contracts/requests', 'clipboard-text-outline', self::WRITE, 'blue'),
                    self::card('approvals', 'contracts/approvals', 'clipboard-check-outline', self::MODIFY, 'teal'),
                    self::card('appraisals', 'contracts/appraisals', 'star-outline', self::MODIFY, 'amber'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'contracts/insights-hub', 'icon' => 'chart-line', 'tier' => self::ADMIN,
                'title' => 'contract_management.hubs.insights.title', 'subtitle' => 'contract_management.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'contracts/reports', 'file-chart-outline', self::ADMIN, 'blue'),
                    self::card('analytics', 'contracts/analytics', 'chart-line', self::ADMIN, 'indigo'),
                    self::card('scheduled_reports', 'contracts/scheduled-reports', 'calendar-clock-outline', self::ADMIN, 'orange'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'contracts/configure-hub', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'contract_management.hubs.configure.title', 'subtitle' => 'contract_management.hubs.configure.subtitle',
                'cards' => [
                    self::card('settings', 'contracts/settings', 'cog-outline', self::ADMIN, 'blue-grey'),
                ],
            ],
        ];
    }
}
