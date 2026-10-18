<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Disciplinary & Misconduct hubs: My Dashboard (employee self-service) + Cases / Hearings /
 * Intelligence / Insights / Configure. HR-sensitive: a normal employee only sees their own
 * dashboard; all case, hearing and intelligence actions are manager (MODIFY) or HR (ADMIN).
 */
class DisciplinaryHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'disciplinary';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'my_dashboard', 'name' => 'My Dashboard', 'path' => 'disciplinary/my-dashboard', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'cases' => [
                'name' => 'Cases', 'path' => 'disciplinary/cases-hub', 'icon' => 'folder', 'tier' => self::MODIFY,
                'title' => 'disciplinary.hubs.cases.title', 'subtitle' => 'disciplinary.hubs.cases.subtitle',
                'cards' => [
                    self::card('dashboard', 'disciplinary', 'view-dashboard-outline', self::MODIFY, 'blue'),
                    self::card('cases', 'disciplinary/cases', 'briefcase-outline', self::MODIFY, 'indigo'),
                    self::card('incidents', 'disciplinary/incidents', 'alert-circle-outline', self::MODIFY, 'orange'),
                    self::card('investigations', 'disciplinary/investigations', 'magnify', self::MODIFY, 'teal'),
                    self::card('corrective_actions', 'disciplinary/corrective-actions', 'wrench-outline', self::MODIFY, 'green'),
                    self::card('appeals', 'disciplinary/appeals', 'scale-balance', self::MODIFY, 'blue-grey'),
                ],
            ],
            'hearings' => [
                'name' => 'Hearings', 'path' => 'disciplinary/hearings-hub', 'icon' => 'gavel', 'tier' => self::MODIFY,
                'title' => 'disciplinary.hubs.hearings.title', 'subtitle' => 'disciplinary.hubs.hearings.subtitle',
                'cards' => [
                    self::card('hearings', 'disciplinary/hearings', 'gavel', self::MODIFY, 'deep-purple'),
                    self::card('warnings', 'disciplinary/progressive-discipline/warnings', 'alert-outline', self::MODIFY, 'orange'),
                    self::card('suspensions', 'disciplinary/progressive-discipline/suspensions', 'account-cancel-outline', self::MODIFY, 'red'),
                    self::card('escalations', 'disciplinary/progressive-discipline/escalations', 'arrow-up-bold-outline', self::MODIFY, 'pink'),
                ],
            ],
            'intelligence' => [
                'name' => 'Intelligence', 'path' => 'disciplinary/intelligence-hub', 'icon' => 'brain', 'tier' => self::ADMIN,
                'title' => 'disciplinary.hubs.intelligence.title', 'subtitle' => 'disciplinary.hubs.intelligence.subtitle',
                'cards' => [
                    self::card('ai_insights', 'disciplinary/ai-insights', 'robot-outline', self::ADMIN, 'deep-purple'),
                    self::card('policy_enforcement', 'disciplinary/policy-enforcement', 'shield-check-outline', self::ADMIN, 'green'),
                    self::card('workforce_intelligence', 'disciplinary/workforce-intelligence', 'chart-box-outline', self::ADMIN, 'blue'),
                    self::card('risk_intelligence', 'disciplinary/risk-intelligence', 'alert-decagram-outline', self::ADMIN, 'red'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'disciplinary/insights-hub', 'icon' => 'chart-line', 'tier' => self::ADMIN,
                'title' => 'disciplinary.hubs.insights.title', 'subtitle' => 'disciplinary.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'disciplinary/reports', 'file-chart-outline', self::ADMIN, 'blue'),
                    self::card('scheduled_reports', 'disciplinary/reports/scheduled', 'calendar-clock-outline', self::ADMIN, 'orange'),
                    self::card('executive_dashboard', 'disciplinary/executive-dashboard', 'chart-box-outline', self::ADMIN, 'deep-purple'),
                    self::card('executive_ai', 'disciplinary/executive-ai-insights', 'robot-happy-outline', self::ADMIN, 'teal'),
                    self::card('notifications', 'disciplinary/notifications-escalations', 'bell-alert-outline', self::ADMIN, 'red'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'disciplinary/configure-hub', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'disciplinary.hubs.configure.title', 'subtitle' => 'disciplinary.hubs.configure.subtitle',
                'cards' => [
                    self::card('setup', 'disciplinary/setup', 'cog-outline', self::ADMIN, 'blue-grey'),
                ],
            ],
        ];
    }
}
