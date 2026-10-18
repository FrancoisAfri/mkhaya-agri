<?php

use App\Modules\SupplierManagement\Controllers\SupplierAppraisalController;
use App\Modules\SupplierManagement\Controllers\SupplierBbbeeController;
use App\Modules\SupplierManagement\Controllers\SupplierController;
use App\Modules\SupplierManagement\Controllers\SupplierDashboardController;
use App\Modules\SupplierManagement\Controllers\SupplierDataSourceController;
use App\Modules\SupplierManagement\Controllers\SupplierKycController;
use App\Modules\SupplierManagement\Controllers\SupplierAnalyticsController;
use App\Modules\SupplierManagement\Controllers\SupplierOnboardingController;
use App\Modules\SupplierManagement\Controllers\SupplierReportController;
use App\Modules\SupplierManagement\Controllers\SupplierScheduledReportController;
use App\Modules\SupplierManagement\Controllers\PurchaseOrderController;
use App\Modules\SupplierManagement\Controllers\RfxController;
use App\Modules\SupplierManagement\Controllers\SpendReportController;
use App\Modules\SupplierManagement\Controllers\SupplierInvoiceController;
use App\Modules\SupplierManagement\Controllers\SupplierScarController;
use App\Modules\SupplierManagement\Controllers\SupplierScorecardController;
use App\Modules\SupplierManagement\Controllers\SupplierScreeningController;
use App\Modules\SupplierManagement\Controllers\SupplierSearchController;
use App\Modules\SupplierManagement\Controllers\SupplierSegmentController;
use App\Modules\SupplierManagement\Controllers\SupplierSettingsController;
use App\Modules\SupplierManagement\Controllers\SupplierOwnershipController;
use App\Modules\SupplierManagement\Controllers\SupplierCaseController;
use App\Modules\SupplierManagement\Controllers\SupplierMonitoringController;
use App\Modules\SupplierManagement\Controllers\SupplierPassportController;
use App\Modules\SupplierManagement\Controllers\SupplierQuestionnaireController;
use App\Modules\SupplierManagement\Controllers\SupplierReputationController;
use App\Modules\SupplierManagement\Controllers\SupplierRiskController;
use App\Modules\SupplierManagement\Controllers\SupplierVerificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('module.active:supplier_management')->prefix('suppliers')->name('suppliers.')->group(function () {

    // ── Dashboard ──────────────────────────────────────────────────────────────
    Route::get('/', [SupplierDashboardController::class, 'index'])->name('dashboard');

    // ── Onboarding ─────────────────────────────────────────────────────────────
    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::get('/',                [SupplierOnboardingController::class, 'index'])->name('index');
        Route::get('/create',          [SupplierOnboardingController::class, 'create'])->name('create');
        Route::post('/',               [SupplierOnboardingController::class, 'store'])->name('store');
        Route::post('/{id}/send-mail', [SupplierOnboardingController::class, 'sendMail'])->name('send-mail')->whereNumber('id');
        Route::get('/{id}/edit',       [SupplierOnboardingController::class, 'edit'])->name('edit')->whereNumber('id');
        Route::patch('/{id}',          [SupplierOnboardingController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}',         [SupplierOnboardingController::class, 'destroy'])->name('destroy')->whereNumber('id');
    });

    // ── KYC approvals ──────────────────────────────────────────────────────────
    Route::prefix('kyc')->name('kyc.')->group(function () {
        Route::get('/',              [SupplierKycController::class, 'index'])->name('index');
        Route::post('/{id}/approve', [SupplierKycController::class, 'approve'])->name('approve')->whereNumber('id');
        Route::post('/{id}/reject',  [SupplierKycController::class, 'reject'])->name('reject')->whereNumber('id');
    });

    // ── Appraisals ─────────────────────────────────────────────────────────────
    /*
     * Designing a scorecard and filling one in are different tasks, done by different people at
     * different times. They used to share a page, which is why nothing could be edited afterwards:
     * a form that creates something it cannot change is a one-way door.
     *
     * Static segments are declared before `/{appraisal}`, or `templates` would be read as a uuid.
     */
    Route::prefix('appraisals')->name('appraisals.')->group(function () {
        Route::get('/', [SupplierAppraisalController::class, 'index'])->name('index');

        // ── The scorecards ──
        Route::get('/templates', [SupplierAppraisalController::class, 'templates'])->name('templates');
        Route::post('/templates', [SupplierAppraisalController::class, 'templatesStore'])->name('templates.store');
        Route::get('/templates/{template}', [SupplierAppraisalController::class, 'template'])->name('templates.show')->whereUuid('template');
        Route::patch('/templates/{template}', [SupplierAppraisalController::class, 'templatesUpdate'])->name('templates.update')->whereUuid('template');
        Route::delete('/templates/{template}', [SupplierAppraisalController::class, 'templatesDestroy'])->name('templates.destroy')->whereUuid('template');

        // ── The questions on one scorecard ──
        Route::post('/templates/{template}/questions', [SupplierAppraisalController::class, 'questionStore'])->name('questions.store')->whereUuid('template');
        Route::patch('/templates/{template}/questions/{question}', [SupplierAppraisalController::class, 'questionUpdate'])->name('questions.update')->whereUuid('template')->whereUuid('question');
        Route::delete('/templates/{template}/questions/{question}', [SupplierAppraisalController::class, 'questionDestroy'])->name('questions.destroy')->whereUuid('template')->whereUuid('question');

        // ── Filling one in ──
        Route::get('/{supplier}/appraise', [SupplierAppraisalController::class, 'appraiseForm'])->name('appraise')->whereUuid('supplier');
        Route::post('/{supplier}/appraise', [SupplierAppraisalController::class, 'store'])->name('store')->whereUuid('supplier');

        // Last: a bare uuid segment would otherwise swallow every static path above.
        Route::get('/{appraisal}', [SupplierAppraisalController::class, 'show'])->name('show')->whereUuid('appraisal');
    });

    // ── Search & discovery ─────────────────────────────────────────────────────
    Route::get('/search',          [SupplierSearchController::class, 'index'])->name('search');
    Route::get('/search/advanced', [SupplierSearchController::class, 'advanced'])->name('search.advanced');

    // ── SCARs (Supplier Corrective Action Requests) ─────────────────────────────
    Route::prefix('scars')->name('scars.')->group(function () {
        Route::get('/',          [SupplierScarController::class, 'index'])->name('index');
        Route::post('/',         [SupplierScarController::class, 'store'])->name('store');
        Route::patch('/{scar}',  [SupplierScarController::class, 'update'])->name('update')->whereNumber('scar');
        Route::delete('/{scar}', [SupplierScarController::class, 'destroy'])->name('destroy')->whereNumber('scar');
    });

    // ── Document AI expiry detection ────────────────────────────────────────────
    Route::post('/documents/{document}/detect-expiry', [SupplierController::class, 'documentDetectExpiry'])->name('documents.detect-expiry')->whereNumber('document');

    // ── Sourcing / RFx ──────────────────────────────────────────────────────────
    Route::prefix('sourcing')->name('sourcing.')->group(function () {
        Route::get('/',        [RfxController::class, 'index'])->name('index');
        Route::get('/create',  [RfxController::class, 'create'])->name('create');
        Route::post('/',       [RfxController::class, 'store'])->name('store');
        Route::get('/{rfx}',           [RfxController::class, 'show'])->name('show')->whereNumber('rfx');
        Route::post('/{rfx}/publish',  [RfxController::class, 'publish'])->name('publish')->whereNumber('rfx');
        Route::post('/{rfx}/close',    [RfxController::class, 'close'])->name('close')->whereNumber('rfx');
        Route::post('/{rfx}/evaluate', [RfxController::class, 'evaluate'])->name('evaluate')->whereNumber('rfx');
        Route::post('/{rfx}/award',    [RfxController::class, 'award'])->name('award')->whereNumber('rfx');
        Route::post('/{rfx}/responses', [RfxController::class, 'recordResponse'])->name('responses')->whereNumber('rfx');
    });

    // ── Purchase Orders ─────────────────────────────────────────────────────────
    Route::prefix('purchase-orders')->name('purchase-orders.')->group(function () {
        Route::get('/',                   [PurchaseOrderController::class, 'index'])->name('index');
        Route::get('/create',             [PurchaseOrderController::class, 'create'])->name('create');
        Route::post('/',                  [PurchaseOrderController::class, 'store'])->name('store');
        Route::patch('/{purchaseOrder}',  [PurchaseOrderController::class, 'update'])->name('update')->whereUuid('purchaseOrder');
        Route::delete('/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('destroy')->whereUuid('purchaseOrder');
    });

    // ── Supplier Invoices ───────────────────────────────────────────────────────
    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/',             [SupplierInvoiceController::class, 'index'])->name('index');
        Route::post('/',            [SupplierInvoiceController::class, 'store'])->name('store');
        Route::patch('/{invoice}',  [SupplierInvoiceController::class, 'update'])->name('update')->whereNumber('invoice');
        Route::delete('/{invoice}', [SupplierInvoiceController::class, 'destroy'])->name('destroy')->whereNumber('invoice');
    });

    // ── Spend analysis / off-contract leakage ──────────────────────────────────
    Route::get('/spend/report', [SpendReportController::class, 'index'])->name('spend.report');

    // ── Reports, Analytics & Scheduled Reports ──────────────────────────────────
    Route::get('/reports',                       [SupplierReportController::class, 'index'])->name('reports');
    Route::get('/reports/export/{key}/{format}', [SupplierReportController::class, 'export'])->name('reports.export');
    Route::get('/analytics',                     [SupplierAnalyticsController::class, 'index'])->name('analytics');
    Route::prefix('scheduled-reports')->name('scheduled-reports.')->group(function () {
        Route::get('/',                    [SupplierScheduledReportController::class, 'index'])->name('index');
        Route::post('/',                   [SupplierScheduledReportController::class, 'store'])->name('store');
        Route::patch('/{scheduledReport}', [SupplierScheduledReportController::class, 'update'])->name('update')->whereNumber('scheduledReport');
        Route::delete('/{scheduledReport}', [SupplierScheduledReportController::class, 'destroy'])->name('destroy')->whereNumber('scheduledReport');
    });


    // ── Supplier Intelligence & Due Diligence (paid add-on) ─────────────────────
    //
    // Everything behind this gate is the add-on. A tenant without it keeps the module exactly as it
    // was: directory, onboarding, KYC, appraisals and sourcing all work, and none of this is
    // half-present. Grouped in one place rather than sprinkled through the file so the boundary is
    // one thing somebody can see, rather than a property of nine separate route definitions.
    // `crud.permissions` is not decoration here. EnforceCrudPermissions derives the permission it
    // demands from the ROUTE NAME, so `suppliers.passport` asks for a `suppliers.passport`
    // permission — a name that exists nowhere and is granted to nobody. Every screen added by this
    // add-on would 403 for every real user on every installation, while the test suite passed
    // because its fixture grants whatever the router derives.
    //
    // So these routes are pinned to the permissions the module already issues: reading needs
    // `suppliers.view`, writing needs `suppliers.update`, and the three governance actions
    // (blacklisting, role mapping, alert routing) additionally authorise `approve` in the
    // controller. Entitlement to the add-on is a separate question, answered by module.active.
    // `withoutMiddleware` matters as much as the middleware itself. web.php wraps every module in a
    // bare `crud.permissions`, which derives the permission from the route name — so
    // `suppliers.passport` demands a `suppliers.passport` permission that exists nowhere and is
    // granted to nobody. Adding an explicit one alongside does not help: the bare instance runs
    // first and refuses. So the derived check is dropped for this group and replaced with an
    // explicit one.
    Route::withoutMiddleware('crud.permissions')
        ->middleware(['module.active:supplier_due_diligence', 'crud.permissions:suppliers.update'])
        ->group(function () {
    // ── Due diligence verifications ─────────────────────────────────────────────
    // Running a check against a configured source, and recording one a person performed at the
    // source themselves. Both write the same observation and the same evidence entry.
    Route::post('/{supplier}/verifications/run',    [SupplierVerificationController::class, 'run'])->name('verifications.run')->whereUuid('supplier');
    Route::post('/{supplier}/verifications/record', [SupplierVerificationController::class, 'record'])->name('verifications.record')->whereUuid('supplier');
    Route::post('/{supplier}/tcs-pin',              [SupplierVerificationController::class, 'storeTcsPin'])->name('tcs-pin.store')->whereUuid('supplier');

    // ── Risk assessment & screening ─────────────────────────────────────────────
    // Assessing writes a snapshot; it never changes the supplier's approval state on its own.
    Route::post('/{supplier}/risk/assess',   [SupplierRiskController::class, 'assess'])->name('risk.assess')->whereUuid('supplier');
    Route::post('/{supplier}/risk/tier',     [SupplierRiskController::class, 'tier'])->name('risk.tier')->whereUuid('supplier');
    Route::post('/{supplier}/risk/screen',   [SupplierRiskController::class, 'screen'])->name('risk.screen')->whereUuid('supplier');
    Route::post('/{supplier}/risk/hits',     [SupplierRiskController::class, 'recordHit'])->name('risk.hits.store')->whereUuid('supplier');
    Route::patch('/{supplier}/risk/hits/{hit}', [SupplierRiskController::class, 'adjudicate'])->name('risk.hits.adjudicate')->whereNumber('hit')->whereUuid('supplier');
    Route::post('/{supplier}/risk/credit',   [SupplierRiskController::class, 'storeCreditReport'])->name('risk.credit.store')->whereUuid('supplier');

    // ── Questionnaires and the written summary ──────────────────────────────────
    // The checks no registry can answer, and the page somebody reads before the meeting.
    Route::post('/{supplier}/questionnaires',                         [SupplierQuestionnaireController::class, 'send'])->name('questionnaires.send')->whereUuid('supplier');
    Route::patch('/{supplier}/questionnaires/{questionnaire}',        [SupplierQuestionnaireController::class, 'record'])->name('questionnaires.record')->whereNumber('questionnaire')->whereUuid('supplier');
    Route::post('/{supplier}/questionnaires/{questionnaire}/review',  [SupplierQuestionnaireController::class, 'review'])->name('questionnaires.review')->whereNumber('questionnaire')->whereUuid('supplier');
    Route::get('/{supplier}/summary',                                 [SupplierQuestionnaireController::class, 'summary'])->name('summary')->middleware('crud.permissions:suppliers.view')->whereUuid('supplier');

    // ── Monitoring inbox ────────────────────────────────────────────────────────
    // Everything nobody has looked at, across every supplier. Without one place for it, finding an
    // alert requires already knowing which supplier to open — so the ones that matter are the ones
    // on suppliers nobody thought to check.
    Route::get('/monitoring',                    [SupplierMonitoringController::class, 'index'])->name('monitoring.index')->middleware('crud.permissions:suppliers.view');
    Route::get('/monitoring/report',             [SupplierMonitoringController::class, 'report'])->name('monitoring.report')->middleware('crud.permissions:suppliers.view');
    Route::patch('/monitoring/changes/{change}', [SupplierMonitoringController::class, 'reviewChange'])->name('monitoring.changes.review')->whereNumber('change');
    Route::post('/{supplier}/monitoring/sweep',  [SupplierMonitoringController::class, 'sweep'])->name('monitoring.sweep')->whereUuid('supplier');

    // ── The Passport, and the case behind it ────────────────────────────────────
    // One screen that answers "should we be trading with this company?" — the detail lives in the
    // panels, and a manager with four minutes does not open five of them.
    Route::get('/{supplier}/passport',            [SupplierPassportController::class, 'show'])->name('passport')->middleware('crud.permissions:suppliers.view')->whereUuid('supplier');
    Route::post('/{supplier}/case',               [SupplierCaseController::class, 'open'])->name('cases.open')->whereUuid('supplier');
    Route::post('/{supplier}/case/{case}/move',   [SupplierCaseController::class, 'transition'])->name('cases.transition')->whereNumber('case')->whereUuid('supplier');
    Route::patch('/{supplier}/case/edd/{task}',   [SupplierCaseController::class, 'settleEdd'])->name('cases.edd')->whereNumber('task')->whereUuid('supplier');
    Route::post('/{supplier}/blacklist',          [SupplierCaseController::class, 'blacklist'])->name('cases.blacklist')->whereUuid('supplier');
    Route::patch('/blacklist/{entry}/lift',       [SupplierCaseController::class, 'liftBlacklist'])->name('cases.blacklist.lift')->whereNumber('entry');

    // ── Reputation & social monitoring ──────────────────────────────────────────
    // Runs during onboarding as readily as after approval: the reputation of a company you are about
    // to engage is exactly as relevant as one you already have.
    Route::post('/{supplier}/reputation/run',              [SupplierReputationController::class, 'run'])->name('reputation.run')->whereUuid('supplier');
    Route::patch('/{supplier}/reputation/findings/{finding}', [SupplierReputationController::class, 'adjudicate'])->name('reputation.findings.adjudicate')->whereNumber('finding')->whereUuid('supplier');
    Route::post('/{supplier}/reputation/handles',          [SupplierReputationController::class, 'storeHandle'])->name('reputation.handles.store')->whereUuid('supplier');
    Route::delete('/{supplier}/reputation/handles/{handle}', [SupplierReputationController::class, 'destroyHandle'])->name('reputation.handles.destroy')->whereNumber('handle')->whereUuid('supplier');

    // ── Ownership chain ─────────────────────────────────────────────────────────
    // Stakes are ended rather than deleted: who owned this when the contract was awarded has to
    // stay answerable.
    Route::post('/{supplier}/ownership',              [SupplierOwnershipController::class, 'store'])->name('ownership.store')->whereUuid('supplier');
    Route::patch('/{supplier}/ownership/{ownership}', [SupplierOwnershipController::class, 'update'])->name('ownership.update')->whereNumber('ownership')->whereUuid('supplier');
    Route::post('/{supplier}/ownership/compare',      [SupplierOwnershipController::class, 'compare'])->name('ownership.compare')->whereUuid('supplier');

        Route::prefix('settings')->name('settings.')->group(function () {
        // ── Due diligence data sources ─────────────────────────────────────────
        // Which verification sources this company can use, and which checks nobody can answer yet.
        Route::get('/data-sources',                      [SupplierDataSourceController::class, 'index'])->name('data-sources.index')->middleware('crud.permissions:suppliers.view');
        Route::put('/data-sources/{code}',               [SupplierDataSourceController::class, 'update'])->name('data-sources.update');
        Route::delete('/data-sources/{code}/credential', [SupplierDataSourceController::class, 'forgetCredential'])->name('data-sources.credential.destroy');

        // Who signs off what, mapped to this tenant's own people.
        Route::post('/due-diligence-roles', [SupplierCaseController::class, 'saveRoles'])->name('dd-roles.save');
        // Who hears about what, by severity.
        Route::post('/alert-routing',       [SupplierQuestionnaireController::class, 'saveRouting'])->name('alert-routing.save');
        });
    });

    // ── Settings ───────────────────────────────────────────────────────────────
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/',           [SupplierSettingsController::class, 'index'])->name('index');
        Route::post('/kyc-toggle', [SupplierSettingsController::class, 'toggleKyc'])->name('kyc.toggle');

        Route::post('/document-types',                  [SupplierSettingsController::class, 'documentTypeStore'])->name('document-types.store');
        Route::patch('/document-types/{documentType}',  [SupplierSettingsController::class, 'documentTypeUpdate'])->name('document-types.update');
        Route::delete('/document-types/{documentType}', [SupplierSettingsController::class, 'documentTypeDestroy'])->name('document-types.destroy');
        Route::get('/document-types/{documentType}/template', [SupplierSettingsController::class, 'documentTypeTemplate'])->name('document-types.template')->whereNumber('documentType');

        Route::post('/industry-types',                  [SupplierSettingsController::class, 'industryTypeStore'])->name('industry-types.store');
        Route::patch('/industry-types/{industryType}',  [SupplierSettingsController::class, 'industryTypeUpdate'])->name('industry-types.update');
        Route::delete('/industry-types/{industryType}', [SupplierSettingsController::class, 'industryTypeDestroy'])->name('industry-types.destroy');

        Route::post('/categories',             [SupplierSettingsController::class, 'categoryStore'])->name('categories.store');
        Route::patch('/categories/{category}', [SupplierSettingsController::class, 'categoryUpdate'])->name('categories.update');
        Route::delete('/categories/{category}', [SupplierSettingsController::class, 'categoryDestroy'])->name('categories.destroy');

    });

    // ── Suppliers (static segments before /{supplier}) ──────────────────────────
    Route::get('/list',         [SupplierController::class, 'index'])->name('index');
    Route::get('/create',       [SupplierController::class, 'create'])->name('create');
    Route::post('/',            [SupplierController::class, 'store'])->name('store');
    Route::get('/trash',        [SupplierController::class, 'trash'])->name('trash');
    Route::post('/{id}/restore', [SupplierController::class, 'restore'])->name('restore')->whereNumber('id');
    Route::delete('/{id}/force', [SupplierController::class, 'forceDelete'])->name('force-delete')->whereNumber('id');

    // ── Risk & fraud flags ──────────────────────────────────────────────────────
    Route::post('/risk-flags/{flag}/resolve', [SupplierController::class, 'resolveRiskFlag'])->name('risk-flags.resolve')->whereNumber('flag');

    Route::get('/{supplier}/scorecard', [SupplierScorecardController::class, 'show'])->name('scorecard')->whereUuid('supplier');

    Route::get('/{supplier}',    [SupplierController::class, 'show'])->name('show')->whereUuid('supplier');
    Route::patch('/{supplier}',  [SupplierController::class, 'update'])->name('update')->whereUuid('supplier');
    Route::delete('/{supplier}', [SupplierController::class, 'destroy'])->name('destroy')->whereUuid('supplier');

    Route::post('/{supplier}/activate',     [SupplierController::class, 'activate'])->name('activate')->whereUuid('supplier');
    Route::post('/{supplier}/refuse',       [SupplierController::class, 'refuse'])->name('refuse')->whereUuid('supplier');
    Route::post('/{supplier}/request-info', [SupplierController::class, 'requestInfo'])->name('request-info')->whereUuid('supplier');
    // Revoking the supplier's portal link. The token is their only credential, so replacing it has
    // to be reachable from the screen rather than only from the database.
    Route::post('/{supplier}/portal-token', [SupplierController::class, 'rotatePortalToken'])->name('portal-token.rotate')->whereUuid('supplier');

    // ── Risk intelligence, B-BBEE, segmentation, hierarchy ──────────────────────
    Route::post('/{supplier}/screen',   [SupplierScreeningController::class, 'screen'])->name('screen')->whereUuid('supplier');
    Route::post('/{supplier}/bbbee',    [SupplierBbbeeController::class, 'upsert'])->name('bbbee.upsert')->whereUuid('supplier');
    Route::patch('/{supplier}/segment', [SupplierSegmentController::class, 'update'])->name('segment.update')->whereUuid('supplier');
    Route::patch('/{supplier}/parent',  [SupplierController::class, 'setParent'])->name('parent.update')->whereUuid('supplier');

    // Contacts
    Route::post('/{supplier}/contacts',            [SupplierController::class, 'contactStore'])->name('contacts.store')->whereUuid('supplier');
    Route::patch('/{supplier}/contacts/{contact}', [SupplierController::class, 'contactUpdate'])->name('contacts.update')->whereUuid('supplier');
    Route::delete('/{supplier}/contacts/{contact}', [SupplierController::class, 'contactDestroy'])->name('contacts.destroy')->whereUuid('supplier');

    // Directors
    Route::post('/{supplier}/directors',             [SupplierController::class, 'directorStore'])->name('directors.store')->whereUuid('supplier');
    Route::patch('/{supplier}/directors/{director}', [SupplierController::class, 'directorUpdate'])->name('directors.update')->whereUuid('supplier');
    Route::delete('/{supplier}/directors/{director}', [SupplierController::class, 'directorDestroy'])->name('directors.destroy')->whereUuid('supplier');

    // Products & industries (sync)
    Route::post('/{supplier}/products',   [SupplierController::class, 'productsSync'])->name('products.sync')->whereUuid('supplier');
    Route::post('/{supplier}/industries', [SupplierController::class, 'industriesSync'])->name('industries.sync')->whereUuid('supplier');

    // Documents (stored in the database)
    Route::post('/{supplier}/documents', [SupplierController::class, 'documentStore'])->name('documents.store')->whereUuid('supplier');
    Route::get('/documents/{document}/download', [SupplierController::class, 'documentDownload'])->name('documents.download')->whereNumber('document');
});
