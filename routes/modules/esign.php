<?php

use App\Modules\ESign\Http\Controllers\EnvelopeController;
use App\Modules\ESign\Http\Controllers\MySignaturesController;
use App\Modules\ESign\Http\Controllers\SigningPortalController;
use Illuminate\Support\Facades\Route;

// ── HR-facing envelope management (authenticated) ────────────────────────────

Route::middleware(['web', 'auth'])->prefix('esign')->name('esign.envelopes.')->group(function () {
    Route::get('/',                          [EnvelopeController::class, 'index'])->name('index');
    Route::get('/create',                    [EnvelopeController::class, 'create'])->name('create');

    /*
     * Everything waiting for my signature. A distinct prefix rather than /esign/sign, which
     * belongs to the token portal — a literal that sits beside a wildcard is exactly the shape
     * that swallowed the hub links, and there is no reason to invite it twice.
     */
    Route::get('/my-signatures',             [MySignaturesController::class, 'index'])->name('mine');
    Route::post('/my-signatures/otp',        [MySignaturesController::class, 'sendOtp'])->name('mine.otp');
    Route::post('/my-signatures/sign',       [MySignaturesController::class, 'sign'])->name('mine.sign');
    Route::post('/',                         [EnvelopeController::class, 'store'])->name('store');
    /*
     * whereUuid on every wildcard, because this group registers before the hub routes and a bare
     * {uuid} swallows any literal segment that follows it. `esign/envelopes-hub` — the "Sign" item
     * in the sidebar — matched `esign/{uuid}` and reached the controller as the string
     * "envelopes-hub", which Postgres then refused as a uuid: a 500 on a nav link, from a route
     * that was registered correctly and simply never reached.
     *
     * Ordering would also fix it, and would break again the next time a hub or a literal path is
     * added. A constraint cannot be undone by someone appending a route.
     */
    Route::get('/{uuid}',                    [EnvelopeController::class, 'show'])->whereUuid('uuid')->name('show');
    Route::post('/{uuid}/send',                  [EnvelopeController::class, 'send'])->whereUuid('uuid')->name('send');
    Route::post('/{uuid}/void',                  [EnvelopeController::class, 'void'])->whereUuid('uuid')->name('void');
    // Drafts and voided envelopes only — the service refuses anything signed.
    Route::delete('/{uuid}',                     [EnvelopeController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
    Route::get('/{uuid}/document',               [EnvelopeController::class, 'document'])->whereUuid('uuid')->name('document');
    Route::post('/{uuid}/document',              [EnvelopeController::class, 'uploadDocument'])->whereUuid('uuid')->name('document.upload');
    Route::get('/{uuid}/certificate/download',   [EnvelopeController::class, 'downloadCertificate'])->whereUuid('uuid')->name('certificate.download');
});
