<?php

use App\Modules\Procurement\Governance\Http\Controllers\BidPortalController;
use Illuminate\Support\Facades\Route;

/*
 * Public bid submission portal (§1.1). Unauthenticated: the invitation token IS the credential, so
 * this is included from web.php OUTSIDE the auth group.
 *
 * Deliberately not behind module.active or crud.permissions. Those gates ask what the *tenant* has
 * bought and what a *user* may do, and a bidder is neither — they hold one token for one tender.
 * The token lookup itself carries the authorisation, and BidPortalController binds the tenant from
 * the invitation before touching anything else.
 *
 * Throttled harder than the supplier portal. A token is the entire credential here, and the thing
 * it protects is every competitor's price, so brute-force resistance matters more than convenience:
 * 10 attempts a minute is generous for a bidder filling in one form and useless for enumeration.
 */
Route::middleware(['web', 'throttle:10,1'])->group(function () {
    Route::prefix('bid')->name('procgov.portal.')->group(function () {
        Route::get('/{token}', [BidPortalController::class, 'show'])->name('show');
        Route::post('/{token}', [BidPortalController::class, 'submit'])->name('submit');
    });
});
