<?php

namespace App\Modules\Operations\Manufacturing\Support;

use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Support\Hubs\ModuleHubs;

/**
 * Navigation for Manufacturing & Production: a dashboard plus hub landings of role-filtered cards.
 *
 * What earns a sidebar entry is whether the page means anything without a factory or a product in
 * the URL. A work centre's capacity, a product's BOM and a line's shutdowns are all per-parent, so
 * they are tabs or cards reached from the thing they belong to rather than top-level ribbons.
 *
 * Every card names the ability its own route authorizes. Module tier and the manufacturing
 * permission set are independent — tier says which cards a user is shown, the permission says
 * whether the page opens — so a card without a gate is a card that can 403 the person it was just
 * offered to.
 */
class ManufacturingHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'manufacturing';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Manufacturing Dashboard', 'path' => 'manufacturing',
                'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return static::consolidate([
            /*
             * Agricultural production — the production engine: cycles (flocks, crop seasons, ponds,
             * batches) with their records and metrics, the units they run in, the production types
             * they are configured by, and processing of farm produce. The farm's own registers stay
             * the farm's; these pages work through them.
             */
            'agriculture' => [
                'name' => 'Agricultural Production', 'path' => 'manufacturing/agriculture',
                'icon' => 'sprout', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.agriculture.title',
                'subtitle' => 'manufacturing.hubs.agriculture.subtitle',
                'cards' => [
                    self::card('agri_overview', 'manufacturing/agriculture/overview', 'chart-donut',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::class)),
                    self::card('agri_cycles', 'manufacturing/agriculture/cycles', 'refresh',
                        self::READ, 'green', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::class)),
                    self::card('agri_units', 'manufacturing/agriculture/units', 'home-modern',
                        self::READ, 'brown', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::class)),
                    // The farm's crops and herds: its registers, kept once, opened here. Gated on the farm
                    // permission their own pages check (the farm module's, not a policy on a model).
                    self::card('agri_crops', 'manufacturing/agriculture/crops', 'plant-2',
                        self::READ, 'light-green', ['ability' => \App\Modules\Agri\Support\RoleCatalog::VIEW_CROPS, 'subject' => null]),
                    self::card('agri_seasons', 'manufacturing/agriculture/seasons', 'route',
                        self::READ, 'teal', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::class)),
                    self::card('agri_crop_intelligence', 'manufacturing/agriculture/crops/intelligence', 'radar',
                        self::READ, 'deep-purple', ['ability' => \App\Modules\Agri\Support\RoleCatalog::VIEW_CROPS, 'subject' => null]),
                    self::card('agri_livestock', 'manufacturing/agriculture/livestock', 'cow',
                        self::READ, 'brown', ['ability' => \App\Modules\Agri\Support\RoleCatalog::VIEW_LIVESTOCK, 'subject' => null]),
                    // Grading, packing and processing farm produce: production runs with lot genealogy, recipes and QA holds.
                    self::card('agri_packhouse', 'manufacturing/agriculture/packhouse', 'package',
                        self::READ, 'amber', ['ability' => \App\Modules\Agri\Support\RoleCatalog::VIEW_STOCK, 'subject' => null]),
                    self::card('agri_types', 'manufacturing/agriculture/types', 'tune',
                        self::READ, 'grey', ['ability' => 'manage', 'subject' => \App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::class]),
                ],
            ],

            /*
             * Command & Control Centre — live values, alarms, incidents and their escalation, and
             * the commands sent to equipment, each through its states.
             */
            'command' => [
                'name' => 'Command Centre', 'path' => 'manufacturing/command',
                'icon' => 'radar', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.command.title',
                'subtitle' => 'manufacturing.hubs.command.subtitle',
                'cards' => [
                    self::card('command_centre', 'manufacturing/command/centre', 'monitor-dashboard',
                        self::READ, 'error', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Incident::class)),
                    self::card('command_incidents', 'manufacturing/command/incidents', 'alert-octagon',
                        self::READ, 'deep-orange', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Incident::class)),
                    self::card('command_devices', 'manufacturing/command/devices', 'access-point',
                        self::READ, 'indigo', ['ability' => 'configure', 'subject' => \App\Modules\Operations\Manufacturing\Models\Agriculture\Incident::class]),
                    self::card('command_settings', 'manufacturing/command/settings', 'bell-cog-outline',
                        self::READ, 'grey', ['ability' => 'configure', 'subject' => \App\Modules\Operations\Manufacturing\Models\Agriculture\Incident::class]),
                ],
            ],

            /*
             * Intelligence — the agents' recommendations, disease outbreaks near the farms, funding
             * and market opportunities, outside sources and forecast accuracy. Advice only: nothing
             * here acts without a person.
             */
            'intelligence' => [
                'name' => 'Intelligence', 'path' => 'manufacturing/intelligence',
                'icon' => 'brain', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.intelligence.title',
                'subtitle' => 'manufacturing.hubs.intelligence.subtitle',
                'cards' => [
                    self::card('intel_recommendations', 'manufacturing/intelligence/recommendations', 'lightbulb-on-outline',
                        self::READ, 'amber', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation::class)),
                    self::card('intel_outbreaks', 'manufacturing/intelligence/outbreaks', 'virus-outline',
                        self::READ, 'error', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation::class)),
                    self::card('intel_opportunities', 'manufacturing/intelligence/opportunities', 'hand-coin-outline',
                        self::READ, 'green', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation::class)),
                    self::card('intel_sources', 'manufacturing/intelligence/sources', 'rss',
                        self::READ, 'blue-grey', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation::class)),
                    // Questions about production, answered from the records; it never acts.
                    self::card('intel_assistant', 'manufacturing/intelligence/assistant', 'robot-outline',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation::class)),
                    self::card('intel_forecasts', 'manufacturing/intelligence/forecasts', 'chart-bell-curve',
                        self::READ, 'indigo', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation::class)),
                ],
            ],

            /*
             * Engineering — what the company makes and what it is made of. READ tier: a planner,
             * a buyer and a supervisor all need to read a BOM, and what any of them may change is
             * settled per request by the policy rather than by hiding the register.
             */
            'engineering' => [
                'name' => 'Engineering', 'path' => 'manufacturing/engineering',
                'icon' => 'blueprint', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.engineering.title',
                'subtitle' => 'manufacturing.hubs.engineering.subtitle',
                'cards' => [
                    self::card('products', 'manufacturing/engineering/products', 'package',
                        self::READ, 'primary', self::viewItems()),
                    self::card('boms', 'manufacturing/engineering/boms', 'sitemap',
                        self::READ, 'teal', self::viewBoms()),
                    // How a product is made: operations, work centres, times and gates (MFG-003).
                    self::card('routings', 'manufacturing/engineering/routings', 'route',
                        self::READ, 'indigo', self::viewRoutings()),
                    /*
                     * Where-used. Its own card rather than a tab on a product, because the
                     * question is asked from the other end: a buyer holding a discontinued
                     * material wants to know what it breaks, and does not yet know which product
                     * to open.
                     */
                    self::card('where_used', 'manufacturing/engineering/where-used', 'search',
                        self::READ, 'blue', self::viewBoms()),
                ],
            ],

            /*
             * Production — the orders that turn engineering into stock (MFG-006). READ tier: the
             * people who raise, approve, release, run and close an order are different people,
             * and which of those any of them may do is the policy's question per order.
             */
            'production' => [
                'name' => 'Production', 'path' => 'manufacturing/production',
                'icon' => 'building-factory', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.production.title',
                'subtitle' => 'manufacturing.hubs.production.subtitle',
                'cards' => [
                    self::card('production_orders', 'manufacturing/production/orders', 'clipboard-list',
                        self::READ, 'primary', self::viewOrders()),
                    // The workstation terminal (MFG-007): queues, start/pause/complete, scanning.
                    self::card('shop_floor', 'manufacturing/floor', 'tablet',
                        self::READ, 'indigo', ['ability' => 'useFloor', 'subject' => \App\Modules\Operations\Manufacturing\Models\ProductionOrder::class]),
                    self::card('downtime', 'manufacturing/floor/downtime', 'pause-circle-outline',
                        self::READ, 'orange', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\DowntimeEvent::class)),
                    self::card('escalations', 'manufacturing/floor/escalations', 'bell-ring',
                        self::READ, 'error', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\FloorEscalation::class)),
                ],
            ],

            /*
             * Planning — demand, MRP and the purchasing it recommends (MFG-004/005/015). Nothing on
             * these pages commits the company until a planner approves a recommendation.
             */
            'planning' => [
                'name' => 'Planning', 'path' => 'manufacturing/planning',
                'icon' => 'calendar-stats', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.planning.title',
                'subtitle' => 'manufacturing.hubs.planning.subtitle',
                'cards' => [
                    self::card('demand', 'manufacturing/planning/demand', 'chart-timeline-variant',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\PlanRun::class)),
                    self::card('mrp', 'manufacturing/planning/mrp', 'calculator-variant',
                        self::READ, 'indigo', ['ability' => 'mrp', 'subject' => \App\Modules\Operations\Manufacturing\Models\PlanRun::class]),
                    // The schedule against real capacity, as a Gantt chart with its exceptions (MFG-014).
                    self::card('schedule', 'manufacturing/planning/schedule', 'chart-gantt',
                        self::READ, 'deep-purple', ['ability' => 'schedule', 'subject' => \App\Modules\Operations\Manufacturing\Models\PlanRun::class]),
                    self::card('supplier_performance', 'manufacturing/planning/suppliers', 'truck-check',
                        self::READ, 'teal', ['ability' => 'suppliers', 'subject' => \App\Modules\Operations\Manufacturing\Models\PlanRun::class]),
                    self::card('planning_parameters', 'manufacturing/planning/parameters', 'tune',
                        self::READ, 'orange', ['ability' => 'parameters', 'subject' => \App\Modules\Operations\Manufacturing\Models\PlanRun::class]),
                ],
            ],

            /*
             * Quality — inspections, non-conformances and the plans behind them (MFG-009). READ
             * tier: inspectors, quality managers and production all read the queue; who may
             * record, decide or close is the policy's question.
             */
            'quality' => [
                'name' => 'Quality', 'path' => 'manufacturing/quality',
                'icon' => 'shield-check', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.quality.title',
                'subtitle' => 'manufacturing.hubs.quality.subtitle',
                'cards' => [
                    self::card('inspections', 'manufacturing/quality/inspections', 'clipboard-check',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Inspection::class)),
                    self::card('nonconformances', 'manufacturing/quality/nonconformances', 'alert-triangle',
                        self::READ, 'error', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Nonconformance::class)),
                    self::card('inspection_plans', 'manufacturing/quality/plans', 'list-check',
                        self::READ, 'teal', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\InspectionPlan::class)),
                    // Backward and forward lot genealogy, and the recalls opened from it (MFG-010).
                    self::card('traceability', 'manufacturing/quality/traceability', 'family-tree',
                        self::READ, 'indigo', ['ability' => 'trace', 'subject' => \App\Modules\Operations\Manufacturing\Models\Recall::class]),
                    self::card('recalls', 'manufacturing/quality/recalls', 'alert-octagon-outline',
                        self::READ, 'deep-orange', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Recall::class)),
                ],
            ],

            /*
             * Workforce — who is rostered, who is qualified, and what labour the plan needs against
             * what it has (MFG-016). Personal data, so every card needs the labour permission.
             */
            'workforce' => [
                'name' => 'Workforce', 'path' => 'manufacturing/workforce',
                'icon' => 'users', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.workforce.title',
                'subtitle' => 'manufacturing.hubs.workforce.subtitle',
                'cards' => [
                    self::card('roster', 'manufacturing/workforce/roster', 'calendar-account',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\RosterEntry::class)),
                    self::card('qualifications', 'manufacturing/workforce/qualifications', 'certificate',
                        self::READ, 'teal', ['ability' => 'qualifications', 'subject' => \App\Modules\Operations\Manufacturing\Models\RosterEntry::class]),
                    self::card('labour', 'manufacturing/workforce/labour', 'account-clock',
                        self::READ, 'orange', ['ability' => 'labour', 'subject' => \App\Modules\Operations\Manufacturing\Models\RosterEntry::class]),
                ],
            ],

            /*
             * Customer orders and subcontracting — production promised to customers, and work sent
             * out to suppliers (MFG-017). The sales order itself stays in Finance.
             */
            'fulfilment' => [
                'name' => 'Customer orders', 'path' => 'manufacturing/fulfilment',
                'icon' => 'truck-delivery', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.fulfilment.title',
                'subtitle' => 'manufacturing.hubs.fulfilment.subtitle',
                'cards' => [
                    self::card('customer_orders', 'manufacturing/fulfilment/customer-orders', 'cart-arrow-right',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\CustomerCommitment::class)),
                    self::card('subcontracting', 'manufacturing/fulfilment/subcontracting', 'truck-delivery',
                        self::READ, 'teal', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\SubcontractOrder::class)),
                ],
            ],

            /*
             * Integrations — other systems this company runs alongside: ERPs, scales, scanners,
             * printers, MES and machine gateways (MFG-022).
             */
            'integrations' => [
                'name' => 'Integrations', 'path' => 'manufacturing/integrations',
                'icon' => 'connection', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.integrations.title',
                'subtitle' => 'manufacturing.hubs.integrations.subtitle',
                'cards' => [
                    self::card('integration_connections', 'manufacturing/integrations/connections', 'connection',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Integration::class)),
                ],
            ],

            /*
             * Factories — stock moved between the company's factories, where one factory's
             * shortage can be met from another's surplus, and which warehouses and products each
             * factory has (MFG-024).
             */
            'factories' => [
                'name' => 'Factories', 'path' => 'manufacturing/factories',
                'icon' => 'factory', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.factories.title',
                'subtitle' => 'manufacturing.hubs.factories.subtitle',
                'cards' => [
                    self::card('factory_transfers', 'manufacturing/factories/transfers', 'truck-fast-outline',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\Transfer::class)),
                    self::card('factory_network', 'manufacturing/factories/network', 'graph-outline',
                        self::READ, 'teal', ['ability' => 'network', 'subject' => \App\Modules\Operations\Manufacturing\Models\Transfer::class]),
                ],
            ],

            /*
             * Accounting — how each manufacturing event is treated in the books, the cost
             * subledger with its source documents, and reconciliation to the ledger (MFG-021).
             */
            'accounting' => [
                'name' => 'Accounting', 'path' => 'manufacturing/accounting',
                'icon' => 'scale-balance', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.accounting.title',
                'subtitle' => 'manufacturing.hubs.accounting.subtitle',
                'cards' => [
                    self::card('accounting_rules', 'manufacturing/accounting/treatments', 'book-cog-outline',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\AccountingRule::class)),
                    self::card('accounting_reconciliation', 'manufacturing/accounting/reconciliation', 'scale-balance',
                        self::READ, 'error', ['ability' => 'reconcile', 'subject' => \App\Modules\Operations\Manufacturing\Models\AccountingRule::class]),
                    self::card('accounting_transactions', 'manufacturing/accounting/transactions', 'book-open-variant',
                        self::READ, 'teal', ['ability' => 'transactions', 'subject' => \App\Modules\Operations\Manufacturing\Models\AccountingRule::class]),
                ],
            ],

            /*
             * Assistant — questions answered from the company's own records, and what came of the
             * recommendations (MFG-019).
             */
            'assistant' => [
                'name' => 'Assistant', 'path' => 'manufacturing/assistant',
                'icon' => 'robot-outline', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.assistant.title',
                'subtitle' => 'manufacturing.hubs.assistant.subtitle',
                'cards' => [
                    self::card('assistant_ask', 'manufacturing/assistant/ask', 'robot-outline',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\AssistantQuery::class)),
                    self::card('assistant_recommendations', 'manufacturing/assistant/recommendations', 'lightbulb-on-outline',
                        self::READ, 'amber', ['ability' => 'recommendations', 'subject' => \App\Modules\Operations\Manufacturing\Models\AssistantQuery::class]),
                ],
            ],

            /*
             * Alerts — who is told about what, and the log of what was sent (MFG-020).
             */
            'notifications' => [
                'name' => 'Alerts', 'path' => 'manufacturing/notifications',
                'icon' => 'bell-cog-outline', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.notifications.title',
                'subtitle' => 'manufacturing.hubs.notifications.subtitle',
                'cards' => [
                    self::card('alert_rules', 'manufacturing/notifications/rules', 'bell-cog-outline',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\NotificationRule::class)),
                    self::card('alert_log', 'manufacturing/notifications/log', 'bell-ring-outline',
                        self::READ, 'orange', ['ability' => 'log', 'subject' => \App\Modules\Operations\Manufacturing\Models\NotificationRule::class]),
                ],
            ],

            /*
             * Reports — management dashboards, each shown only to the roles whose permissions
             * cover its figures (MFG-018).
             */
            'reports' => [
                'name' => 'Reports', 'path' => 'manufacturing/reports',
                'icon' => 'chart-box-outline', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.reports.title',
                'subtitle' => 'manufacturing.hubs.reports.subtitle',
                'cards' => [
                    self::card('dash_executive', 'manufacturing/reports/executive', 'view-dashboard-variant',
                        self::READ, 'primary', ['ability' => 'executive', 'subject' => \App\Modules\Operations\Manufacturing\Support\ManufacturingReport::class]),
                    self::card('dash_planning', 'manufacturing/reports/planning', 'calendar-clock',
                        self::READ, 'indigo', ['ability' => 'planning', 'subject' => \App\Modules\Operations\Manufacturing\Support\ManufacturingReport::class]),
                    self::card('dash_inventory', 'manufacturing/reports/inventory', 'warehouse',
                        self::READ, 'teal', ['ability' => 'inventory', 'subject' => \App\Modules\Operations\Manufacturing\Support\ManufacturingReport::class]),
                    self::card('dash_costs', 'manufacturing/reports/costs', 'cash-multiple',
                        self::READ, 'orange', ['ability' => 'costs', 'subject' => \App\Modules\Operations\Manufacturing\Support\ManufacturingReport::class]),
                    self::card('dash_quality', 'manufacturing/reports/quality', 'clipboard-pulse-outline',
                        self::READ, 'error', ['ability' => 'quality', 'subject' => \App\Modules\Operations\Manufacturing\Support\ManufacturingReport::class]),
                    // Who changed what, with before and after values (MFG-023).
                    self::card('audit_trail', 'manufacturing/reports/audit-trail', 'history',
                        self::READ, 'grey', ['ability' => 'audit', 'subject' => \App\Modules\Operations\Manufacturing\Support\ManufacturingReport::class]),
                ],
            ],

            /*
             * Loss prevention — what the detectors found, who is investigating it, and what was
             * verified and saved (MFG-012).
             */
            'loss' => [
                'name' => 'Loss prevention', 'path' => 'manufacturing/loss',
                'icon' => 'shield-search', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.loss.title',
                'subtitle' => 'manufacturing.hubs.loss.subtitle',
                'cards' => [
                    self::card('loss_findings', 'manufacturing/loss/findings', 'magnify-scan',
                        self::READ, 'error', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\LossFinding::class)),
                    self::card('loss_rules', 'manufacturing/loss/rules', 'tune-variant',
                        self::READ, 'grey', ['ability' => 'rules', 'subject' => \App\Modules\Operations\Manufacturing\Models\LossFinding::class]),
                ],
            ],

            /*
             * Maintenance — the machines, what keeps them running and how well they run (MFG-013).
             * Repairs themselves are asset work orders; these cards are the plant's view of them.
             */
            'maintenance' => [
                'name' => 'Maintenance', 'path' => 'manufacturing/maintenance',
                'icon' => 'tools', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.maintenance.title',
                'subtitle' => 'manufacturing.hubs.maintenance.subtitle',
                'cards' => [
                    self::card('machines', 'manufacturing/maintenance/machines', 'cog-outline',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\MachineProfile::class)),
                    self::card('preventive', 'manufacturing/maintenance/preventive', 'calendar-check',
                        self::READ, 'teal', ['ability' => 'preventive', 'subject' => \App\Modules\Operations\Manufacturing\Models\MachineProfile::class]),
                    self::card('oee', 'manufacturing/maintenance/oee', 'speedometer',
                        self::READ, 'indigo', ['ability' => 'oee', 'subject' => \App\Modules\Operations\Manufacturing\Models\MachineProfile::class]),
                ],
            ],

            /*
             * Costing — standard costs, variances and margins (MFG-011). READ tier with every card
             * gated on the costing permissions: cost and margin are seen by fewer people than the
             * orders that produce them.
             */
            'costing' => [
                'name' => 'Costing', 'path' => 'manufacturing/costing',
                'icon' => 'calculator', 'tier' => self::READ,
                'title' => 'manufacturing.hubs.costing.title',
                'subtitle' => 'manufacturing.hubs.costing.subtitle',
                'cards' => [
                    self::card('standard_costs', 'manufacturing/costing/standards', 'calculator',
                        self::READ, 'primary', self::viewSubject(\App\Modules\Operations\Manufacturing\Models\StandardCost::class)),
                    self::card('variances', 'manufacturing/costing/variances', 'chart-waterfall',
                        self::READ, 'orange', ['ability' => 'variances', 'subject' => \App\Modules\Operations\Manufacturing\Models\StandardCost::class]),
                    self::card('profitability', 'manufacturing/costing/profitability', 'cash-multiple',
                        self::READ, 'success', ['ability' => 'profitability', 'subject' => \App\Modules\Operations\Manufacturing\Models\StandardCost::class]),
                ],
            ],

            /*
             * Setup — the factory environment (MFG-001). ADMIN tier: these are the records every
             * plan and every cost is calculated from, and a wrong work-centre rate misprices
             * every order made after it.
             */
            'setup' => [
                'name' => 'Manufacturing Setup', 'path' => 'manufacturing/setup',
                'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'manufacturing.hubs.setup.title',
                'subtitle' => 'manufacturing.hubs.setup.subtitle',
                'cards' => [
                    self::card('factories', 'manufacturing/setup/factories', 'building-factory',
                        self::ADMIN, 'primary', self::configure()),
                    self::card('work_centres', 'manufacturing/setup/work-centres', 'tools',
                        self::ADMIN, 'indigo', self::configure()),
                    self::card('shifts', 'manufacturing/setup/shifts', 'clock-hour-4',
                        self::ADMIN, 'amber', self::configure()),
                    self::card('units', 'manufacturing/setup/units', 'ruler-measure',
                        self::ADMIN, 'cyan', self::configure()),
                    self::card('numbering', 'manufacturing/setup/numbering', 'hash',
                        self::ADMIN, 'deep-purple', self::configure()),
                    self::card('policies', 'manufacturing/setup/policies', 'shield-check',
                        self::ADMIN, 'teal', self::configure()),
                ],
            ],
        ], [
            // Five sidebar ribbons at most (the dashboard is one): these hubs fold into their neighbours.
            'agriculture' => ['absorb' => ['intelligence'], 'name' => 'Agriculture & Intelligence'],
            'production' => ['absorb' => ['planning', 'engineering', 'factories', 'workforce', 'maintenance', 'loss', 'fulfilment'], 'name' => 'Production, Planning & Maintenance'],
            'quality' => ['absorb' => ['reports', 'accounting', 'costing', 'assistant', 'notifications', 'integrations', 'setup'], 'name' => 'Quality, Reports & Setup'],
        ]);
    }

    /**
     * Defence in depth: any /setup/* URL requires ADMIN even if a later sub-route is added
     * without a card, so a new settings page can never fall back to READ.
     */
    public static function guards(): array
    {
        return [
            'manufacturing/setup' => self::ADMIN,
        ];
    }

    private static function viewItems(): array
    {
        return ['ability' => 'viewAny', 'subject' => \App\Modules\Operations\Manufacturing\Models\Item::class];
    }

    private static function viewBoms(): array
    {
        return ['ability' => 'viewAny', 'subject' => Bom::class];
    }

    private static function viewRoutings(): array
    {
        return ['ability' => 'viewAny', 'subject' => \App\Modules\Operations\Manufacturing\Models\Routing::class];
    }

    private static function viewOrders(): array
    {
        return ['ability' => 'viewAny', 'subject' => \App\Modules\Operations\Manufacturing\Models\ProductionOrder::class];
    }

    private static function viewSubject(string $class): array
    {
        return ['ability' => 'viewAny', 'subject' => $class];
    }

    private static function configure(): array
    {
        return ['ability' => 'configure', 'subject' => Factory::class];
    }
}
