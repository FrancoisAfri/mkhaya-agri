<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Purchase Requests hubs: Requests (raise and approve), Orders (POs and invoices) and
 * Intelligence (spend analytics and policy rules). Routes are spread across top-level
 * paths (purchase-requests, requisitions, invoices) and procurement/*.
 */
class ProcurementRequestHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'procurement_requests';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'procurement/dashboard', 'icon' => 'tachometer-alt', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'requests' => [
                'name' => 'Requests', 'path' => 'procurement/requests-hub', 'icon' => 'list', 'tier' => self::READ,
                'title' => 'procurement_requests.hubs.requests.title', 'subtitle' => 'procurement_requests.hubs.requests.subtitle',
                'cards' => [
                    self::card('requests', 'purchase-requests', 'format-list-bulleted', self::READ, 'indigo'),
                    self::card('new_request', 'purchase-requests/create', 'plus-circle-outline', self::READ, 'green'),
                    self::card('requisitions', 'requisitions', 'clipboard-list-outline', self::READ, 'blue'),
                    self::card('approvals', 'procurement/approvals', 'check-all', self::READ, 'orange'),
                ],
            ],
            'orders' => [
                'name' => 'Orders', 'path' => 'procurement/orders-hub', 'icon' => 'file-invoice', 'tier' => self::READ,
                'title' => 'procurement_requests.hubs.orders.title', 'subtitle' => 'procurement_requests.hubs.orders.subtitle',
                'cards' => [
                    self::card('purchase_orders', 'purchase-orders', 'file-document-outline', self::READ, 'teal'),
                    self::card('invoices', 'invoices', 'receipt', self::READ, 'cyan'),
                ],
            ],
            'intelligence' => [
                'name' => 'Intelligence', 'path' => 'procurement/intelligence-hub', 'icon' => 'brain', 'tier' => self::READ,
                'title' => 'procurement_requests.hubs.intelligence.title', 'subtitle' => 'procurement_requests.hubs.intelligence.subtitle',
                'cards' => [
                    self::card('spend_intelligence', 'procurement/intelligence', 'brain', self::READ, 'deep-purple'),
                    self::card('policy_rules', 'procurement/policies', 'gavel', self::READ, 'blue-grey'),
                ],
            ],
        ];
    }
}
