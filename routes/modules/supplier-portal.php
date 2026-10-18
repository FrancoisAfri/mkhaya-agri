<?php

use App\Modules\SupplierManagement\Controllers\SupplierPortalController;
use App\Modules\SupplierManagement\Controllers\SupplierRegistrationController;
use Illuminate\Support\Facades\Route;

/**
 * Public (unauthenticated) Supplier self-service portal. The portal_token is the
 * credential — included from web.php OUTSIDE the auth group so suppliers can reach
 * it without logging in.
 */
Route::middleware(['web', 'throttle:30,1'])->group(function () {
    Route::prefix('supplier-portal')->name('supplier.portal.')->group(function () {
        Route::get('/{token}',                              [SupplierPortalController::class, 'show'])->name('show');
        Route::patch('/{token}',                            [SupplierPortalController::class, 'update'])->name('update');
        Route::post('/{token}/documents',                   [SupplierPortalController::class, 'uploadDocument'])->name('documents.store');
        Route::get('/{token}/documents/{document}/download', [SupplierPortalController::class, 'downloadDocument'])->name('documents.download');
    });

    /**
     * Registration from an onboarding invitation.
     *
     * Separate from the portal because the caller has no supplier record yet — that is the thing this
     * creates. Throttled harder than the portal: it is the only public endpoint that writes a new
     * company into a tenant, so it should not be usable to probe for live tokens at speed.
     */
    Route::prefix('supplier-registration')->name('supplier.registration.')->middleware('throttle:10,1')->group(function () {
        Route::get('/{token}',  [SupplierRegistrationController::class, 'show'])->name('show');
        Route::post('/{token}', [SupplierRegistrationController::class, 'store'])->name('store');
    });
});
