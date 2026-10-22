<?php

use App\Modules\Agri\Http\Controllers\GrowerPortalAuthController;
use App\Modules\Agri\Http\Controllers\GrowerPortalController;
use App\Modules\Agri\Http\Middleware\EnsureGrowerPortal;
use Illuminate\Support\Facades\Route;

/*
| Mkhaya Agri grower portal: a contracted grower's own contracts, deliveries, advances, statements,
| visits and messages from the company.
|
| Deliberately outside the authenticated staff group and outside the agri.* name prefix (that prefix
| is for signed-in, permission-checked staff routes): a grower signs in on the `grower_portal` guard,
| which no staff route accepts, and EnsureGrowerPortal pins every request to the signed-in grower.
*/
Route::middleware('web')->prefix('grower')->name('grower.portal.')->group(function () {
    Route::get('/login', [GrowerPortalAuthController::class, 'showLogin'])->name('login');
    Route::post('/login/code', [GrowerPortalAuthController::class, 'requestCode'])->middleware('throttle:20,1')->name('code');
    Route::post('/login/verify', [GrowerPortalAuthController::class, 'verify'])->middleware('throttle:20,1')->name('verify');
    Route::post('/login/password', [GrowerPortalAuthController::class, 'password'])->middleware('throttle:20,1')->name('password.login');
    Route::post('/logout', [GrowerPortalAuthController::class, 'logout'])->name('logout');
    Route::post('/locale', [GrowerPortalAuthController::class, 'locale'])->name('locale');
    // The company's own logo on a page nobody has signed in to yet; served only where it is white-labelled.
    Route::get('/brand/{company}/logo', [GrowerPortalAuthController::class, 'logo'])->whereUuid('company')->name('logo');

    Route::middleware(EnsureGrowerPortal::class)->group(function () {
        Route::get('/', [GrowerPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/contracts', [GrowerPortalController::class, 'contracts'])->name('contracts');
        Route::get('/contracts/{contract}', [GrowerPortalController::class, 'contract'])->name('contracts.show');
        Route::get('/deliveries', [GrowerPortalController::class, 'deliveries'])->name('deliveries');
        Route::get('/advances', [GrowerPortalController::class, 'advances'])->name('advances');
        Route::get('/statements', [GrowerPortalController::class, 'statements'])->name('statements');
        Route::get('/statements/pdf/{settlement?}', [GrowerPortalController::class, 'statementPdf'])->name('statements.pdf');
        Route::get('/visits', [GrowerPortalController::class, 'visits'])->name('visits');
        Route::get('/messages', [GrowerPortalController::class, 'messages'])->name('messages');
        Route::post('/reports', [GrowerPortalController::class, 'storeReport'])->middleware('throttle:20,1')->name('reports.store');
        Route::post('/password', [GrowerPortalController::class, 'password'])->name('password');
    });
});
