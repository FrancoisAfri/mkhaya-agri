<?php

namespace App\Modules\Procurement\Governance\Support;

use App\Support\Hubs\ModuleHubs;

/**
 * Procurement Governance navigation (Supply Chain Management).
 *
 * Six hubs, matching §12's tree: Planning, Tenders, Deviations, Governance, Insights, Configure.
 * Every card points at a page that exists — a card linking to an unbuilt page is a 404 with a
 * promise attached, and the hub test suite fails the build for exactly that reason.
 *
 * Hub tiers are the *visual* gate; `module.tier:procurement_governance` enforces the same tiers per
 * route so the separation is real. The evaluator's own workspace sits at READ deliberately: a
 * committee member who is not otherwise a procurement user must be able to reach the tender they
 * were appointed to score, and gating that behind a WRITE seat would mean buying a seat per
 * evaluator to run one committee.
 */
class ProcurementGovernanceHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'procurement_governance';
    }

    public static function direct(): array
    {
        return [
            [
                'key' => 'dashboard',
                'name' => 'Dashboard',
                'path' => 'procurement-governance',
                'icon' => 'gavel',
                'tier' => self::READ,
            ],
        ];
    }

    public static function hubs(): array
    {
        return static::consolidate([
            'planning' => [
                'name' => 'Planning', 'path' => 'procurement-governance/planning-hub',
                'icon' => 'calendar-check', 'tier' => self::READ,
                'title' => 'procurement_governance.hubs.planning.title',
                'subtitle' => 'procurement_governance.hubs.planning.subtitle',
                'cards' => [
                    self::card('plan_dashboard', 'procurement-governance/planning/dashboard', 'view-dashboard-outline', self::READ, 'indigo'),
                    self::card('plans', 'procurement-governance/plans', 'clipboard-list-outline', self::READ, 'blue'),
                    self::card('new_plan', 'procurement-governance/plans/create', 'plus-circle-outline', self::WRITE, 'green'),
                ],
            ],

            'tenders' => [
                'name' => 'Tenders', 'path' => 'procurement-governance/tenders-hub',
                'icon' => 'scale-balance', 'tier' => self::READ,
                'title' => 'procurement_governance.hubs.tenders.title',
                'subtitle' => 'procurement_governance.hubs.tenders.subtitle',
                'cards' => [
                    self::card('tender_reviews', 'procurement-governance/tenders', 'file-document-multiple-outline', self::READ, 'teal'),
                    self::card('new_review', 'procurement-governance/tenders/create', 'plus-circle-outline', self::WRITE, 'green'),
                    // READ: see the class docblock — an appointed evaluator must be able to score.
                    self::card('my_evaluations', 'procurement-governance/evaluations', 'clipboard-check-outline', self::READ, 'amber'),
                    self::card('committees', 'procurement-governance/committees', 'account-group-outline', self::READ, 'deep-purple'),
                ],
            ],

            'deviations' => [
                'name' => 'Deviations', 'path' => 'procurement-governance/deviations-hub',
                'icon' => 'alert-decagram-outline', 'tier' => self::READ,
                'title' => 'procurement_governance.hubs.deviations.title',
                'subtitle' => 'procurement_governance.hubs.deviations.subtitle',
                'cards' => [
                    self::card('deviations', 'procurement-governance/deviations', 'alert-decagram-outline', self::READ, 'orange'),
                    self::card('new_deviation', 'procurement-governance/deviations/create', 'plus-circle-outline', self::WRITE, 'green'),
                ],
            ],

            'governance' => [
                'name' => 'Governance', 'path' => 'procurement-governance/governance-hub',
                'icon' => 'shield-search', 'tier' => self::READ,
                'title' => 'procurement_governance.hubs.governance.title',
                'subtitle' => 'procurement_governance.hubs.governance.subtitle',
                'cards' => [
                    self::card('records', 'procurement-governance/records', 'file-tree-outline', self::READ, 'blue-grey'),
                    self::card('approvals', 'procurement-governance/approvals', 'check-all', self::READ, 'cyan'),
                    self::card('documents', 'procurement-governance/documents', 'folder-open-outline', self::READ, 'brown'),
                    self::card('compliance', 'procurement-governance/compliance', 'traffic-light', self::READ, 'red'),
                ],
            ],

            'insights' => [
                'name' => 'Insights', 'path' => 'procurement-governance/insights-hub',
                'icon' => 'chart-box-outline', 'tier' => self::MODIFY,
                'title' => 'procurement_governance.hubs.insights.title',
                'subtitle' => 'procurement_governance.hubs.insights.subtitle',
                'cards' => [
                    // Listed first: it is the page that answers "how are we doing", which is what
                    // most people arrive at this hub wanting. Reports answer "prove it".
                    self::card('analytics', 'procurement-governance/analytics', 'chart-timeline-variant', self::MODIFY, 'teal'),
                    self::card('reports', 'procurement-governance/reports', 'chart-bar', self::MODIFY, 'indigo'),
                    self::card('workload', 'procurement-governance/workload', 'account-clock-outline', self::MODIFY, 'purple'),
                ],
            ],

            'configure' => [
                'name' => 'Configure', 'path' => 'procurement-governance/configure-hub',
                'icon' => 'cog-outline', 'tier' => self::ADMIN,
                'title' => 'procurement_governance.hubs.configure.title',
                'subtitle' => 'procurement_governance.hubs.configure.subtitle',
                'cards' => [
                    self::card('settings', 'procurement-governance/settings', 'cog-outline', self::ADMIN, 'blue-grey'),
                ],
            ],
        ], [
            // Five sidebar ribbons at most: these hubs fold into their neighbours.
            'governance' => ['absorb' => ['deviations'], 'name' => 'Governance & Deviations'],
            'insights' => ['absorb' => ['configure'], 'name' => 'Insights & Settings'],
        ]);
    }

    /**
     * Subtree floors, so a route that is not an exact card still gets gated. Without the settings
     * guard, a sub-path under settings would fall back to READ and let a front-line user reach the
     * rules engine by typing a URL — which is the whole compliance framework.
     */
    public static function guards(): array
    {
        return [
            'procurement-governance/settings' => self::ADMIN,
            'procurement-governance/reports' => self::MODIFY,
            'procurement-governance/workload' => self::MODIFY,
        ];
    }
}
