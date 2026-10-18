<?php

use App\Modules\Tenancy\Http\Controllers\BillingController;
use App\Modules\Tenancy\Http\Controllers\CompanyController;
use App\Modules\Tenancy\Http\Controllers\CompanyUsageController;
use App\Modules\Tenancy\Http\Controllers\PlanController;
use App\Modules\Tenancy\Http\Controllers\PlatformOverviewController;
use App\Modules\Tenancy\Http\Controllers\ResellerController;
use App\Modules\Tenancy\Http\Controllers\SystemController;
use App\Modules\Tenancy\Http\Controllers\TokenRequestController;
use Illuminate\Support\Facades\Route;

// Platform Overview — the global roll-up a platform admin lands on after install.
Route::middleware('platform.admin')
    ->get('/platform/overview', [PlatformOverviewController::class, 'index'])
    ->name('tenancy.overview');

// Module catalog & per-module pricing (à la carte).
Route::prefix('platform/modules')->name('tenancy.modules.')->middleware('platform.admin')->group(function () {
    Route::get('/',            [\App\Modules\Tenancy\Http\Controllers\ModulePricingController::class, 'index'])->name('index');
    Route::put('/{module}',    [\App\Modules\Tenancy\Http\Controllers\ModulePricingController::class, 'update'])->name('update');
    Route::put('/addons/{addon}', [\App\Modules\Tenancy\Http\Controllers\ModulePricingController::class, 'updateAddon'])->name('addons.update');
});

// Subscription plans (module bundles + user caps).
Route::prefix('platform/plans')->name('tenancy.plans.')->middleware('platform.admin')->group(function () {
    Route::get('/',            [PlanController::class, 'index'])->name('index');
    Route::post('/',           [PlanController::class, 'store'])->name('store');
    Route::put('/{plan}',      [PlanController::class, 'update'])->name('update');
    Route::delete('/{plan}',   [PlanController::class, 'destroy'])->name('destroy');
});

// Platform billing — revenue KPIs + every company's invoices.
Route::prefix('platform/billing')->name('tenancy.billing.')->middleware('platform.admin')->group(function () {
    Route::get('/',                    [BillingController::class, 'index'])->name('index');
    Route::post('/generate',           [BillingController::class, 'generate'])->name('generate');
    Route::post('/overdue',            [BillingController::class, 'processOverdue'])->name('overdue');
    Route::post('/{invoice}/paid',     [BillingController::class, 'markPaid'])->name('paid');
});

// Reseller program — partners who bring clients and earn monthly commission (platform admin).
Route::prefix('platform/resellers')->name('tenancy.resellers.')->middleware('platform.admin')->group(function () {
    Route::get('/',                       [ResellerController::class, 'index'])->name('index');
    Route::post('/',                      [ResellerController::class, 'store'])->name('store');
    Route::get('/{reseller}',             [ResellerController::class, 'show'])->name('show');
    Route::put('/{reseller}',             [ResellerController::class, 'update'])->name('update');
    Route::delete('/{reseller}',          [ResellerController::class, 'destroy'])->name('destroy');
    Route::post('/{reseller}/suspend',    [ResellerController::class, 'suspend'])->name('suspend');
    Route::post('/{reseller}/activate',   [ResellerController::class, 'activate'])->name('activate');
    Route::post('/{reseller}/products',   [ResellerController::class, 'syncProducts'])->name('products');
    Route::post('/{reseller}/clients',    [ResellerController::class, 'assignClient'])->name('clients.assign');
    Route::delete('/{reseller}/clients',  [ResellerController::class, 'unassignClient'])->name('clients.unassign');
    Route::post('/commissions/{commission}/paid', [ResellerController::class, 'markPaid'])->name('commissions.paid');
    Route::post('/{reseller}/login',      [ResellerController::class, 'setLogin'])->name('login');
});

// Reseller self-service portal (Phase 2) — a reseller manages the clients they bring.
Route::prefix('reseller')->name('reseller.')->middleware('reseller')->group(function () {
    Route::get('/dashboard',   [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/clients',     [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'clients'])->name('clients');
    Route::post('/clients',    [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'storeClient'])->name('clients.store');
    Route::get('/commissions', [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'commissions'])->name('commissions');
    Route::get('/team',              [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'team'])->name('team');
    Route::post('/team',             [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'storeMember'])->name('team.store');
    Route::put('/team/{member}',     [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'updateMember'])->name('team.update')->whereNumber('member');
    Route::delete('/team/{member}',  [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'removeMember'])->name('team.remove')->whereNumber('member');
    Route::get('/profile',           [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'profile'])->name('profile');
    Route::put('/profile',           [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'updateProfile'])->name('profile.update');
    Route::get('/profile/logo',      [\App\Modules\Tenancy\Http\Controllers\ResellerPortalController::class, 'logo'])->name('profile.logo');
});

// System monitoring — queues, AI usage, database and server health.
Route::prefix('platform/system')->name('tenancy.system.')->middleware('platform.admin')->group(function () {
    Route::get('/',              [SystemController::class, 'index'])->name('index');
    Route::post('/queue/retry',  [SystemController::class, 'retryFailed'])->name('queue.retry');
    Route::post('/queue/flush',  [SystemController::class, 'flushFailed'])->name('queue.flush');
});

// Token/SMS top-up requests + enforcement toggle (platform admin).
Route::prefix('platform/token-requests')->name('tenancy.token-requests.')->middleware('platform.admin')->group(function () {
    Route::get('/',                       [TokenRequestController::class, 'index'])->name('index');
    Route::post('/{tokenRequest}/approve', [TokenRequestController::class, 'approve'])->name('approve');
    Route::post('/{tokenRequest}/reject',  [TokenRequestController::class, 'reject'])->name('reject');
    Route::post('/grant',                  [TokenRequestController::class, 'grant'])->name('grant');
    Route::post('/enforcement',            [TokenRequestController::class, 'enforcement'])->name('enforcement');
});

// Company self-service — AI/SMS usage + top-ups. OWNER ONLY (the original account
// admin who created the company); ordinary users under the account cannot manage billing.
Route::prefix('usage')->name('tenancy.usage.')->middleware('company.owner')->group(function () {
    Route::get('/',        [CompanyUsageController::class, 'index'])->name('index');
    Route::post('/request', [CompanyUsageController::class, 'request'])->name('request');
    Route::post('/buy',     [CompanyUsageController::class, 'buy'])->name('buy');
});

// Company self-service — manage subscription (bundle + à-la-carte modules). OWNER ONLY.
Route::prefix('subscription')->name('tenancy.subscription.')->middleware('company.owner')->group(function () {
    Route::get('/',        [\App\Modules\Tenancy\Http\Controllers\CompanySubscriptionController::class, 'index'])->name('index');
    Route::post('/compose', [\App\Modules\Tenancy\Http\Controllers\CompanySubscriptionController::class, 'compose'])->name('compose');
    Route::post('/bundle', [\App\Modules\Tenancy\Http\Controllers\CompanySubscriptionController::class, 'setBundle'])->name('bundle');
    Route::post('/add',    [\App\Modules\Tenancy\Http\Controllers\CompanySubscriptionController::class, 'addModule'])->name('add');
    Route::post('/remove', [\App\Modules\Tenancy\Http\Controllers\CompanySubscriptionController::class, 'removeModule'])->name('remove');
});

// Company self-service — payments (subscription + top-ups) via the active gateway.
// Initiating a payment is OWNER ONLY; the gateway return/cancel callbacks stay open
// (they carry the user's session back and verify by uuid).
Route::name('tenancy.pay.')->group(function () {
    Route::get('/subscription/pay',       [\App\Modules\Tenancy\Http\Controllers\PaymentController::class, 'pay'])->middleware('company.owner')->name('index');
    Route::post('/subscription/checkout', [\App\Modules\Tenancy\Http\Controllers\PaymentController::class, 'checkout'])->middleware('company.owner')->name('checkout');
    Route::get('/pay/return/{uuid}',     [\App\Modules\Tenancy\Http\Controllers\PaymentController::class, 'return'])->name('return');
    Route::get('/pay/cancel/{uuid}',     [\App\Modules\Tenancy\Http\Controllers\PaymentController::class, 'cancel'])->name('cancel');
});

// Platform admin — payment gateway settings (PayFast / PayGate / Ozow).
Route::prefix('platform/payment-settings')->name('tenancy.payment-settings.')->middleware('platform.admin')->group(function () {
    Route::get('/',                    [\App\Modules\Tenancy\Http\Controllers\PaymentSettingsController::class, 'index'])->name('index');
    Route::put('/{gateway}',           [\App\Modules\Tenancy\Http\Controllers\PaymentSettingsController::class, 'update'])->name('update');
    Route::post('/{gateway}/activate', [\App\Modules\Tenancy\Http\Controllers\PaymentSettingsController::class, 'activate'])->name('activate');
});

// Platform admin — Mkhaya's own Yodlee subscription, which every tenant's bank feed runs through.
Route::prefix('platform/yodlee')->name('tenancy.yodlee.')->middleware('platform.admin')->group(function () {
    Route::get('/',      [\App\Modules\Tenancy\Http\Controllers\PlatformYodleeController::class, 'index'])->name('index');
    Route::post('/',     [\App\Modules\Tenancy\Http\Controllers\PlatformYodleeController::class, 'save'])->name('save');
    Route::post('/test', [\App\Modules\Tenancy\Http\Controllers\PlatformYodleeController::class, 'test'])->name('test');
});

// Platform super-admin — tenant (company) management + impersonation (Phase F).
Route::prefix('platform/companies')->name('tenancy.companies.')->middleware('platform.admin')->group(function () {
    Route::get('/',                          [CompanyController::class, 'index'])->name('index');
    Route::post('/',                         [CompanyController::class, 'store'])->name('store');
    Route::put('/{company}',                  [CompanyController::class, 'update'])->name('update');
    Route::delete('/{company}',               [CompanyController::class, 'destroy'])->name('destroy');
    Route::post('/{company}/reset-admin-password', [CompanyController::class, 'resetAdminPassword'])->name('reset-admin-password');
    Route::post('/{company}/plan',           [CompanyController::class, 'setPlan'])->name('plan');
    Route::post('/{company}/compose',         [CompanyController::class, 'compose'])->name('compose');
    Route::post('/{company}/suspend',        [CompanyController::class, 'suspend'])->name('suspend');
    Route::post('/{company}/activate',       [CompanyController::class, 'activate'])->name('activate');
    Route::post('/{company}/extend-trial',   [CompanyController::class, 'extendTrial'])->name('extend-trial');
    Route::post('/{company}/white-label',    [CompanyController::class, 'setWhiteLabel'])->name('white-label');
    Route::post('/{company}/impersonate',    [CompanyController::class, 'impersonate'])->name('impersonate');
    Route::get('/{company}/convert-to-reseller',  [CompanyController::class, 'conversionCandidates'])->name('convert.candidates');
    Route::post('/{company}/convert-to-reseller', [CompanyController::class, 'convertToReseller'])->name('convert');
    Route::get('/{company}/invoices',        [CompanyController::class, 'invoices'])->name('invoices');
});

Route::middleware('platform.admin')->group(function () {
    Route::post('/platform/stop-impersonating',     [CompanyController::class, 'stopImpersonating'])->name('tenancy.stop-impersonating');
    Route::post('/platform/invoices/{invoice}/paid', [CompanyController::class, 'markPaid'])->name('tenancy.invoices.paid');
});
