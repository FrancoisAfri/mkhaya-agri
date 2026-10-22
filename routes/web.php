<?php

use App\Core\AI\Controllers\AICopilotController;
use App\Core\Auth\Controllers\AuthController;
use App\Core\Security\AdminController;
use App\Modules\Dashboard\Controller\DashbaordController;
use App\Modules\Fleet\Controllers\FleetBookingController;
use App\Modules\HR\Recruitment\Http\Controllers\PublicApplicationController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// CACHE CLEAR ROUTE
Route::middleware(['auth', 'role:administrator'])->group(function () {
    Route::get('cache-clear', function () {
        Artisan::call('optimize:clear');
        request()->session()->flash('success', 'Successfully cache cleared.');

        return redirect()->back();
    })->name('cache.clear');

    // STORAGE LINKED ROUTE
    Route::get('storage-link', [AdminController::class, 'storageLink'])->name('storage.link');
});


// ── PUBLIC ROUTES ──────────────────────────
require __DIR__.'/core/auth.php';

// Fleet booking email-approval token links (no auth required — token IS the credential)
Route::prefix('fleet/approvals')->name('fleet.approvals.token.')->group(function () {
    Route::get('/approve/{token}', [FleetBookingController::class, 'approveByToken'])->name('approve');
    Route::get('/reject/{token}',  [FleetBookingController::class, 'rejectByToken'])->name('reject');
});

/*
 * ── PUBLIC LEGAL PAGES (no auth, and that is the requirement) ───
 *
 * Facebook and LinkedIn fetch the privacy policy URL with a crawler that has no session, and
 * Facebook will not let an app leave development mode until it answers — a policy behind a login is
 * the same to them as no policy. Facebook also requires the data deletion URL before going live.
 *
 * Throttled because they are unauthenticated and cached hard, since the content changes about once
 * a year and a reviewer's crawler should never be the thing that is slow.
 */
Route::middleware(['web', 'throttle:60,1'])->group(function () {
    Route::get('/privacy', [\App\Core\Legal\LegalController::class, 'privacy'])->name('legal.privacy');
    Route::get('/data-deletion', [\App\Core\Legal\LegalController::class, 'dataDeletion'])->name('legal.data-deletion');
});

// ── PUBLIC RECRUITMENT PORTAL (no auth) ───
// External candidates apply via a unique token URL — no authentication required.
// ── DATABASE-STORED FILES (streams tenant_files bytes; RLS-scoped to the tenant) ─
Route::middleware(['web', 'auth'])->get('/dbfiles/{disk}/{path}', [\App\Core\Storage\StoredFileController::class, 'show'])
    ->where('path', '.*')->name('dbfiles.show');

Route::middleware(['web', 'throttle:30,5'])->group(function () {
    Route::get('/apply/{token}', [PublicApplicationController::class, 'show'])
        ->name('recruitment.public.apply');
    Route::post('/apply/{token}', [PublicApplicationController::class, 'store'])
        ->middleware('throttle:10,5') // unauthenticated 10MB-upload endpoint — keep tight
        ->name('recruitment.public.apply.store');
    Route::get('/apply/{token}/success', [PublicApplicationController::class, 'success'])
        ->name('recruitment.public.success');
});

// ── PUBLIC FINANCE "PAY NOW" PAGE (no auth — the invoice pay_token is the credential) ───
Route::middleware(['web', 'throttle:40,1'])->prefix('finance/pay')->name('finance.pay.')->group(function () {
    Route::get('/{token}',        [\App\Modules\Finance\Accounting\Http\Controllers\PaymentPortalController::class, 'show'])->name('show');
    Route::post('/{token}/notify', [\App\Modules\Finance\Accounting\Http\Controllers\PaymentPortalController::class, 'notifyPaid'])->name('notify');
});

// ── PUBLIC CRM CAMPAIGN TRACKING (no auth — loaded by mail clients/browsers) ───
Route::middleware(['web'])->group(function () {
    Route::get('/crm/t/o/{token}', [\App\Modules\CRM\Http\Controllers\CampaignTrackingController::class, 'open'])->name('crm.track.open');
    Route::get('/crm/t/c/{token}', [\App\Modules\CRM\Http\Controllers\CampaignTrackingController::class, 'click'])->name('crm.track.click');
    // GET asks, POST performs — the CRM's own copy of the same link, with the same protection.
    Route::match(['GET', 'POST'], '/crm/u/{token}', [\App\Modules\CRM\Http\Controllers\CampaignTrackingController::class, 'unsubscribe'])->name('crm.unsubscribe');

    // Deliverability events (bounce / spam / blocked / unsubscribe) from the email
    // provider. Guarded by CRM_EMAIL_WEBHOOK_SECRET, not user auth. {provider} is
    // generic|mailgun|sendgrid|postmark.
    Route::post('/crm/webhooks/email/{provider}', [\App\Modules\CRM\Http\Controllers\EmailEventWebhookController::class, 'handle'])
        ->name('crm.webhooks.email');
});

// ── LICENSING AUTHORITY (vendor-hosted instance only; guarded by shared secret) ─
Route::middleware(['web'])->group(function () {
    Route::post('/license/validate', [\App\Core\License\LicenseAuthorityController::class, 'validateInstall'])->name('license.validate');
});

// ── PAYMENT GATEWAY NOTIFY (server-to-server; verified by signature, no auth) ───
Route::middleware(['web'])->post('/pay/notify/{code}', [\App\Modules\Tenancy\Http\Controllers\PaymentController::class, 'notify'])->name('tenancy.pay.notify');

// ── PUBLIC CUSTOMER FEEDBACK PORTAL (no auth) ──────────────────────────────────
Route::middleware(['web'])->group(function () {
    Route::get('/feedback',  [\App\Modules\CRM\Http\Controllers\ComplaintPortalController::class, 'show'])->name('crm.portal.feedback');
    Route::post('/feedback', [\App\Modules\CRM\Http\Controllers\ComplaintPortalController::class, 'store'])->name('crm.portal.feedback.store');

    // Public customer survey response (token = the credential)
    Route::get('/survey/{token}',  [\App\Modules\CRM\Http\Controllers\SurveyResponseController::class, 'take'])->name('crm.survey.take');
    Route::post('/survey/{token}', [\App\Modules\CRM\Http\Controllers\SurveyResponseController::class, 'submit'])->name('crm.survey.submit');
});

// ── PUBLIC SUPPLIER PORTAL (no auth — portal_token IS the credential) ───
require __DIR__.'/modules/supplier-portal.php';

// ── PUBLIC BID SUBMISSION PORTAL (no auth — the invitation token IS the credential) ───
require __DIR__.'/modules/procurement-bid-portal.php';

// ── PUBLIC PM PORTAL INVITATION (no auth — the invitation token IS the credential) ───
require __DIR__.'/modules/project-management-public.php';

// ── PAYROLL BUREAU PAYSLIP PORTAL (no auth — the emailed token IS the credential) ─────
require __DIR__.'/modules/payroll-payslip-portal.php';

// ── PUBLIC SHARED DOCUMENTS (no auth — the share token IS the credential) ──────────────
require __DIR__.'/modules/document-management-public.php';

// ── E-SIGN SIGNING PORTAL (no auth — the emailed token IS the credential) ─────────────
require __DIR__.'/modules/esign-portal.php';

// ── CANDIDATE OFFER + CONTRACT SIGNING PORTAL (no auth — the emailed token IS the credential) ──
require __DIR__.'/modules/offers-portal.php';

// ── CONTRACTOR / WORKER PASS VERIFICATION (no auth — the QR token IS the credential) ──────────
require __DIR__.'/modules/sheq-public.php';


// ── LANGUAGE SWITCH (works for guests too — public portals/support pages) ──────
// Persists to the user record when logged in, otherwise just to the session so
// unauthenticated visitors can switch language without being bounced to login.
Route::middleware(['web'])->patch('/user/locale', function (\Illuminate\Http\Request $request) {
    $supported = ['en', 'af', 'zu', 'xh', 'nso', 'st', 'tn', 'ss', 've', 'ts', 'nr', 'fr'];
    $locale = $request->validate(['locale' => ['required', 'string', \Illuminate\Validation\Rule::in($supported)]])['locale'];

    // Persist for server-side use (emails, notifications) — logged-in users
    // get it remembered, guests keep it for the session. Fire-and-forget:
    // the UI has already switched client-side, so return 204 (no reload).
    if ($user = $request->user()) {
        $user->update(['locale' => $locale]);
    }
    $request->session()->put('locale', $locale);

    return response()->noContent();
})->name('user.locale');

// ── AUTHENTICATED ROUTES ────────────────────
// Mkhaya Agri phone app: service worker + manifest, public and data-free.
require __DIR__.'/modules/agri-pwa.php';
// Mkhaya Agri consumer trace page (QR code on the pack): public, no login.
require __DIR__.'/modules/agri-public.php';

Route::middleware(['auth', 'auth.lock', 'check.banned', 'prevent-back-history', 'crud.permissions'])->group(function () {

    Route::get('/', [DashbaordController::class, 'index'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // ── Notifications ──────────────────────────────────────────────────────
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::post('/{id}/read', function (string $id) {
            auth()->user()->notifications()->where('id', $id)->update(['read_at' => now()]);
            return redirect()->back();
        })->name('read');
        Route::post('/read-all', function () {
            auth()->user()->unreadNotifications->markAsRead();
            return redirect()->back();
        })->name('read-all');
    });

    Route::middleware('auth')->prefix('ai-copilot')->group(function () {
        Route::post('/chat', [AICopilotController::class, 'chat']);
        Route::get('/sessions', [AICopilotController::class, 'sessions']);
        Route::get('/history/{sessionId}', [AICopilotController::class, 'history']);
        Route::post('/new-session', [AICopilotController::class, 'newSession']);
        Route::delete('/sessions/{sessionId}', [AICopilotController::class, 'deleteSession']);
        Route::get('/quick-stats', [AICopilotController::class, 'quickStats']);
    });


    require __DIR__.'/core/category.php';
    require __DIR__.'/core/module.php';
    require __DIR__.'/core/ribbon.php';
    require __DIR__.'/core/profile.php';
    require __DIR__.'/core/howTo.php';

    require __DIR__.'/modules/content-management.php';
    require __DIR__.'/modules/resources.php';
    require __DIR__.'/modules/policies.php';
    require __DIR__.'/modules/recruitment.php';
    require __DIR__.'/modules/offers.php';
    require __DIR__.'/modules/preboarding.php';
    require __DIR__.'/modules/assets.php';
    require __DIR__.'/modules/fleet.php';
    require __DIR__.'/modules/hr.php';
    require __DIR__.'/modules/attendance.php';
    require __DIR__.'/modules/performance.php';
    require __DIR__.'/modules/payroll.php';
    require __DIR__.'/modules/disciplinary.php';
    require __DIR__.'/modules/finance-accounting.php';
    require __DIR__.'/modules/claims.php';
    require __DIR__.'/modules/petty-cash.php';
    require __DIR__.'/modules/staff-loans.php';
    require __DIR__.'/modules/compliance.php';
    require __DIR__.'/modules/lms.php';
    require __DIR__.'/modules/offboarding.php';
    require __DIR__.'/modules/esign.php';
    require __DIR__.'/modules/supplier-management.php';
    require __DIR__.'/modules/contract-management.php';
    require __DIR__.'/modules/budget.php';
    require __DIR__.'/modules/procurement.php';
    // Procurement Governance / ESPPRA — planning, tender evaluation, deviations, reporting,
    // workload and the universal audit trail. Loaded after procurement so its routes sit beside
    // the P2P flow they extend.
    require __DIR__.'/modules/procurement-governance.php';
    require __DIR__.'/modules/crm.php';
    require __DIR__.'/modules/project-management.php';

    // Operations & Support — SHEQ (Safety, Health, Environment, Quality)
    require __DIR__.'/modules/sheq.php';
    require __DIR__.'/modules/document-management.php';

    // Operations & Support — Manufacturing & Production (MFG-001/002)
    require __DIR__.'/modules/manufacturing.php';

    // Operations & Support — Warehouse Management & Intelligence
    require __DIR__.'/modules/warehouse.php';

    // Finance — Tax Management & Compliance
    require __DIR__.'/modules/tax.php';

    // Workflow & Automation — the central engine
    require __DIR__.'/modules/workflow.php';

    // Mkhaya Agri — farms, fields and (by phase) crops, livestock and farm operations.
    require __DIR__.'/modules/agri.php';

    // Organisations a person belongs to, switching between them, and adding members from other
    // organisations (company_memberships). Each action checks its own authority.
    Route::get('/account/organisations', [\App\Modules\Tenancy\Http\Controllers\OrganisationController::class, 'index'])->name('organisations.index');
    Route::post('/account/organisations/{company}/switch', [\App\Modules\Tenancy\Http\Controllers\OrganisationController::class, 'switch'])
        ->whereNumber('company')->name('organisations.switch');
    Route::post('/account/organisations/members', [\App\Modules\Tenancy\Http\Controllers\OrganisationController::class, 'invite'])->name('organisations.members.store');
    Route::patch('/account/organisations/members/{membership}', [\App\Modules\Tenancy\Http\Controllers\OrganisationController::class, 'update'])->name('organisations.members.update');

    // Hub landings for modules that don't register their own (see the file's header).
    require __DIR__.'/modules/hubs.php';

    // Platform super-admin — tenant management (top-level, not under settings/).
    require __DIR__.'/settings/tenancy.php';

    // Settings group (all sub-routes are inside settings/)
    Route::prefix('settings')->name('settings.')->group(function () {
        require __DIR__.'/settings/holidays.php';
        require __DIR__.'/settings/user-access.php';
        require __DIR__.'/settings/audit.php';
        require __DIR__.'/settings/roles.php';
        require __DIR__.'/settings/permissions.php';
        require __DIR__.'/settings/document-types.php';
        require __DIR__.'/settings/organization.php';
        require __DIR__.'/settings/position.php';
        require __DIR__.'/settings/restoration.php';
        require __DIR__.'/settings/platform-admin.php';
        require __DIR__.'/settings/theme.php';
        require __DIR__.'/settings/offline-policy.php';
        require __DIR__.'/settings/bi-export.php';
    });

    // 404 fallback (must be last)
    Route::fallback(function () {
        return Inertia::render('Pages/Found/404');
    });
});

// ── SHARED CUSTOMER PROFILE ────────────────────────────────────────────────────
// Deliberately not gated on any module: the customer record is shared, and the
// page shows only the sections the tenant's active modules provide.
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/customers', [\App\Modules\Shared\Party\CustomerProfileController::class, 'index'])
        ->name('customers.index');
    Route::get('/customers/{uuid}', [\App\Modules\Shared\Party\CustomerProfileController::class, 'show'])
        ->name('customers.show');
});

/*
 * Single sign-on (§9.1).
 *
 * Outside the auth middleware by necessity — these are how a person becomes authenticated. The
 * `state` parameter, held server-side and single-use, is what stands in for CSRF protection on the
 * callback, which arrives from the identity provider rather than from our own form.
 */
Route::post('/auth/sso/discover', [\App\Core\Auth\Controllers\SsoController::class, 'discover'])
    ->name('sso.discover');
Route::get('/auth/sso/redirect', [\App\Core\Auth\Controllers\SsoController::class, 'redirect'])
    ->name('sso.redirect');
Route::get('/auth/sso/callback', [\App\Core\Auth\Controllers\SsoController::class, 'callback'])
    ->name('sso.callback');

/*
 * The new hire's own onboarding page, before they have a login.
 *
 * Unauthenticated because the token in the URL is the credential — the same arrangement as esign
 * and the helpdesk portal. Throttled tightly for that reason: an unauthenticated route that
 * resolves a token is a route somebody can guess at, and the upload endpoint accepts 10MB.
 */
Route::middleware(['web', 'throttle:30,5'])->prefix('onboarding/portal')->name('onboarding.portal.')->group(function () {
    Route::get('/{token}', [\App\Modules\HR\Onboarding\Http\Controllers\NewHirePortalController::class, 'show'])->name('show');
    Route::post('/{token}/documents', [\App\Modules\HR\Onboarding\Http\Controllers\NewHirePortalController::class, 'upload'])
        ->middleware('throttle:10,5')->name('documents.upload');
});
