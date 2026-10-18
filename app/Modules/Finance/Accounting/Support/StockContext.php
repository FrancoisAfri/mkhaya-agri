<?php

namespace App\Modules\Finance\Accounting\Support;

/**
 * Everything about a stock movement beyond item, quantity, warehouse and value: which bin, which
 * lot, which status, what authorised it, and whether it undoes another movement.
 *
 * Optional on every InventoryService method, so the callers that existed before it -- invoice
 * relief, the stock screens -- keep working unchanged and their movements land in the plain
 * available, un-binned, un-lotted position.
 */
final class StockContext
{
    public function __construct(
        public readonly ?int $binId = null,
        public readonly ?int $lotId = null,
        public readonly string $status = 'AVAILABLE',
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?int $toBinId = null,
        public readonly ?string $toStatus = null,
        public readonly ?int $reversalOfId = null,
        /** When true, the position may not go below zero. Legacy callers tolerate overselling. */
        public readonly bool $strict = true,
        /** The lot stock moves into when a split or merge re-lots it. */
        public readonly ?int $toLotId = null,
        /**
         * What the movement was transacted as and why (WMS-011, WMS-020): txn_uom_id, txn_qty,
         * reason_code, occurred_at, approved_by, approval_reference, handling_unit. Written onto
         * the movement as given; the ledger never derives a balance from it.
         */
        public readonly array $meta = [],
    ) {}

    /** The movement columns this context fills beyond the ledger's own. */
    public function movementMeta(): array
    {
        return array_intersect_key($this->meta, array_flip(self::META_COLUMNS));
    }

    public const META_COLUMNS = ['txn_uom_id', 'txn_qty', 'reason_code', 'occurred_at', 'approved_by', 'approval_reference', 'handling_unit'];

    /** The context as it applies to the receiving end of a transfer or status change. */
    public function destination(): self
    {
        return new self(
            binId: $this->toBinId ?? $this->binId,
            lotId: $this->toLotId ?? $this->lotId,
            status: $this->toStatus ?? $this->status,
            sourceType: $this->sourceType,
            sourceId: $this->sourceId,
            idempotencyKey: $this->idempotencyKey,
            strict: $this->strict,
            meta: $this->meta,
        );
    }
}
