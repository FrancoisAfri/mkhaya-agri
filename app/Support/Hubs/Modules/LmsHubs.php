<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * LMS hubs: My Dashboard + My Learning (learner, READ) / Team (manager, MODIFY) + the L&D-admin
 * hubs Courses / Skills / Compliance / SETA-QCTO / Insights (ADMIN). The `lms/admin` guard floors
 * the whole admin subtree at ADMIN so learners and managers never reach course-admin routes.
 */
class LmsHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'learning_management';
    }

    public static function guards(): array
    {
        return ['lms/admin' => self::ADMIN];
    }

    public static function direct(): array
    {
        return [
            ['key' => 'my_dashboard', 'name' => 'My Dashboard', 'path' => 'lms/my-dashboard', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'my_learning' => [
                'name' => 'My Learning', 'path' => 'lms/my-learning-hub', 'icon' => 'book', 'tier' => self::READ,
                'title' => 'learning_management.hubs.my_learning.title', 'subtitle' => 'learning_management.hubs.my_learning.subtitle',
                'cards' => [
                    self::card('catalog', 'lms/catalog', 'bookshelf', self::READ, 'blue'),
                    self::card('my_learning', 'lms/my-learning', 'book-open-page-variant-outline', self::READ, 'indigo'),
                    self::card('my_learning_plan', 'lms/my-learning-plan', 'calendar-text-outline', self::READ, 'teal'),
                    self::card('my_portfolios', 'lms/my-portfolios', 'folder-account-outline', self::READ, 'green'),
                ],
            ],
            'team' => [
                'name' => 'Team', 'path' => 'lms/team-hub', 'icon' => 'users-group', 'tier' => self::MODIFY,
                'title' => 'learning_management.hubs.team.title', 'subtitle' => 'learning_management.hubs.team.subtitle',
                'cards' => [
                    self::card('team_dashboard', 'lms/manager-dashboard', 'account-group-outline', self::MODIFY, 'blue'),
                    self::card('executive_view', 'lms/executive-dashboard', 'chart-box-outline', self::ADMIN, 'deep-purple'),
                ],
            ],
            'courses' => [
                'name' => 'Courses', 'path' => 'lms/courses-hub', 'icon' => 'school', 'tier' => self::ADMIN,
                'title' => 'learning_management.hubs.courses.title', 'subtitle' => 'learning_management.hubs.courses.subtitle',
                'cards' => [
                    self::card('admin_dashboard', 'lms/admin/dashboard', 'view-dashboard-outline', self::ADMIN, 'blue'),
                    self::card('courses', 'lms/admin/courses', 'book-education-outline', self::ADMIN, 'indigo'),
                    self::card('learning_paths', 'lms/admin/learning-paths', 'map-marker-path', self::ADMIN, 'teal'),
                    self::card('categories', 'lms/admin/categories', 'shape-outline', self::ADMIN, 'green'),
                    self::card('enrollments', 'lms/admin/enrollments', 'account-plus-outline', self::ADMIN, 'orange'),
                    self::card('assessments', 'lms/admin/questionnaires', 'clipboard-text-outline', self::ADMIN, 'deep-purple'),
                    self::card('question_banks', 'lms/admin/question-banks', 'database-outline', self::ADMIN, 'cyan'),
                    self::card('marking', 'lms/admin/marking', 'check-outline', self::ADMIN, 'blue-grey'),
                    self::card('understanding', 'lms/admin/understanding', 'lightbulb-outline', self::ADMIN, 'amber'),
                ],
            ],
            'skills' => [
                'name' => 'Skills', 'path' => 'lms/skills-hub', 'icon' => 'star', 'tier' => self::ADMIN,
                'title' => 'learning_management.hubs.skills.title', 'subtitle' => 'learning_management.hubs.skills.subtitle',
                'cards' => [
                    self::card('skills_library', 'lms/admin/skills', 'star-outline', self::ADMIN, 'blue'),
                    self::card('skills_matrix', 'lms/admin/skills-matrix', 'grid', self::ADMIN, 'indigo'),
                    self::card('succession', 'lms/admin/succession', 'account-supervisor-outline', self::ADMIN, 'teal'),
                    self::card('ai_recommendations', 'lms/admin/ai-recommendations', 'robot-happy-outline', self::ADMIN, 'deep-purple'),
                    self::card('onboarding', 'lms/admin/onboarding', 'account-arrow-right-outline', self::ADMIN, 'green'),
                    self::card('onboarding_rules', 'lms/admin/onboarding/rules', 'ruler', self::ADMIN, 'blue-grey'),
                    self::card('pa_training', 'lms/admin/pa-training', 'school-outline', self::ADMIN, 'orange'),
                    self::card('pa_recommendations', 'lms/admin/pa-training/recommendations', 'lightbulb-on-outline', self::ADMIN, 'amber'),
                    self::card('pa_dev_plans', 'lms/admin/pa-training/plans', 'clipboard-list-outline', self::ADMIN, 'cyan'),
                ],
            ],
            'compliance' => [
                'name' => 'Compliance', 'path' => 'lms/compliance-hub', 'icon' => 'shield-check', 'tier' => self::ADMIN,
                'title' => 'learning_management.hubs.compliance.title', 'subtitle' => 'learning_management.hubs.compliance.subtitle',
                'cards' => [
                    self::card('compliance_dashboard', 'lms/admin/compliance', 'shield-check-outline', self::ADMIN, 'green'),
                    self::card('compliance_rules', 'lms/admin/compliance/rules', 'ruler-square', self::ADMIN, 'blue'),
                    self::card('policy_violations', 'lms/admin/policy-violations', 'alert-outline', self::ADMIN, 'red'),
                    self::card('certifications', 'lms/admin/certifications', 'certificate-outline', self::ADMIN, 'indigo'),
                    self::card('cert_programs', 'lms/admin/certifications/programs', 'certificate', self::ADMIN, 'teal'),
                    self::card('cert_templates', 'lms/admin/cert-templates', 'file-certificate-outline', self::ADMIN, 'orange'),
                    self::card('moderation', 'lms/admin/moderation', 'gavel', self::ADMIN, 'deep-purple'),
                ],
            ],
            'seta' => [
                'name' => 'SETA / QCTO', 'path' => 'lms/seta-hub', 'icon' => 'certificate', 'tier' => self::ADMIN,
                'title' => 'learning_management.hubs.seta.title', 'subtitle' => 'learning_management.hubs.seta.subtitle',
                'cards' => [
                    self::card('qcto', 'lms/admin/qcto', 'domain', self::ADMIN, 'blue'),
                    self::card('registered_persons', 'lms/admin/registered-persons', 'account-check-outline', self::ADMIN, 'indigo'),
                    self::card('sdfs', 'lms/admin/sdfs', 'account-tie-outline', self::ADMIN, 'teal'),
                    self::card('learnerships', 'lms/admin/learnerships', 'school-outline', self::ADMIN, 'green'),
                    self::card('wsp_atr', 'lms/admin/wsp-atr', 'file-document-outline', self::ADMIN, 'orange'),
                    self::card('bbee', 'lms/admin/bbee', 'scale-balance', self::ADMIN, 'deep-purple'),
                    self::card('nlrd', 'lms/admin/nlrd', 'database-arrow-up-outline', self::ADMIN, 'cyan'),
                    self::card('poe_admin', 'lms/admin/poe-admin', 'folder-check-outline', self::ADMIN, 'blue-grey'),
                    self::card('aqp', 'lms/admin/aqp', 'clipboard-check-outline', self::ADMIN, 'amber'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'lms/insights-hub', 'icon' => 'chart-line', 'tier' => self::ADMIN,
                'title' => 'learning_management.hubs.insights.title', 'subtitle' => 'learning_management.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'lms/admin/reports', 'file-chart-outline', self::ADMIN, 'blue'),
                    self::card('analytics', 'lms/admin/analytics', 'chart-line', self::ADMIN, 'green'),
                    self::card('effectiveness', 'lms/admin/effectiveness', 'target', self::ADMIN, 'teal'),
                    self::card('training_costs', 'lms/admin/training-costs', 'cash-multiple', self::ADMIN, 'orange'),
                    self::card('ai_chat', 'lms/admin/ai-chat', 'chat-outline', self::ADMIN, 'deep-purple'),
                    self::card('ai_chat_manager', 'lms/admin/ai-chat/manager', 'chat-processing-outline', self::ADMIN, 'indigo'),
                    self::card('ai_chat_hr', 'lms/admin/ai-chat/hr', 'chat-alert-outline', self::ADMIN, 'red'),
                    self::card('scheduled_reports', 'lms/admin/scheduled-reports', 'calendar-clock-outline', self::ADMIN, 'blue-grey'),
                ],
            ],
        ];
    }
}
