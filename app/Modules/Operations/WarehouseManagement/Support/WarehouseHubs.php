<?php

namespace App\Modules\Operations\WarehouseManagement\Support;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Support\Hubs\ModuleHubs;

/**
 * Navigation for Warehouse Management & Intelligence: a dashboard plus hub landings.
 *
 * Cards are only declared for pages that exist. A card for a page still to be built is a link to
 * a 404, and the hub test fails on it -- which is the point.
 */
class WarehouseHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'warehouse_management';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Warehouse Dashboard', 'path' => 'warehouse',
                'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            /*
             * What the figures say: stock, suppliers, losses and money, and the reports behind them.
             */
            'insights' => [
                'name' => 'Insights', 'path' => 'warehouse/insights',
                'icon' => 'chart-bar', 'tier' => self::READ,
                'title' => 'warehouse_management.hubs.insights.title',
                'subtitle' => 'warehouse_management.hubs.insights.subtitle',
                'cards' => [
                    self::card('inventory_dashboard', 'warehouse/insights/inventory', 'stack-2', self::READ, 'primary', self::viewItems()),
                    self::card('supplier_dashboard', 'warehouse/insights/suppliers', 'truck-delivery', self::READ, 'teal', self::viewItems()),
                    self::card('protection_dashboard', 'warehouse/insights/protection', 'shield-check', self::READ, 'error', self::investigate()),
                    self::card('executive_dashboard', 'warehouse/insights/executive', 'chart-line', self::READ, 'indigo', self::accounting()),
                    self::card('kpis', 'warehouse/kpis', 'target', self::READ, 'success', ['ability' => RoleCatalog::SUPERVISE, 'subject' => \App\Modules\Finance\Accounting\Models\FinStockItem::class]),
                    self::card('reports', 'warehouse/reports', 'file-export', self::READ, 'secondary', self::viewItems()),
                    self::card('approvals', 'warehouse/approvals', 'circle-check', self::READ, 'primary', self::viewItems()),
                    self::card('alerts', 'warehouse/alerts', 'bell', self::READ, 'warning', self::viewItems()),
                    self::card('health', 'warehouse/health', 'heartbeat', self::READ, 'success', ['ability' => RoleCatalog::SUPERVISE, 'subject' => \App\Modules\Finance\Accounting\Models\FinStockItem::class]),
                    self::card('governance', 'warehouse/governance', 'shield-check', self::READ, 'secondary', ['ability' => RoleCatalog::AUDIT, 'subject' => \App\Modules\Finance\Accounting\Models\FinStockItem::class]),
                ],
            ],

            /*
             * Goods going out: demand from every source, allocated, picked, packed and shipped,
             * against the service levels customers were promised.
             */
            'outbound' => [
                'name' => 'Outbound', 'path' => 'warehouse/outbound',
                'icon' => 'truck-loading', 'tier' => self::READ,
                'title' => 'warehouse_management.hubs.outbound.title',
                'subtitle' => 'warehouse_management.hubs.outbound.subtitle',
                'cards' => [
                    self::card('fulfilment_orders', 'warehouse/outbound/orders', 'clipboard-list', self::READ, 'primary', self::viewItems()),
                    self::card('picking', 'warehouse/outbound/picking', 'shopping-cart', self::READ, 'teal', self::viewItems()),
                    self::card('service_levels', 'warehouse/outbound/service-levels', 'clock-hour-4', self::READ, 'warning', self::viewItems()),
                ],
            ],

            /*
             * Work on the floor: put-away now, and the moves, replenishment, picking and packing
             * the later parts add. Each card is a queue somebody works through with a scanner.
             */
            'floor' => [
                'name' => 'Floor Work', 'path' => 'warehouse/floor',
                'icon' => 'forklift', 'tier' => self::READ,
                'title' => 'warehouse_management.hubs.floor.title',
                'subtitle' => 'warehouse_management.hubs.floor.subtitle',
                'cards' => [
                    self::card('putaway', 'warehouse/floor/putaway', 'arrow-bar-to-down', self::READ, 'primary', self::viewItems()),
                    self::card('capacity', 'warehouse/floor/capacity', 'gauge', self::READ, 'teal', self::viewItems()),
                    self::card('bin_move', 'warehouse/floor/move', 'arrows-move', self::READ, 'indigo', self::viewItems()),
                    self::card('transfers', 'warehouse/floor/transfers', 'truck', self::READ, 'warning', self::viewItems()),
                    self::card('replenishment', 'warehouse/floor/replenishment', 'refresh', self::READ, 'blue', self::viewItems()),
                    self::card('floor_screen', 'warehouse/m', 'mobile', self::READ, 'success', self::viewItems()),
                    self::card('task_board', 'warehouse/work/board', 'list-check', self::READ, 'primary', self::viewItems()),
                    self::card('labour', 'warehouse/work/labour', 'users', self::READ, 'teal', self::viewItems()),
                    self::card('slotting', 'warehouse/work/slotting', 'th', self::READ, 'indigo', self::viewItems()),
                    self::card('performance', 'warehouse/work/performance', 'chart-bar', self::READ, 'success', self::viewItems()),
                    self::card('simulations', 'warehouse/work/simulations', 'flask', self::READ, 'warning', self::viewItems()),
                ],
            ],

            /*
             * Goods coming in: what is expected, the dock diary, receiving against orders (and
             * without them), what did not match, what goes back, and how suppliers perform.
             */
            'inbound' => [
                'name' => 'Inbound', 'path' => 'warehouse/inbound',
                'icon' => 'truck-delivery', 'tier' => self::READ,
                'title' => 'warehouse_management.hubs.inbound.title',
                'subtitle' => 'warehouse_management.hubs.inbound.subtitle',
                'cards' => [
                    self::card('expected', 'warehouse/inbound/expected', 'calendar-due', self::READ, 'primary', self::viewItems()),
                    self::card('receipts', 'warehouse/inbound/receipts', 'clipboard-check', self::READ, 'teal', self::viewItems()),
                    self::card('receive_without_order', 'warehouse/inbound/without-order', 'package-import', self::READ, 'warning', self::viewItems()),
                    self::card('appointments', 'warehouse/inbound/appointments', 'calendar-time', self::READ, 'indigo', self::viewItems()),
                    self::card('supplier_returns', 'warehouse/inbound/returns', 'truck-return', self::READ, 'error', self::viewItems()),
                    self::card('customer_returns', 'warehouse/returns', 'undo', self::READ, 'warning', self::viewItems()),
                    self::card('inspections', 'warehouse/quality/inspections', 'flask', self::READ, 'teal', self::viewItems()),
                    self::card('scorecards', 'warehouse/inbound/suppliers', 'chart-bar', self::READ, 'blue', self::viewItems()),
                ],
            ],

            /*
             * Stock as the ledger holds it: what is where, in which state, what can be promised,
             * how every figure got there, and the corrections that changed it.
             */
            'inventory' => [
                'name' => 'Inventory', 'path' => 'warehouse/inventory',
                'icon' => 'stack-2', 'tier' => self::READ,
                'title' => 'warehouse_management.hubs.inventory.title',
                'subtitle' => 'warehouse_management.hubs.inventory.subtitle',
                'cards' => [
                    self::card('stock', 'warehouse/stock/overview', 'stack-2', self::READ, 'primary', self::viewItems()),
                    self::card('movements', 'warehouse/stock/movements', 'arrows-exchange', self::READ, 'teal', self::viewItems()),
                    self::card('adjustments', 'warehouse/stock/adjustments', 'adjustments', self::READ, 'warning', self::viewItems()),
                    self::card('stock_states', 'warehouse/stock/states', 'list-details', self::READ, 'blue', self::viewItems()),
                    self::card('reason_codes', 'warehouse/stock/reason-codes', 'tags', self::READ, 'indigo', self::viewItems()),
                    self::card('clients', 'warehouse/stock/clients', 'users-group', self::READ, 'secondary', self::viewItems()),
                    self::card('counts', 'warehouse/counts', 'clipboard-check', self::READ, 'success', self::viewItems()),
                    self::card('count_accuracy', 'warehouse/counts/accuracy', 'target', self::READ, 'info', self::viewItems()),
                    self::card('losses', 'warehouse/losses', 'exclamation-triangle', self::READ, 'error', self::viewItems()),
                    self::card('batches', 'warehouse/batches', 'list-check', self::READ, 'indigo', self::viewItems()),
                    self::card('expiry', 'warehouse/batches/expiry', 'calendar-due', self::READ, 'warning', self::viewItems()),
                    self::card('recalls', 'warehouse/recalls', 'shield-alt', self::READ, 'error', self::viewItems()),
                    self::card('quality_setup', 'warehouse/quality/setup', 'sliders', self::READ, 'secondary', self::viewItems()),
                    self::card('accounting', 'warehouse/accounting', 'calculator', self::READ, 'primary', self::accounting()),
                    self::card('stock_costs', 'warehouse/accounting/costs', 'chart-bar', self::READ, 'teal', self::accounting()),
                    self::card('protection', 'warehouse/protection', 'shield-check', self::READ, 'error', self::investigate()),
                    self::card('assistant', 'warehouse/assistant', 'robot', self::READ, 'primary', self::viewItems()),
                    self::card('forecast', 'warehouse/planning/forecast', 'chart-line', self::READ, 'indigo', self::viewItems()),
                    self::card('buying', 'warehouse/planning/buying', 'shopping-cart', self::READ, 'primary', self::viewItems()),
                    self::card('excess', 'warehouse/planning/excess', 'archive', self::READ, 'warning', self::viewItems()),
                    self::card('production', 'warehouse/production', 'tools', self::READ, 'secondary', self::viewItems()),
                ],
            ],

            /*
             * What is stored: the product master as the warehouse needs it -- units, packaging,
             * barcodes, the codes suppliers use, and whether an item may move at all.
             */
            'products' => [
                'name' => 'Products', 'path' => 'warehouse/products',
                'icon' => 'package', 'tier' => self::READ,
                'title' => 'warehouse_management.hubs.products.title',
                'subtitle' => 'warehouse_management.hubs.products.subtitle',
                'cards' => [
                    self::card('items', 'warehouse/products/items', 'package',
                        self::READ, 'primary', self::viewItems()),
                    self::card('scan', 'warehouse/products/scan', 'scan',
                        self::READ, 'teal', self::viewItems()),
                    self::card('barcode_duplicates', 'warehouse/products/items?duplicates=1', 'copy',
                        self::READ, 'warning', self::viewItems()),
                ],
            ],

            /*
             * The physical warehouse: the buildings and the floor inside them. READ tier, because
             * a picker needs to read the location explorer; what anybody may change is the policy's
             * question per request.
             */
            'layout' => [
                'name' => 'Warehouse Layout', 'path' => 'warehouse/layout',
                'icon' => 'building-warehouse', 'tier' => self::READ,
                'title' => 'warehouse_management.hubs.layout.title',
                'subtitle' => 'warehouse_management.hubs.layout.subtitle',
                'cards' => [
                    self::card('warehouses', 'warehouse/layout/warehouses', 'building-warehouse',
                        self::READ, 'primary', self::view()),
                    self::card('locations', 'warehouse/layout/locations', 'layout-grid',
                        self::READ, 'teal', self::view()),
                    self::card('labels', 'warehouse/layout/labels', 'qrcode',
                        self::READ, 'indigo', self::view()),
                    self::card('location_history', 'warehouse/layout/history', 'history',
                        self::READ, 'blue', self::view()),
                    self::card('label_setup', 'warehouse/labels/setup', 'print',
                        self::READ, 'secondary', self::viewItems()),
                    self::card('carriers', 'warehouse/integrations/carriers', 'truck', self::READ, 'primary', self::viewItems()),
                    self::card('events', 'warehouse/integrations/events', 'bell', self::READ, 'teal', self::viewItems()),
                    self::card('systems', 'warehouse/integrations/systems', 'plug', self::READ, 'indigo', self::viewItems()),
                    self::card('standards', 'warehouse/integrations/standards', 'globe', self::READ, 'blue', self::viewItems()),
                ],
            ],
        ];
    }

    /** The accounting screens ask for the accounting grant, not merely sight of the stock. */
    private static function accounting(): array
    {
        return ['ability' => RoleCatalog::ACCOUNTING, 'subject' => \App\Modules\Finance\Accounting\Models\FinStockItem::class];
    }

    /** Investigations are seen only by investigators (WMS-113). */
    private static function investigate(): array
    {
        return ['ability' => RoleCatalog::INVESTIGATE, 'subject' => \App\Modules\Finance\Accounting\Models\FinStockItem::class];
    }

    private static function viewItems(): array
    {
        return ['ability' => 'viewAny', 'subject' => \App\Modules\Finance\Accounting\Models\FinStockItem::class];
    }

    private static function view(): array
    {
        return ['ability' => 'viewAny', 'subject' => FinWarehouse::class];
    }
}
