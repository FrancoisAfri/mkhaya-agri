<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Budget Management hubs.
 *
 * Four groups rather than one flat run of ten ribbons: Budgets (the register and capture),
 * Governance (the queue, the alerts, and the rules behind both), Insights (BI), and Configure.
 *
 * The approval designer is the one card above READ. Designing who signs off what is not something a
 * viewer should reach — and because hiding a card is not access control on its own, its routes also
 * require `update` on Budget through the policy.
 */
class BudgetHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'budget_management';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'budgets', 'icon' => 'tachometer-alt', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'budgets' => [
                'name' => 'Budgets', 'path' => 'budgets/manage-hub', 'icon' => 'cash', 'tier' => self::READ,
                'title' => 'budget_management.hubs.budgets.title', 'subtitle' => 'budget_management.hubs.budgets.subtitle',
                'cards' => [
                    self::card('budgets', 'budgets/list', 'cash', self::READ, 'indigo'),
                    self::card('new_budget', 'budgets/create', 'plus-circle-outline', self::WRITE, 'green'),
                    self::card('planning', 'budgets/planning', 'calendar-clock', self::WRITE, 'teal'),
                ],
            ],
            'governance' => [
                'name' => 'Governance', 'path' => 'budgets/governance-hub', 'icon' => 'shield-check', 'tier' => self::READ,
                'title' => 'budget_management.hubs.governance.title', 'subtitle' => 'budget_management.hubs.governance.subtitle',
                'cards' => [
                    self::card('approvals', 'budgets/approvals', 'check-decagram-outline', self::READ, 'primary'),
                    self::card('tracking', 'budgets/tracking', 'alert-outline', self::READ, 'warning'),
                    self::card('approval_setup', 'budgets/settings/approvals', 'sitemap-outline', self::ADMIN, 'deep-purple'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'budgets/insights-hub', 'icon' => 'chart-pie', 'tier' => self::READ,
                'title' => 'budget_management.hubs.insights.title', 'subtitle' => 'budget_management.hubs.insights.subtitle',
                'cards' => [
                    self::card('analytics', 'budgets/analytics', 'chart-donut', self::READ, 'cyan'),
                    self::card('reports', 'budgets/reports', 'file-chart-outline', self::READ, 'blue'),
                ],
            ],
            // Left at READ, as it was. Raising it would take Settings away from people who can
            // reach it today, which is a change nobody asked for; the new approval designer carries
            // its own ADMIN tier above, and the policy check on its routes is the real guard.
            'configure' => [
                'name' => 'Configure', 'path' => 'budgets/configure-hub', 'icon' => 'cog', 'tier' => self::READ,
                'title' => 'budget_management.hubs.configure.title', 'subtitle' => 'budget_management.hubs.configure.subtitle',
                'cards' => [
                    self::card('settings', 'budgets/settings', 'cog-outline', self::READ, 'blue-grey'),
                ],
            ],
        ];
    }
}
