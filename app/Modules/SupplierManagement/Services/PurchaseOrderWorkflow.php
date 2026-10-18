<?php

namespace App\Modules\SupplierManagement\Services;

/**
 * Which way a purchase order is allowed to move.
 *
 * Status was a free write: any holder of `suppliers.update` could set any value in any order, so a
 * cancelled order went back to approved in one request and a closed one reopened. The sequence is
 * the whole meaning of the field — an order that can move backwards is not a record of anything.
 *
 * Cancellation is reachable from every live state, because an order genuinely can be called off at
 * any point before it is closed. Closed is terminal: the goods arrived and the file is shut.
 */
class PurchaseOrderWorkflow
{
    private const ALLOWED = [
        'draft'     => ['approved', 'cancelled'],
        'approved'  => ['received', 'cancelled'],
        'received'  => ['closed', 'cancelled'],
        'closed'    => [],
        'cancelled' => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        // Saving a form without touching the status must not be read as an illegal move.
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    /** The statuses a given order may move to, so the screen can offer only those. */
    public function nextStatuses(string $from): array
    {
        return self::ALLOWED[$from] ?? [];
    }
}
