<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Claims hubs: My Claims (self-service), Approvals & Payments, Insights and Configure.
 * Mirrors the module's current SUPERUSER ribbons — the self-service pages keep their own
 * routes, so tightening these tiers is a separate, deliberate decision.
 */
class ClaimsHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'claims';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'claims', 'icon' => 'layout-dashboard', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'my_claims' => [
                'name' => 'My Claims', 'path' => 'claims/my-hub', 'icon' => 'receipt', 'tier' => self::SUPERUSER,
                'title' => 'claims.hubs.my_claims.title', 'subtitle' => 'claims.hubs.my_claims.subtitle',
                'cards' => [
                    self::card('my_claims', 'claims/my', 'receipt', self::SUPERUSER, 'indigo'),
                    self::card('new_claim', 'claims/my/create', 'plus-circle-outline', self::SUPERUSER, 'green'),
                ],
            ],
            'approvals' => [
                'name' => 'Approvals', 'path' => 'claims/approvals-hub', 'icon' => 'check-decagram', 'tier' => self::SUPERUSER,
                'title' => 'claims.hubs.approvals.title', 'subtitle' => 'claims.hubs.approvals.subtitle',
                'cards' => [
                    self::card('approvals', 'claims/approvals', 'check-decagram', self::SUPERUSER, 'orange'),
                    self::card('delegations', 'claims/approvals/delegations', 'account-group-outline', self::SUPERUSER, 'blue'),
                    self::card('payments', 'claims/payments', 'cash', self::SUPERUSER, 'green'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'claims/insights-hub', 'icon' => 'chart-bar', 'tier' => self::SUPERUSER,
                'title' => 'claims.hubs.insights.title', 'subtitle' => 'claims.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'claims/reports', 'chart-bar', self::SUPERUSER, 'blue'),
                    self::card('scheduled_reports', 'claims/reports/scheduled', 'calendar-clock', self::SUPERUSER, 'teal'),
                    self::card('executive', 'claims/executive', 'chart-line', self::SUPERUSER, 'deep-purple'),
                    self::card('ai_intelligence', 'claims/ai-intelligence', 'robot-outline', self::SUPERUSER, 'pink'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'claims/configure-hub', 'icon' => 'cog', 'tier' => self::SUPERUSER,
                'title' => 'claims.hubs.configure.title', 'subtitle' => 'claims.hubs.configure.subtitle',
                'cards' => [
                    self::card('setup', 'claims/setup', 'cog-outline', self::SUPERUSER, 'blue-grey'),
                ],
            ],
        ];
    }
}
