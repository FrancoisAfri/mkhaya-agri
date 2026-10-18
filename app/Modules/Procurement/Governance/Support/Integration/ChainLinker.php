<?php

namespace App\Modules\Procurement\Governance\Support\Integration;

use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes §11's chain: request → requisition → order → invoice, and award → supplier → contract.
 *
 * The 360° record and the spend report read link roles that the governance module cannot produce on
 * its own — a contract is signed in Contract Management, an invoice arrives in Accounting, days or
 * weeks after the award, by somebody else entirely. Without something to go and find them, those
 * sections stay empty forever and the "awards with no contract" compliance check flags every award
 * for the rest of time.
 *
 * So linking is a **reconciliation**, not a one-off write at award time. It runs immediately on
 * award (so the supplier appears at once), on demand from the record page, and nightly, which is
 * what makes a contract signed next Tuesday show up on Wednesday morning.
 *
 * Discovery only follows real foreign keys:
 *
 *     record → requisition → purchase_orders → contract_id
 *                                           → supplier_invoices
 *     record → awarded bidder → supplier
 *
 * It deliberately does **not** guess. There is no key from a contract back to a procurement, so a
 * contract that was never raised through a purchase order is linked by hand rather than inferred
 * from "same supplier, similar dates" — a wrong contract on a procurement record is worse than a
 * missing one, because the missing one is visible and the wrong one is not.
 *
 * Every step is gated on the owning module being installed, so on a standalone tenant this is a
 * no-op rather than an error.
 */
class ChainLinker
{
    public function __construct(
        private readonly Capabilities $capabilities,
        private readonly RecordService $records,
    ) {}

    /**
     * Discover and refresh every link reachable from one record.
     *
     * @return array<string,int> links written, by role
     */
    public function sync(int $companyId, int $recordId): array
    {
        $record = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->where('id', $recordId)
            ->first();

        if (! $record) {
            return [];
        }

        $written = [];

        $supplierId = $this->linkSupplier($companyId, $record, $written);
        $requisitionId = $this->linkRequisition($companyId, $record, $written);
        $orderIds = $this->linkPurchaseOrders($companyId, $record, $requisitionId, $written);

        $this->linkContracts($companyId, $record, $orderIds, $supplierId, $written);
        $this->linkInvoices($companyId, $record, $orderIds, $written);

        return array_filter($written);
    }

    /**
     * Reconcile every open record for a tenant. Bounded, and ordered oldest-first, so a large
     * backlog drains predictably across runs rather than starving the tail.
     */
    public function syncAll(int $companyId, int $limit = 500): int
    {
        $records = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            // Closed procurements still accrue invoices, so they are included for a grace period
            // rather than dropped the moment they close.
            ->where(function ($q) {
                $q->where('status', 'ACTIVE')
                    ->orWhere('closed_at', '>=', now()->subMonths(6));
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $linked = 0;

        foreach ($records as $recordId) {
            $linked += array_sum($this->sync($companyId, (int) $recordId));
        }

        return $linked;
    }

    /**
     * Open a 360° record for a requisition that does not have one, so the chain starts where the
     * brief says it starts — at the request — rather than at planning.
     */
    public function openFromRequisition(int $companyId, int $requisitionId): ?object
    {
        if (! $this->has('procurement.requisitions') || ! Schema::hasTable('procurement_requisitions')) {
            return null;
        }

        $existing = $this->records->findByLink($companyId, 'procurement_requisitions', $requisitionId);

        if ($existing) {
            return $existing;
        }

        $requisition = DB::table('procurement_requisitions')
            ->where('company_id', $companyId)
            ->where('id', $requisitionId)
            ->whereNull('deleted_at')
            ->first();

        if (! $requisition) {
            return null;
        }

        $record = $this->records->open($companyId, [
            'title' => $requisition->title ?: ('Requisition '.$requisition->requisition_number),
            'method' => $requisition->procurement_method,
            'officer_id' => $requisition->officer_id,
            'estimated_value' => $requisition->estimated_total,
            'currency' => $requisition->currency ?? 'ZAR',
            'stage' => 'REQUEST',
            'origin' => [
                'link_role' => 'request',
                'entity_type' => 'procurement_requisitions',
                'entity_id' => (int) $requisition->id,
                'entity_reference' => $requisition->requisition_number,
                'label' => $requisition->title,
                'amount' => $requisition->estimated_total,
                'state' => $requisition->status,
                'source_module' => 'procurement_requests',
            ],
        ]);

        $this->sync($companyId, (int) $record->id);

        return $record;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function has(string $integration): bool
    {
        return $this->capabilities->integratesWith($integration);
    }

    /** The awarded bidder's supplier, falling back to the requisition's. */
    private function linkSupplier(int $companyId, object $record, array &$written): ?int
    {
        if (! $this->has('procurement.suppliers') || ! Schema::hasTable('suppliers')) {
            return null;
        }

        $supplierId = DB::table('procgov_bidders as b')
            ->join('procgov_tender_reviews as r', 'r.id', '=', 'b.review_id')
            ->where('r.record_id', $record->id)
            ->where('b.status', 'AWARDED')
            ->whereNotNull('b.supplier_id')
            ->value('b.supplier_id');

        if (! $supplierId && Schema::hasTable('procurement_requisitions')) {
            $supplierId = DB::table('procurement_requisitions as q')
                ->join('procgov_record_links as l', function ($join) {
                    $join->on('l.entity_id', '=', 'q.id')
                        ->where('l.entity_type', '=', 'procurement_requisitions');
                })
                ->where('l.record_id', $record->id)
                ->whereNotNull('q.supplier_id')
                ->value('q.supplier_id');
        }

        if (! $supplierId) {
            return null;
        }

        $supplier = DB::table('suppliers')
            ->where('id', $supplierId)
            ->where('company_id', $companyId)
            ->first(['id', 'company_name', 'supplier_number', 'is_active']);

        if (! $supplier) {
            return null;
        }

        $this->records->link($companyId, (int) $record->id, [
            'link_role' => 'supplier',
            'entity_type' => 'suppliers',
            'entity_id' => (int) $supplier->id,
            'entity_reference' => $supplier->supplier_number,
            'label' => $supplier->company_name,
            'amount' => $record->awarded_value,
            'state' => $supplier->is_active ? 'ACTIVE' : 'INACTIVE',
            'source_module' => 'supplier_management',
        ]);

        $written['supplier'] = ($written['supplier'] ?? 0) + 1;

        return (int) $supplier->id;
    }

    /** The requisition behind this record, and the purchase request behind that. */
    private function linkRequisition(int $companyId, object $record, array &$written): ?int
    {
        if (! $this->has('procurement.requisitions') || ! Schema::hasTable('procurement_requisitions')) {
            return null;
        }

        $requisitionId = DB::table('procgov_tender_reviews')
            ->where('record_id', $record->id)
            ->whereNotNull('requisition_id')
            ->value('requisition_id');

        $requisitionId ??= DB::table('procgov_record_links')
            ->where('record_id', $record->id)
            ->where('entity_type', 'procurement_requisitions')
            ->value('entity_id');

        if (! $requisitionId) {
            return null;
        }

        $requisition = DB::table('procurement_requisitions')
            ->where('id', $requisitionId)
            ->where('company_id', $companyId)
            ->first();

        if (! $requisition) {
            return null;
        }

        $this->records->link($companyId, (int) $record->id, [
            'link_role' => 'request',
            'entity_type' => 'procurement_requisitions',
            'entity_id' => (int) $requisition->id,
            'entity_reference' => $requisition->requisition_number,
            'label' => $requisition->title,
            'amount' => $requisition->estimated_total,
            'state' => $requisition->status,
            'source_module' => 'procurement_requests',
        ]);

        $written['request'] = ($written['request'] ?? 0) + 1;

        if ($requisition->purchase_request_id && Schema::hasTable('purchase_requests')) {
            $request = DB::table('purchase_requests')
                ->where('id', $requisition->purchase_request_id)
                ->where('company_id', $companyId)
                ->first(['id', 'request_number', 'justification', 'estimated_total', 'status']);

            if ($request) {
                $this->records->link($companyId, (int) $record->id, [
                    'link_role' => 'request',
                    'entity_type' => 'purchase_requests',
                    'entity_id' => (int) $request->id,
                    'entity_reference' => $request->request_number,
                    'label' => $request->justification,
                    'amount' => $request->estimated_total,
                    'state' => $request->status,
                    'source_module' => 'procurement_requests',
                ]);

                $written['request']++;
            }
        }

        return (int) $requisition->id;
    }

    /** @return list<int> purchase order ids reached from the requisition */
    private function linkPurchaseOrders(int $companyId, object $record, ?int $requisitionId, array &$written): array
    {
        if (! $requisitionId || ! $this->has('procurement.orders') || ! Schema::hasTable('purchase_orders')) {
            return [];
        }

        $orders = DB::table('purchase_orders')
            ->where('company_id', $companyId)
            ->where('procurement_requisition_id', $requisitionId)
            ->whereNull('deleted_at')
            ->get(['id', 'po_number', 'title', 'amount', 'status', 'contract_id']);

        foreach ($orders as $order) {
            $this->records->link($companyId, (int) $record->id, [
                'link_role' => 'purchase_order',
                'entity_type' => 'purchase_orders',
                'entity_id' => (int) $order->id,
                'entity_reference' => $order->po_number,
                'label' => $order->title,
                'amount' => $order->amount,
                'state' => $order->status,
                'source_module' => 'procurement_requests',
            ]);

            $written['purchase_order'] = ($written['purchase_order'] ?? 0) + 1;
        }

        return $orders->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Contracts reached through a purchase order. See the class docblock for why a contract that
     * was never raised through an order is linked by hand instead of guessed.
     */
    private function linkContracts(int $companyId, object $record, array $orderIds, ?int $supplierId, array &$written): void
    {
        if (! $this->has('contracts.register') || ! Schema::hasTable('contracts') || $orderIds === []) {
            return;
        }

        $contractIds = DB::table('purchase_orders')
            ->whereIn('id', $orderIds)
            ->whereNotNull('contract_id')
            ->distinct()
            ->pluck('contract_id');

        if ($contractIds->isEmpty()) {
            return;
        }

        $contracts = DB::table('contracts')
            ->whereIn('id', $contractIds)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->get(['id', 'contract_number', 'title', 'contract_value', 'final_contract_value',
                'contract_status', 'start_date', 'end_date']);

        foreach ($contracts as $contract) {
            $this->records->link($companyId, (int) $record->id, [
                'link_role' => 'contract',
                'entity_type' => 'contracts',
                'entity_id' => (int) $contract->id,
                'entity_reference' => $contract->contract_number,
                'label' => $contract->title,
                'amount' => $contract->final_contract_value ?? $contract->contract_value,
                'state' => $contract->contract_status,
                'source_module' => 'contract_management',
                // The expiring-contract sweep reads end_date from here, so it keeps working when
                // Contract Management is later switched off.
                'meta' => ['start_date' => $contract->start_date, 'end_date' => $contract->end_date],
            ]);

            $written['contract'] = ($written['contract'] ?? 0) + 1;
        }
    }

    private function linkInvoices(int $companyId, object $record, array $orderIds, array &$written): void
    {
        if (! $this->has('finance.invoices') || ! Schema::hasTable('supplier_invoices') || $orderIds === []) {
            return;
        }

        $invoices = DB::table('supplier_invoices')
            ->where('company_id', $companyId)
            ->whereIn('purchase_order_id', $orderIds)
            ->whereNull('deleted_at')
            ->get(['id', 'invoice_number', 'amount', 'status', 'payment_status', 'invoice_date']);

        foreach ($invoices as $invoice) {
            $this->records->link($companyId, (int) $record->id, [
                'link_role' => 'invoice',
                'entity_type' => 'supplier_invoices',
                'entity_id' => (int) $invoice->id,
                'entity_reference' => $invoice->invoice_number,
                'label' => 'Invoice '.$invoice->invoice_number,
                'amount' => $invoice->amount,
                'state' => $invoice->payment_status ?: $invoice->status,
                'source_module' => 'accounting',
                'meta' => ['invoice_date' => $invoice->invoice_date],
            ]);

            $written['invoice'] = ($written['invoice'] ?? 0) + 1;
        }
    }

    /**
     * Link a contract by hand — the escape hatch for a contract that was not raised through a
     * purchase order, which is most of them on a tenant that runs contracting separately.
     */
    public function linkContractManually(int $companyId, int $recordId, int $contractId): bool
    {
        if (! $this->has('contracts.register') || ! Schema::hasTable('contracts')) {
            return false;
        }

        $contract = DB::table('contracts')
            ->where('id', $contractId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first(['id', 'contract_number', 'title', 'contract_value', 'final_contract_value',
                'contract_status', 'start_date', 'end_date']);

        if (! $contract) {
            return false;
        }

        $this->records->link($companyId, $recordId, [
            'link_role' => 'contract',
            'entity_type' => 'contracts',
            'entity_id' => (int) $contract->id,
            'entity_reference' => $contract->contract_number,
            'label' => $contract->title,
            'amount' => $contract->final_contract_value ?? $contract->contract_value,
            'state' => $contract->contract_status,
            'source_module' => 'contract_management',
            'meta' => ['start_date' => $contract->start_date, 'end_date' => $contract->end_date],
        ]);

        return true;
    }

    /** Contracts a user could pick from when linking by hand. */
    public function selectableContracts(int $companyId, ?int $supplierId = null, int $limit = 200): array
    {
        if (! $this->has('contracts.register') || ! Schema::hasTable('contracts')) {
            return [];
        }

        return DB::table('contracts')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->orderByDesc('start_date')
            ->limit($limit)
            ->get(['id', 'contract_number', 'title', 'contract_value', 'contract_status'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'reference' => $c->contract_number,
                'title' => $c->title,
                'value' => $c->contract_value,
                'status' => $c->contract_status,
            ])
            ->all();
    }
}
