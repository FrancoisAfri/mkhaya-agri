<?php

use App\Modules\ESign\Http\Controllers\SigningPortalController;
use Illuminate\Support\Facades\Route;

/*
 * The public signing portal — the emailed token IS the credential.
 *
 * These routes lived in routes/modules/esign.php, which web.php requires from inside a group
 * carrying `auth`. A group's middleware is inherited and cannot be removed from within, so
 * declaring `Route::middleware('web')` here did nothing: every signing link arrived carrying
 * Illuminate\Auth\Middleware\Authenticate and bounced the signer to /login.
 *
 * Which is fatal for the feature. The people these links go to are external — a contractor, an
 * employee's next of kin, a client's director — and by definition have no account to log in with.
 * The link worked for whoever tested it while already signed in, and for nobody else.
 *
 * So it sits out here with the other token-credentialled portals, outside every authenticated
 * group. The token is still checked, still expiring, still single-use.
 */
Route::middleware('web')->prefix('esign/sign')->name('esign.portal.')->group(function () {
    Route::get('/{token}',         [SigningPortalController::class, 'view'])->name('view');
    // The document itself, reachable with the same token that opened the page.
    Route::get('/{token}/document',[SigningPortalController::class, 'document'])->name('document');
    Route::post('/{token}/consent',[SigningPortalController::class, 'consent'])->name('consent');

    Route::post('/{token}/otp',    [SigningPortalController::class, 'requestOtp'])
        ->middleware('throttle:esign.otp-request')
        ->name('otp');

    Route::post('/{token}/sign',   [SigningPortalController::class, 'sign'])
        ->middleware('throttle:esign.otp-verify')
        ->name('sign');

    Route::post('/{token}/decline',[SigningPortalController::class, 'decline'])->name('decline');
});
