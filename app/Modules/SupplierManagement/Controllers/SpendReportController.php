<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SpendReportController extends Controller
{
    /**
     * Spend analysis / off-contract leakage report.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Supplier::class);

        // ── Total spend (all invoices) ──────────────────────────────────────────
        $totalSpend = (float) SupplierInvoice::sum('amount');

        // ── Off-contract spend (invoices with no contract link) ─────────────────
        $offContractInvoiceValue = (float) SupplierInvoice::offContract()->sum('amount');
        $offContractInvoiceCount = (int) SupplierInvoice::offContract()->count();

        // ── Off-contract POs (no contract link) ─────────────────────────────────
        $offContractPoValue = (float) PurchaseOrder::offContract()->sum('amount');
        $offContractPoCount = (int) PurchaseOrder::offContract()->count();

        // Combined leakage figure surfaces invoice + PO exposure that bypasses contracts.
        $offContractValue = $offContractInvoiceValue + $offContractPoValue;
        $offContractCount = $offContractInvoiceCount + $offContractPoCount;
        $offContractPct   = $totalSpend > 0
            ? round(($offContractInvoiceValue / $totalSpend) * 100, 1)
            : 0.0;

        // ── Spend by supplier (top 10 by invoice value) ─────────────────────────
        $spendBySupplier = SupplierInvoice::query()
            ->select('supplier_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as invoices'))
            ->groupBy('supplier_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $supplier = Supplier::find($row->supplier_id, ['id', 'company_name']);

                return [
                    'supplier_id'  => (int) $row->supplier_id,
                    'company_name' => $supplier?->company_name ?? '—',
                    'total'        => (float) $row->total,
                    'invoices'     => (int) $row->invoices,
                ];
            })
            ->values()
            ->all();

        // ── Leakage list: off-contract invoices ─────────────────────────────────
        $offContractInvoices = SupplierInvoice::with('supplier:id,company_name')
            ->offContract()
            ->latest()
            ->limit(100)
            ->get(['id', 'invoice_number', 'supplier_id', 'amount', 'currency', 'status', 'invoice_date'])
            ->map(fn ($i) => [
                'id'             => $i->id,
                'invoice_number' => $i->invoice_number,
                'company_name'   => $i->supplier?->company_name ?? '—',
                'amount'         => (float) $i->amount,
                'currency'       => $i->currency,
                'status'         => $i->status,
                'invoice_date'   => optional($i->invoice_date)->toDateString(),
                'type'           => 'invoice',
            ])
            ->all();

        // ── Leakage list: off-contract POs ──────────────────────────────────────
        $offContractPurchaseOrders = PurchaseOrder::with('supplier:id,company_name')
            ->offContract()
            ->latest()
            ->limit(100)
            ->get(['id', 'po_number', 'supplier_id', 'amount', 'currency', 'status', 'order_date'])
            ->map(fn ($p) => [
                'id'           => $p->id,
                'po_number'    => $p->po_number,
                'company_name' => $p->supplier?->company_name ?? '—',
                'amount'       => (float) $p->amount,
                'currency'     => $p->currency,
                'status'       => $p->status,
                'order_date'   => optional($p->order_date)->toDateString(),
                'type'         => 'purchase_order',
            ])
            ->all();

        return Inertia::render('Modules/SupplierManagement/Spend/Report', [
            'kpis' => [
                'total_spend'         => $totalSpend,
                'off_contract_value'  => $offContractValue,
                'off_contract_invoice_value' => $offContractInvoiceValue,
                'off_contract_po_value'      => $offContractPoValue,
                'off_contract_count'  => $offContractCount,
                'off_contract_pct'    => $offContractPct,
                'suppliers_count'     => (int) Supplier::count(),
            ],
            'spendBySupplier'           => $spendBySupplier,
            'offContractInvoices'       => $offContractInvoices,
            'offContractPurchaseOrders' => $offContractPurchaseOrders,
        ]);
    }
}
