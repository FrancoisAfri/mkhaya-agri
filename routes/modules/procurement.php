<?php

use App\Modules\Procurement\Controllers\InvoiceController;
use App\Modules\Procurement\Controllers\ProcurementDashboardController;
use App\Modules\Procurement\Controllers\ProcurementApprovalController;
use App\Modules\Procurement\Controllers\ProcurementPolicyController;
use App\Modules\Procurement\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Controllers\PurchaseRequestController;
use App\Modules\Procurement\Controllers\RequisitionController;
use App\Modules\Procurement\Controllers\SpendIntelligenceController;
use Illuminate\Support\Facades\Route;

// Auth + crud.permissions already applied by the parent group in web.php.
Route::middleware('module.active:procurement_requests')->group(function () {

    // ── Role-adaptive dashboard ───────────────────────────────────────────────────
    Route::get('procurement/dashboard', [ProcurementDashboardController::class, 'index'])->name('procurement.dashboard');

    // ── Purchase requests ───────────────────────────────────────────────────────
    Route::prefix('purchase-requests')->name('purchase-requests.')->group(function () {
        Route::get('/',       [PurchaseRequestController::class, 'index'])->name('index');
        Route::get('/create', [PurchaseRequestController::class, 'create'])->name('create');
        Route::post('/',      [PurchaseRequestController::class, 'store'])->name('store');
        Route::get('/attachments/{attachment}/download', [PurchaseRequestController::class, 'downloadAttachment'])->name('attachments.download')->whereNumber('attachment');

        Route::get('/{purchaseRequest}',    [PurchaseRequestController::class, 'show'])->name('show')->whereUuid('purchaseRequest');
        Route::delete('/{purchaseRequest}', [PurchaseRequestController::class, 'destroy'])->name('destroy')->whereUuid('purchaseRequest');
        Route::post('/{purchaseRequest}/submit',      [PurchaseRequestController::class, 'submit'])->name('submit')->whereUuid('purchaseRequest');
        Route::post('/{purchaseRequest}/cancel',      [PurchaseRequestController::class, 'cancel'])->name('cancel')->whereUuid('purchaseRequest');
        Route::post('/{purchaseRequest}/attachments', [PurchaseRequestController::class, 'uploadAttachment'])->name('attachments.store')->whereUuid('purchaseRequest');
        Route::post('/{purchaseRequest}/convert',     [RequisitionController::class, 'convert'])->name('convert')->whereUuid('purchaseRequest');
    });

    // ── Procurement requisitions ────────────────────────────────────────────────
    Route::prefix('requisitions')->name('requisitions.')->group(function () {
        Route::get('/', [RequisitionController::class, 'index'])->name('index');
        Route::get('/attachments/{attachment}/download', [RequisitionController::class, 'downloadAttachment'])->name('attachments.download')->whereNumber('attachment');
        Route::get('/{requisition}', [RequisitionController::class, 'show'])->name('show')->whereUuid('requisition');
        Route::post('/{requisition}/assign-officer',     [RequisitionController::class, 'assignOfficer'])->name('assign-officer')->whereUuid('requisition');
        Route::post('/{requisition}/method',             [RequisitionController::class, 'confirmMethod'])->name('method')->whereUuid('requisition');
        Route::post('/{requisition}/confirm-reservation', [RequisitionController::class, 'confirmReservation'])->name('confirm-reservation')->whereUuid('requisition');
        Route::post('/{requisition}/rfq',                [RequisitionController::class, 'createRfq'])->name('rfq')->whereUuid('requisition');
        Route::post('/{requisition}/choose-supplier',    [RequisitionController::class, 'chooseSupplier'])->name('choose-supplier')->whereUuid('requisition');
        Route::post('/{requisition}/attachments',        [RequisitionController::class, 'uploadAttachment'])->name('attachments.store')->whereUuid('requisition');
        Route::post('/{requisition}/cancel',             [RequisitionController::class, 'cancel'])->name('cancel')->whereUuid('requisition');
        Route::post('/{requisition}/generate-po',        [PurchaseOrderController::class, 'generate'])->name('generate-po')->whereUuid('requisition');
    });

    // ── Purchase orders (P2P flow: generate → approve → send → deliver → GRN) ─────
    Route::prefix('purchase-orders')->name('purchase-orders.')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
        Route::get('/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('show')->whereUuid('purchaseOrder');
        Route::post('/{purchaseOrder}/approve',  [PurchaseOrderController::class, 'approve'])->name('approve')->whereUuid('purchaseOrder');
        Route::post('/{purchaseOrder}/send',     [PurchaseOrderController::class, 'send'])->name('send')->whereUuid('purchaseOrder');
        Route::post('/{purchaseOrder}/delivery', [PurchaseOrderController::class, 'storeDelivery'])->name('delivery')->whereUuid('purchaseOrder');
        Route::post('/{purchaseOrder}/grn',      [PurchaseOrderController::class, 'storeGrn'])->name('grn')->whereUuid('purchaseOrder');
        Route::post('/{purchaseOrder}/amend',    [PurchaseOrderController::class, 'amend'])->name('amend')->whereUuid('purchaseOrder');
        Route::post('/{purchaseOrder}/cancel',   [PurchaseOrderController::class, 'cancel'])->name('cancel')->whereUuid('purchaseOrder');
    });

    // ── Invoices: AI extract → 3-way match → reconcile → pay ─────────────────────
    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::get('/create', [InvoiceController::class, 'create'])->name('create');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show')->whereNumber('invoice');
        Route::get('/{invoice}/document', [InvoiceController::class, 'downloadDocument'])->name('document')->whereNumber('invoice');
        Route::post('/{invoice}/document', [InvoiceController::class, 'uploadDocument'])->name('document.upload')->whereNumber('invoice');
        Route::post('/{invoice}/extract', [InvoiceController::class, 'extract'])->name('extract')->whereNumber('invoice');
        Route::post('/{invoice}/items', [InvoiceController::class, 'updateItems'])->name('items')->whereNumber('invoice');
        Route::post('/{invoice}/match', [InvoiceController::class, 'match'])->name('match')->whereNumber('invoice');
        Route::post('/{invoice}/reconcile', [InvoiceController::class, 'reconcile'])->name('reconcile')->whereNumber('invoice');
        Route::post('/{invoice}/pay', [InvoiceController::class, 'markPaid'])->name('pay')->whereNumber('invoice');
    });

    // ── Approvals inbox ─────────────────────────────────────────────────────────
    Route::prefix('procurement/approvals')->name('procurement.approvals.')->group(function () {
        Route::get('/', [ProcurementApprovalController::class, 'index'])->name('index');
        Route::post('/{approval}/approve',  [ProcurementApprovalController::class, 'approve'])->name('approve')->whereNumber('approval');
        Route::post('/{approval}/reject',   [ProcurementApprovalController::class, 'reject'])->name('reject')->whereNumber('approval');
        Route::post('/{approval}/escalate', [ProcurementApprovalController::class, 'escalate'])->name('escalate')->whereNumber('approval');
    });

    // ── AI spend intelligence ────────────────────────────────────────────────────
    Route::prefix('procurement/intelligence')->name('procurement.intelligence.')->group(function () {
        Route::get('/', [SpendIntelligenceController::class, 'dashboard'])->name('index');
        Route::post('/run', [SpendIntelligenceController::class, 'run'])->name('run');
        Route::post('/narrative', [SpendIntelligenceController::class, 'generateNarrative'])->name('narrative');
        Route::post('/insights/{insight}', [SpendIntelligenceController::class, 'updateInsight'])->name('insights.update')->whereNumber('insight');
    });

    // ── Procurement policy rules ────────────────────────────────────────────────
    Route::prefix('procurement/policies')->name('procurement.policies.')->group(function () {
        Route::get('/',          [ProcurementPolicyController::class, 'index'])->name('index');
        Route::post('/',         [ProcurementPolicyController::class, 'store'])->name('store');
        Route::patch('/{rule}',  [ProcurementPolicyController::class, 'update'])->name('update')->whereNumber('rule');
        Route::delete('/{rule}', [ProcurementPolicyController::class, 'destroy'])->name('destroy')->whereNumber('rule');
        Route::post('/exceptions/{exception}/approve', [ProcurementPolicyController::class, 'approveException'])->name('exceptions.approve')->whereNumber('exception');
        Route::post('/exceptions/{exception}/reject',  [ProcurementPolicyController::class, 'rejectException'])->name('exceptions.reject')->whereNumber('exception');
    });
});
