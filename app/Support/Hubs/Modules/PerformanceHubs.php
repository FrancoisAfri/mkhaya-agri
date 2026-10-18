<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Performance Management hubs: My Dashboard + My Performance (employee, WRITE) / Team (manager,
 * MODIFY) / Manage (HR, ADMIN) / Insights / Configure. Compensation, DEI and cycles are HR-only
 * (ADMIN); managers run calibration/PIP for their team; employees own their goals and check-ins.
 */
class PerformanceHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'performance_appraisal';
    }

    public static function guards(): array
    {
        return ['performance' => self::WRITE];
    }

    public static function direct(): array
    {
        return [
            ['key' => 'my_dashboard', 'name' => 'My Dashboard', 'path' => 'performance/my-dashboard', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'my_performance' => [
                'name' => 'My Performance', 'path' => 'performance/my-performance-hub', 'icon' => 'target', 'tier' => self::WRITE,
                'title' => 'performance_appraisal.hubs.my_performance.title', 'subtitle' => 'performance_appraisal.hubs.my_performance.subtitle',
                'cards' => [
                    self::card('goals', 'performance/goals', 'bullseye-arrow', self::WRITE, 'blue'),
                    self::card('kpis', 'performance/kpis', 'gauge', self::WRITE, 'indigo'),
                    self::card('checkins', 'performance/checkins', 'calendar-check-outline', self::WRITE, 'teal'),
                    self::card('feedback', 'performance/feedback', 'comment-quote-outline', self::WRITE, 'green'),
                    self::card('reviews_360', 'performance/360', 'account-multiple-outline', self::WRITE, 'deep-purple'),
                    self::card('development', 'performance/development', 'sprout-outline', self::WRITE, 'light-green'),
                    self::card('wellness', 'performance/wellness', 'heart-pulse', self::WRITE, 'pink'),
                    self::card('recognition', 'performance/recognition', 'trophy-outline', self::WRITE, 'amber'),
                ],
            ],
            'team' => [
                'name' => 'Team', 'path' => 'performance/team-hub', 'icon' => 'users-group', 'tier' => self::MODIFY,
                'title' => 'performance_appraisal.hubs.team.title', 'subtitle' => 'performance_appraisal.hubs.team.subtitle',
                'cards' => [
                    self::card('team_dashboard', 'performance/manager-dashboard', 'account-group-outline', self::MODIFY, 'blue'),
                    self::card('calibration', 'performance/calibration', 'tune-vertical', self::MODIFY, 'indigo'),
                    self::card('pip', 'performance/pip', 'clipboard-alert-outline', self::MODIFY, 'orange'),
                    self::card('probation', 'performance/probation', 'account-clock-outline', self::MODIFY, 'teal'),
                    self::card('talent_growth', 'performance/talent-growth', 'trending-up', self::MODIFY, 'green'),
                ],
            ],
            'manage' => [
                'name' => 'Manage', 'path' => 'performance/manage-hub', 'icon' => 'briefcase', 'tier' => self::ADMIN,
                'title' => 'performance_appraisal.hubs.manage.title', 'subtitle' => 'performance_appraisal.hubs.manage.subtitle',
                'cards' => [
                    self::card('cycles', 'performance/cycles', 'sync', self::ADMIN, 'blue'),
                    self::card('succession', 'performance/succession', 'account-supervisor-outline', self::ADMIN, 'indigo'),
                    self::card('nine_box', 'performance/nine-box', 'grid', self::ADMIN, 'teal'),
                    self::card('goal_tree', 'performance/goals/tree', 'file-tree-outline', self::ADMIN, 'green'),
                    self::card('compensation', 'performance/compensation', 'cash-multiple', self::ADMIN, 'red'),
                    self::card('competencies', 'performance/competencies', 'star-cog-outline', self::ADMIN, 'deep-purple'),
                    self::card('policy', 'performance/policy', 'file-document-outline', self::ADMIN, 'blue-grey'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'performance/insights-hub', 'icon' => 'chart-line', 'tier' => self::MODIFY,
                'title' => 'performance_appraisal.hubs.insights.title', 'subtitle' => 'performance_appraisal.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'performance/reports', 'file-chart-outline', self::MODIFY, 'blue'),
                    self::card('executive_graphs', 'performance/reports/executive', 'chart-box-outline', self::ADMIN, 'deep-purple'),
                    self::card('scheduled_reports', 'performance/reports/scheduled', 'calendar-clock-outline', self::MODIFY, 'orange'),
                    self::card('dei', 'performance/dei', 'scale-balance', self::ADMIN, 'red'),
                    self::card('ai_insights', 'performance/ai', 'robot-happy-outline', self::MODIFY, 'cyan'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'performance/configure-hub', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'performance_appraisal.hubs.configure.title', 'subtitle' => 'performance_appraisal.hubs.configure.subtitle',
                'cards' => [
                    self::card('templates', 'performance/templates', 'file-document-multiple-outline', self::ADMIN, 'blue'),
                    self::card('rating_scales', 'performance/rating-scales', 'star-outline', self::ADMIN, 'amber'),
                    self::card('settings', 'performance/settings', 'cog-outline', self::ADMIN, 'blue-grey'),
                ],
            ],
        ];
    }
}
