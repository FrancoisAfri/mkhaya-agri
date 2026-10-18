<?php

use App\Modules\HR\Contracts\Http\Controllers\SignatureController;
use App\Modules\HR\Offers\Http\Controllers\CandidateOfferController;
use Illuminate\Support\Facades\Route;

/*
 * The candidate's side of an offer, and of signing the contract that follows.
 *
 * These live in their own file for one reason: `routes/modules/offers.php` is required inside the
 * ['auth', 'auth.lock', 'check.banned', ...] group in web.php, and a group's middleware applies to
 * everything required within it. So these routes — commented as unauthenticated and gated by an
 * emailed token — were sitting behind a login, and a candidate following their offer link was
 * answered "Unauthenticated." They have no account; that is the entire point of the token.
 *
 * Required at the top level of web.php alongside the other public portals, which are kept separate
 * for exactly the same reason.
 */

// ── Candidate-facing portal routes (unauthenticated — token-gated) ────────────

Route::middleware('web')->prefix('portal')->name('recruitment.offers.portal.')->group(function () {

    Route::prefix('offers/{offerUuid}')->group(function () {
        Route::get('/',        [CandidateOfferController::class, 'view'])->name('view');

        Route::post('/otp',    [CandidateOfferController::class, 'requestOtp'])
            ->middleware('throttle:recruitment.otp-request')
            ->name('otp');

        Route::post('/accept', [CandidateOfferController::class, 'accept'])
            ->middleware('throttle:recruitment.otp-verify')
            ->name('accept');

        Route::post('/decline', [CandidateOfferController::class, 'decline'])->name('decline');
    });
});

// ── Signature portal routes (unauthenticated — token-gated) ──────────────────

Route::middleware('web')->prefix('portal')->name('recruitment.contracts.sign.')->group(function () {

    Route::prefix('sign/{signatureRequestUuid}')->group(function () {
        Route::get('/',       [SignatureController::class, 'view'])->name('view');

        Route::post('/otp',   [SignatureController::class, 'requestOtp'])
            ->middleware('throttle:recruitment.otp-request')
            ->name('otp');

        Route::post('/sign',  [SignatureController::class, 'sign'])
            ->middleware('throttle:recruitment.otp-verify')
            ->name('sign');
    });
});
