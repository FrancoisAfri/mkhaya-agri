<?php

use App\Modules\HR\Contracts\Http\Controllers\ContractController;
use App\Modules\HR\Contracts\Http\Controllers\SignatureController;
use App\Modules\HR\Offers\Http\Controllers\CandidateOfferController;
use App\Modules\HR\Offers\Http\Controllers\OfferController;
use Illuminate\Support\Facades\Route;

// ── Standalone offers page (ribbon entry point) ───────────────────────────────

Route::middleware(['web', 'auth', 'module.active:recruitment'])->group(function () {
    Route::get('/offers', [OfferController::class, 'allOffers'])->name('offers.index');
    Route::get('/offers/{job}/{offer}', [OfferController::class, 'show'])->name('offers.show');
    Route::get('/offers/{job}/{offer}/download-letter', [OfferController::class, 'downloadLetter'])->name('offers.download-letter');
});

// ── HR-facing offer and contract routes (job-scoped) ─────────────────────────

Route::middleware(['web', 'auth', 'module.active:recruitment'])->prefix('recruitment')->name('recruitment.')->group(function () {

    // ── Offers ────────────────────────────────────────────────────────────────
    Route::prefix('jobs/{job}/offers')->name('offers.')->group(function () {
        Route::post('/{application}',    [OfferController::class, 'store'])->name('store');
        Route::post('/{offer}/send',     [OfferController::class, 'send'])->name('send');
        Route::post('/{offer}/withdraw', [OfferController::class, 'withdraw'])->name('withdraw');

        // ── Contracts ─────────────────────────────────────────────────────────
        Route::prefix('/{offer}/contracts')->name('contracts.')->group(function () {
            Route::post('/',                [ContractController::class, 'store'])->name('store');
            Route::get('/{contract}',       [ContractController::class, 'show'])->name('show');
            Route::post('/{contract}/send', [ContractController::class, 'send'])->name('send');
        });
    });
});

// NOTE: the candidate-facing portal routes live in routes/modules/offers-portal.php — they must
// not be required inside web.php's auth group, which is where this file is loaded.
