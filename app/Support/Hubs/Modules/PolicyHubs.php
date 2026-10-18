<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Policy enforcement hubs: Policies (library and quality), Attestations, Enforcement
 * (violations, exceptions, simulation) and Insights. This module's routes are top-level
 * (policies, policy-*), so hub paths follow the same shape.
 */
class PolicyHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'policy_enforcement';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'policy-dashboard', 'icon' => 'chart-line', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'policies' => [
                'name' => 'Policies', 'path' => 'policies-hub', 'icon' => 'file-contract', 'tier' => self::SUPERUSER,
                'title' => 'policy_enforcement.hubs.policies.title', 'subtitle' => 'policy_enforcement.hubs.policies.subtitle',
                'cards' => [
                    self::card('policies', 'policies', 'file-sign', self::SUPERUSER, 'indigo'),
                    self::card('categories', 'policy-categories', 'tag-multiple', self::SUPERUSER, 'blue'),
                    self::card('quality', 'policy-quality', 'check-all', self::SUPERUSER, 'teal'),
                ],
            ],
            'attestations' => [
                'name' => 'Attestations', 'path' => 'policy-attestations-hub', 'icon' => 'clipboard-check', 'tier' => self::SUPERUSER,
                'title' => 'policy_enforcement.hubs.attestations.title', 'subtitle' => 'policy_enforcement.hubs.attestations.subtitle',
                'cards' => [
                    self::card('attestations', 'policy-attestations', 'clipboard-check-outline', self::SUPERUSER, 'green'),
                    self::card('attestation_dashboard', 'policy-attestations/dashboard/overview', 'chart-pie', self::SUPERUSER, 'cyan'),
                ],
            ],
            'enforcement' => [
                'name' => 'Enforcement', 'path' => 'policy-enforcement-hub', 'icon' => 'shield-alt', 'tier' => self::SUPERUSER,
                'title' => 'policy_enforcement.hubs.enforcement.title', 'subtitle' => 'policy_enforcement.hubs.enforcement.subtitle',
                'cards' => [
                    self::card('violations', 'policy-violations', 'alert-outline', self::SUPERUSER, 'orange'),
                    self::card('exceptions', 'policy-exceptions', 'shield-outline', self::SUPERUSER, 'amber'),
                    self::card('simulation', 'policy-simulation', 'flask-outline', self::SUPERUSER, 'deep-purple'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'policy-insights-hub', 'icon' => 'chart-bar', 'tier' => self::SUPERUSER,
                'title' => 'policy_enforcement.hubs.insights.title', 'subtitle' => 'policy_enforcement.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'policy-reports', 'file-document-outline', self::SUPERUSER, 'blue'),
                ],
            ],
        ];
    }
}
