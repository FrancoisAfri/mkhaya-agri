<?php

namespace App\Modules\Operations\WarehouseManagement\Support;

/**
 * The Warehouse Management & Intelligence permission set.
 *
 * Named constants, because a misspelt permission does not fail: `can('wms.locaton.block')` is
 * simply false, the button disappears, and it reads as a configuration problem on the customer's
 * side.
 *
 * Configuring a warehouse is separated from shaping its locations, and both from blocking one.
 * Blocking is a safety and quality decision taken on the floor -- a spill, a broken beam, a pest
 * finding -- by a supervisor who should not need the right to redesign the warehouse to take it.
 */
final class RoleCatalog
{
    public const VIEW = 'wms.view';

    public const MANAGE_WAREHOUSES = 'wms.warehouse.manage';

    public const MANAGE_LOCATIONS = 'wms.location.manage';

    /** Block, restrict and reopen a location. */
    public const BLOCK_LOCATIONS = 'wms.location.block';

    /** Create and edit items, their packaging, codes and barcodes. */
    public const MANAGE_ITEMS = 'wms.item.manage';

    /** Discontinue, block, recall, quarantine or retire an item -- it stops stock moving. */
    public const CHANGE_ITEM_STATUS = 'wms.item.status';

    /** Knowingly register a barcode that already belongs to another item. */
    public const APPROVE_DUPLICATE_BARCODES = 'wms.barcode.approve_duplicate';

    /** Raise a stock adjustment (posted at once when it is below every approval limit). */
    public const ADJUST_STOCK = 'wms.stock.adjust';

    /** Approve or reject somebody else's adjustment. Never one's own: the schema refuses it. */
    public const APPROVE_ADJUSTMENTS = 'wms.stock.approve';

    /** See every third-party client's stock without a per-client grant. */
    public const ALL_CLIENTS = 'wms.clients.all';

    /** Grant and revoke people's access to a client's stock, and set up reason codes. */
    public const MANAGE_CLIENT_ACCESS = 'wms.clients.manage';

    /** Receive deliveries, record what arrived, book dock appointments. */
    public const RECEIVE = 'wms.receive';

    /** Approve receipts without an order and settle discrepancies outside tolerance. */
    public const APPROVE_RECEIVING_EXCEPTIONS = 'wms.receive.exceptions';

    /** Raise, approve and dispatch returns to suppliers. */
    public const SUPPLIER_RETURNS = 'wms.supplier_return';

    /** Perform put-away and bin-to-bin moves. */
    public const MOVE_STOCK = 'wms.move';

    /** Put away somewhere other than a directed bin. */
    public const OVERRIDE_PUTAWAY = 'wms.putaway.override';

    /** Approve a warehouse-to-warehouse transfer, and recall one already on its way. */
    public const APPROVE_TRANSFERS = 'wms.transfer.approve';

    /** Set the min/max rules that keep pick faces and warehouses topped up. */
    public const MANAGE_REPLENISHMENT = 'wms.replenishment.manage';

    /** Take in, release, allocate and cancel fulfilment orders. */
    public const MANAGE_ORDERS = 'wms.order.manage';

    /** Pick, pack and dispatch -- the floor work of fulfilment, each its own grant. */
    public const PICK = 'wms.pick';

    public const PACK = 'wms.pack';

    public const DISPATCH = 'wms.dispatch';

    /** Enter counts; plan, review and close them. Closing is a separate grant from counting. */
    public const COUNT = 'wms.count';

    public const MANAGE_COUNTS = 'wms.count.manage';

    /** Customer returns: authorise and receive; crediting the customer is a separate, financial grant. */
    public const RETURNS = 'wms.returns';

    public const CREDIT_RETURNS = 'wms.returns.credit';

    /** Hold stock for inspection, record results and decide; plans and defect codes are set up separately. */
    public const QUALITY = 'wms.quality';

    public const MANAGE_QUALITY = 'wms.quality.manage';

    /** Open, run and close recalls. */
    public const RECALLS = 'wms.recall';

    /** Loss recoveries and the loss register's evidence. */
    public const LOSSES = 'wms.loss';

    /** Allocate against the rotation rule (an older lot, a chosen bin), with a reason. */
    public const OVERRIDE_ROTATION = 'wms.rotation.override';

    /** See valuation and reconciliation, draft landed costs and ask for revaluations; approving them is finance's grant. */
    public const ACCOUNTING = 'wms.accounting';

    public const APPROVE_ACCOUNTING = 'wms.accounting.approve';

    /** Work loss investigations -- the only grant that shows them (WMS-113) -- set the detectors, sign off losses and savings. */
    public const INVESTIGATE = 'wms.investigate';

    public const MANAGE_PROTECTION = 'wms.protection.manage';

    public const APPROVE_LOSS = 'wms.loss.approve';

    /** Run and override forecasts, set supplier terms, draft purchase requests from recommendations. */
    public const PLAN = 'wms.plan';

    /** Assign work, set labour standards, propose and apply slotting, run simulations; approving a slotting change is separate. */
    public const SUPERVISE = 'wms.supervise';

    public const APPROVE_SLOTTING = 'wms.slotting.approve';

    /** @return list<string> */
    /** Carriers, webhooks, API events and connections to other systems (WMS-170..173). */
    public const INTEGRATIONS = 'wms.integrations.manage';

    /** Work in every warehouse, including those restricted to their own staff (WMS-200). */
    public const ALL_WAREHOUSES = 'wms.warehouses.all';

    /** Break glass: approve one's own request in an emergency, audited and reviewed afterwards (WMS-201). */
    public const EMERGENCY_OVERRIDE = 'wms.override';

    /** Read the warehouse audit ledger (WMS-203). */
    public const AUDIT = 'wms.audit.view';

    public static function all(): array
    {
        return [self::VIEW, self::MANAGE_WAREHOUSES, self::MANAGE_LOCATIONS, self::BLOCK_LOCATIONS,
            self::MANAGE_ITEMS, self::CHANGE_ITEM_STATUS, self::APPROVE_DUPLICATE_BARCODES,
            self::ADJUST_STOCK, self::APPROVE_ADJUSTMENTS, self::ALL_CLIENTS, self::MANAGE_CLIENT_ACCESS,
            self::RECEIVE, self::APPROVE_RECEIVING_EXCEPTIONS, self::SUPPLIER_RETURNS, self::MOVE_STOCK, self::OVERRIDE_PUTAWAY,
            self::APPROVE_TRANSFERS, self::MANAGE_REPLENISHMENT, self::MANAGE_ORDERS, self::PICK, self::PACK, self::DISPATCH,
            self::COUNT, self::MANAGE_COUNTS,
            self::RETURNS, self::CREDIT_RETURNS, self::QUALITY, self::MANAGE_QUALITY, self::RECALLS, self::LOSSES, self::OVERRIDE_ROTATION,
            self::ACCOUNTING, self::APPROVE_ACCOUNTING,
            self::INVESTIGATE, self::MANAGE_PROTECTION, self::APPROVE_LOSS, self::PLAN,
            self::SUPERVISE, self::APPROVE_SLOTTING, self::INTEGRATIONS,
            self::ALL_WAREHOUSES, self::EMERGENCY_OVERRIDE, self::AUDIT];
    }
}
