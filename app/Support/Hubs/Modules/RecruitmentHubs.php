<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Recruitment hubs: Hiring (jobs, vacancies, requisitions, offers), Pipeline (candidates,
 * approvals, internal mobility), Insights and Configure. All recruitment ribbons are HR-only
 * (SUPERUSER) today and the cards keep that tier.
 */
class RecruitmentHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'recruitment';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'recruitment/dashboard', 'icon' => 'gauge-high', 'tier' => self::SUPERUSER],
        ];
    }

    public static function hubs(): array
    {
        return [
            'hiring' => [
                'name' => 'Hiring', 'path' => 'recruitment/hiring-hub', 'icon' => 'briefcase', 'tier' => self::SUPERUSER,
                'title' => 'recruitment.hubs.hiring.title', 'subtitle' => 'recruitment.hubs.hiring.subtitle',
                'cards' => [
                    self::card('jobs', 'recruitment/jobs', 'briefcase-outline', self::SUPERUSER, 'indigo'),
                    self::card('vacancies', 'recruitment/vacancies', 'bullhorn-outline', self::SUPERUSER, 'blue'),
                    self::card('requisitions', 'recruitment/requisitions', 'file-sign', self::SUPERUSER, 'teal'),
                    self::card('offers', 'offers', 'file-document-edit-outline', self::SUPERUSER, 'green'),
                ],
            ],
            'pipeline' => [
                'name' => 'Pipeline', 'path' => 'recruitment/pipeline-hub', 'icon' => 'users-group', 'tier' => self::SUPERUSER,
                'title' => 'recruitment.hubs.pipeline.title', 'subtitle' => 'recruitment.hubs.pipeline.subtitle',
                'cards' => [
                    self::card('candidates', 'recruitment/candidates', 'account-tie-outline', self::SUPERUSER, 'indigo'),
                    self::card('approvals', 'recruitment/approvals/pending', 'check-all', self::SUPERUSER, 'orange'),
                    self::card('internal_board', 'recruitment/internal/job-board', 'office-building-outline', self::SUPERUSER, 'cyan'),
                    self::card('succession', 'recruitment/succession', 'sitemap', self::SUPERUSER, 'deep-purple'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'recruitment/insights-hub', 'icon' => 'chart-bar', 'tier' => self::SUPERUSER,
                'title' => 'recruitment.hubs.insights.title', 'subtitle' => 'recruitment.hubs.insights.subtitle',
                'cards' => [
                    self::card('analytics', 'recruitment/analytics', 'chart-bar', self::SUPERUSER, 'blue'),
                    self::card('reports', 'recruitment/reports', 'chart-line', self::SUPERUSER, 'teal'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'recruitment/configure-hub', 'icon' => 'cog', 'tier' => self::SUPERUSER,
                'title' => 'recruitment.hubs.configure.title', 'subtitle' => 'recruitment.hubs.configure.subtitle',
                'cards' => [
                    self::card('approval_workflows', 'recruitment/settings/approval-workflows', 'sitemap', self::SUPERUSER, 'blue-grey'),
                    self::card('social_accounts', 'recruitment/settings/social-accounts', 'share-variant-outline', self::SUPERUSER, 'pink'),
                ],
            ],
        ];
    }
}
