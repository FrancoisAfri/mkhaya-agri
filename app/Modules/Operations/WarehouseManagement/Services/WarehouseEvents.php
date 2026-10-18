<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The warehouse's events (WMS-172): a registry of what is published, at which schema version and
 * with which fields, and the outbox they are written to.
 *
 * An event is written in the same transaction as the work it describes -- if the work rolls back,
 * so does the event -- and is never rewritten afterwards (a database trigger refuses it). Only
 * warehouses the module runs publish: a company using Finance stock alone hears nothing new.
 *
 * A field is added to a version freely; removing or renaming one is a new version.
 */
class WarehouseEvents
{
    public const TYPES = [
        'warehouse.receipt.posted' => [1, 'Stock received into a warehouse: a purchase receipt, production output or a customer return.',
            ['movement', 'movement_type', 'item', 'warehouse', 'qty', 'lot', 'status', 'reference', 'occurred_at']],
        'warehouse.stock.moved' => [1, 'Any stock movement in the ledger: issue, transfer, adjustment, status change, relot, dispatch.',
            ['movement', 'movement_type', 'item', 'warehouse', 'qty', 'lot', 'status', 'to_status', 'reason_code', 'reference', 'occurred_at']],
        'warehouse.reservation.changed' => [1, 'Stock reserved for demand, consumed, or released.',
            ['reservation', 'item', 'warehouse', 'qty', 'qty_consumed', 'status', 'source_type']],
        'warehouse.shipment.dispatched' => [1, 'Packages left the warehouse.',
            ['shipment', 'order', 'external_ref', 'carrier', 'service', 'tracking_reference', 'packages', 'dispatched_at']],
        'warehouse.shipment.tracking' => [1, 'A carrier or a person recorded what happened to a shipment.',
            ['shipment', 'status', 'occurred_at', 'location', 'source']],
        'warehouse.shipment.delivered' => [1, 'Proof of delivery was recorded.',
            ['shipment', 'order', 'external_ref', 'received_by', 'delivered_at']],
        'warehouse.return.status_changed' => [1, 'A customer return moved on: authorised, received, inspected, completed or cancelled.',
            ['return', 'status', 'customer', 'reference']],
        'warehouse.exception.raised' => [1, 'An inventory exception: a loss risk, variance or control failure found in a warehouse.',
            ['finding', 'category', 'priority', 'warehouse', 'item', 'summary']],
    ];

    /** Movement types that bring stock in. */
    public const RECEIVING = ['receipt', 'mfg_receipt', 'mfg_return', 'cust_return'];

    public function record(int $companyId, ?int $warehouseId, string $type, string $subjectType, string $subjectRef, array $payload, ?\DateTimeInterface $occurredAt = null): ?string
    {
        if (! isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("Unknown warehouse event {$type}.");
        }
        if ($warehouseId !== null && ! $this->publishes($warehouseId)) {
            return null;
        }
        [$version, , $fields] = self::TYPES[$type];
        $missing = array_diff($fields, array_keys($payload));
        if ($missing !== []) {
            throw new \InvalidArgumentException("{$type} v{$version} is missing ".implode(', ', $missing).'.');
        }
        $id = (string) Str::uuid();
        DB::table('wms_outbox')->insert(['event_id' => $id, 'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'event_type' => $type,
            'schema_version' => $version, 'subject_type' => $subjectType, 'subject_ref' => $subjectRef,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'occurred_at' => $occurredAt ?? now(), 'available_at' => now(), 'created_at' => now()]);

        return $id;
    }

    public function publishes(int $warehouseId): bool
    {
        return (bool) DB::table('fin_warehouses')->where('id', $warehouseId)->value('is_wms_managed');
    }

    /** The registry as an API consumer reads it. */
    public function catalogue(): array
    {
        return collect(self::TYPES)->map(fn ($t, $type) => ['type' => $type, 'schema_version' => $t[0], 'description' => $t[1], 'fields' => $t[2]])->values()->all();
    }

    /** One outbox row as it is delivered and listed. */
    public static function envelope(object $row): array
    {
        return ['sequence' => (int) $row->id, 'event_id' => $row->event_id, 'type' => $row->event_type, 'schema_version' => (int) $row->schema_version,
            'occurred_at' => \Illuminate\Support\Carbon::parse($row->occurred_at)->toIso8601String(),
            'warehouse' => $row->warehouse_id ? DB::table('fin_warehouses')->where('id', $row->warehouse_id)->value('code') : null,
            'subject' => ['type' => $row->subject_type, 'ref' => $row->subject_ref], 'data' => is_string($row->payload) ? json_decode($row->payload, true) : $row->payload];
    }
}
