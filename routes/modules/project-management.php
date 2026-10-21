<?php

use App\Http\Controllers\ModuleHubController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\AgileController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\AnnouncementController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\ApprovalsController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\AssistantController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\BoardController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\ChatController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\ContractorBillingController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\ContractTemplateController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\ProjectContractController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\DashboardController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\DeliveryController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\DocumentsController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\EvidenceController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\FieldController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\InsightsController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\InviteController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\InvoicingController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\MonthlyReportingController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\LinkController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\FiguresController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\MeetingController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\PortalController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\PortfolioController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\RegisterController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\ProjectController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\ScheduleController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\SetupController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\SiteController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\SiteRecordController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\TaskCollaborationController;
use App\Modules\Operations\ProjectManagement\Http\Controllers\WorkController;
use Illuminate\Support\Facades\Route;

/*
 * Project Management (Operations & Support).
 *
 * Gated by the module entitlement (module.active) and by hub tier (module.tier), on top of the
 * per-action policy checks in the controllers. Tenant data is isolated by row-level security, so
 * an id from another tenant does not resolve here at all.
 *
 * Routes bind on the public UUID, never the internal id (PM-ADR-003).
 */
Route::middleware(['module.active:project_management', 'module.tier:project_management'])
    ->prefix('project-management')->name('project-management.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // ── Hub landings — role-filtered feature cards ─────────────────────────
        foreach (['workspace', 'insights', 'invoicing'] as $hub) {
            Route::get("/{$hub}", [ModuleHubController::class, 'show'])
                ->defaults('module', 'project_management')
                ->defaults('group', $hub)
                ->name('hub.' . $hub);
        }

        // ── Approvals: what is waiting on me, and what I am waiting on ────────
        Route::get('/approvals', [ApprovalsController::class, 'index'])->name('approvals.index');
        Route::post('/approvals/{uuid}/approve', [ApprovalsController::class, 'approve'])->name('approvals.approve');
        Route::post('/approvals/{uuid}/reject', [ApprovalsController::class, 'reject'])->name('approvals.reject');
        Route::post('/approvals/{uuid}/withdraw', [ApprovalsController::class, 'cancel'])->name('approvals.cancel');

        // ── Announcements: telling a whole project team something ─────────────
        Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::post('/announcements/{uuid}/read', [AnnouncementController::class, 'acknowledge'])->name('announcements.read');
        Route::delete('/announcements/{uuid}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

        // ── Portfolios, programmes and templates (§2, §3) ─────────────────────
        Route::get('/portfolio', [PortfolioController::class, 'index'])->name('portfolio');
        Route::post('/portfolios', [PortfolioController::class, 'storePortfolio'])->name('portfolios.store');
        Route::post('/programmes', [PortfolioController::class, 'storeProgramme'])->name('programmes.store');
        Route::post('/projects/{project}/assign', [PortfolioController::class, 'assign'])->name('projects.assign');

        Route::get('/setup/templates', [PortfolioController::class, 'templates'])->name('setup.templates');
        Route::post('/setup/templates/capture', [PortfolioController::class, 'captureTemplate'])->name('setup.templates.capture');
        Route::delete('/setup/templates/{uuid}', [PortfolioController::class, 'destroyTemplate'])->name('setup.templates.destroy');
        Route::post('/projects/{project}/template', [PortfolioController::class, 'applyTemplate'])->name('projects.template');

        // Stage gates give the project's `stage` field teeth.
        Route::post('/projects/{project}/gates', [PortfolioController::class, 'storeGate'])->name('gates.store');
        Route::post('/projects/{project}/gates/{uuid}/decide', [PortfolioController::class, 'decideGate'])->name('gates.decide');
        Route::delete('/projects/{project}/gates/{uuid}', [PortfolioController::class, 'destroyGate'])->name('gates.destroy');

        // ── Projects ──────────────────────────────────────────────────────────
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        // Client picker for the project form: search whichever system owns clients here, and add
        // one without leaving the page — otherwise the field is a dead end on a tenant with no CRM.
        Route::get('/clients/search', [ProjectController::class, 'clients'])->name('clients.search');
        Route::post('/clients', [ProjectController::class, 'storeClient'])->name('clients.store');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        // Restore takes a raw uuid rather than a bound model: the model is soft-deleted, so route
        // binding would 404 before the controller ran (PM-ADR-007).
        Route::post('/projects/{uuid}/restore', [ProjectController::class, 'restore'])->name('projects.restore');

        // ── Work: the breakdown structure and its tasks ───────────────────────
        Route::get('/projects/{project}/work', [WorkController::class, 'index'])->name('work');
        Route::post('/projects/{project}/wbs', [WorkController::class, 'storeNode'])->name('wbs.store');
        Route::patch('/projects/{project}/wbs/{uuid}/move', [WorkController::class, 'moveNode'])->name('wbs.move');
        Route::delete('/projects/{project}/wbs/{uuid}', [WorkController::class, 'destroyNode'])->name('wbs.destroy');
        Route::post('/projects/{project}/tasks', [WorkController::class, 'storeTask'])->name('tasks.store');
        Route::patch('/projects/{project}/tasks/bulk', [WorkController::class, 'bulkUpdateTasks'])->name('tasks.bulk');
        // Bulk creation from a spreadsheet. The template download sits beside the upload so the
        // user never has to go looking for the column names.
        Route::get('/projects/{project}/tasks/import/template', [WorkController::class, 'tasksImportTemplate'])->name('tasks.import.template');
        Route::post('/projects/{project}/tasks/import', [WorkController::class, 'tasksImport'])->name('tasks.import');
        Route::patch('/projects/{project}/tasks/{uuid}', [WorkController::class, 'updateTask'])->name('tasks.update');
        Route::delete('/projects/{project}/tasks/{uuid}', [WorkController::class, 'destroyTask'])->name('tasks.destroy');
        Route::post('/projects/{project}/dependencies', [WorkController::class, 'linkTasks'])->name('dependencies.store');
        Route::delete('/projects/{project}/dependencies/{uuid}', [WorkController::class, 'unlinkTasks'])->name('dependencies.destroy');

        // ── Insights: charts and BI exports ───────────────────────────────────
        Route::get('/insights/analytics', [InsightsController::class, 'analytics'])->name('insights.analytics');
        Route::get('/insights/reports', [InsightsController::class, 'reports'])->name('insights.reports');
        // The assistant (§25). Its warnings are deterministic queries, so the page works on a
        // workspace with no AI provider at all — only the drafting needs one.
        Route::get('/insights/assistant', [AssistantController::class, 'index'])->name('insights.assistant');
        Route::post('/projects/{project}/status-report', [AssistantController::class, 'statusReport'])->name('assistant.status');
        Route::post('/meetings/{uuid}/draft-minutes', [AssistantController::class, 'minutes'])->name('assistant.minutes');
        Route::get('/insights/reports/{key}/export', [InsightsController::class, 'export'])->name('insights.export');

        // ── The field shell (EPIC-060) ────────────────────────────────────────
        // Renders from IndexedDB, filled by /api/v1/pm/sync, so it works with no signal.
        Route::get('/field', [FieldController::class, 'index'])->name('field');

        // ── Project documents ─────────────────────────────────────────────────
        Route::get('/projects/{project}/documents', [DocumentsController::class, 'index'])->name('documents');
        Route::post('/projects/{project}/documents', [DocumentsController::class, 'store'])->name('documents.store');
        Route::get('/projects/{project}/documents/{documentId}', [DocumentsController::class, 'show'])->name('documents.show');
        Route::delete('/projects/{project}/documents/{documentId}', [DocumentsController::class, 'destroy'])->name('documents.destroy');

        // Revisions and signatures. A new revision supersedes rather than replaces, so the document
        // keeps its id and everything already linked to it keeps resolving.
        Route::get('/projects/{project}/documents/{documentId}/history', [DocumentsController::class, 'history'])->name('documents.history');
        Route::post('/projects/{project}/documents/{documentId}/versions', [DocumentsController::class, 'storeVersion'])->name('documents.versions.store');
        Route::post('/projects/{project}/documents/{documentId}/signature', [DocumentsController::class, 'sendForSignature'])->name('documents.signature.send');
        Route::post('/projects/{project}/documents/{documentId}/sign', [DocumentsController::class, 'sign'])->name('documents.sign');
        Route::post('/projects/{project}/documents/{documentId}/decline', [DocumentsController::class, 'declineSignature'])->name('documents.decline');

        // ── Linked records from other modules (PM-1042) ───────────────────────
        // A provider can list every asset in the register; only a person can say which one is on
        // this job, and until they have there is no committed cost to report.
        Route::get('/projects/{project}/links/search', [LinkController::class, 'search'])->name('links.search');
        Route::post('/projects/{project}/links', [LinkController::class, 'store'])->name('links.store');
        Route::delete('/projects/{project}/links/{uuid}', [LinkController::class, 'destroy'])->name('links.destroy');
        // Ask to buy something for this job. Goes through Procurement's own gate — PM raises
        // nothing of its own.
        Route::post('/projects/{project}/purchases', [LinkController::class, 'raisePurchase'])->name('purchases.raise');

        // ── Site: diary, bill of quantities, snags, payment certificates ─────
        Route::get('/projects/{project}/site', [SiteController::class, 'index'])->name('site');
        Route::post('/projects/{project}/site/diary', [SiteController::class, 'storeDiary'])->name('site.diary.store');
        Route::post('/projects/{project}/site/boq', [SiteController::class, 'storeBoqItem'])->name('site.boq.store');
        Route::post('/projects/{project}/site/boq/{uuid}/measure', [SiteController::class, 'measureBoqItem'])->name('site.boq.measure');
        Route::post('/projects/{project}/site/snags', [SiteController::class, 'storeSnag'])->name('site.snags.store');
        Route::post('/projects/{project}/site/snags/{uuid}/close', [SiteController::class, 'closeSnag'])->name('site.snags.close');
        Route::post('/projects/{project}/site/claims', [SiteController::class, 'storeClaim'])->name('site.claims.store');
        Route::post('/projects/{project}/site/claims/{uuid}/submit', [SiteController::class, 'submitClaim'])->name('site.claims.submit');
        Route::post('/projects/{project}/site/claims/{uuid}/certify', [SiteController::class, 'certifyClaim'])->name('site.claims.certify');

        // ── Site records: instructions, tests, inspections, completion (§23) ──
        Route::get('/projects/{project}/site-records', [SiteRecordController::class, 'index'])->name('site.records');
        Route::post('/projects/{project}/instructions', [SiteRecordController::class, 'storeInstruction'])->name('instructions.store');
        Route::put('/projects/{project}/instructions/{uuid}', [SiteRecordController::class, 'updateInstruction'])->name('instructions.update');
        Route::post('/projects/{project}/instructions/{uuid}/variation', [SiteRecordController::class, 'raiseVariation'])->name('instructions.variation');
        Route::post('/projects/{project}/tests', [SiteRecordController::class, 'storeTest'])->name('tests.store');
        Route::post('/projects/{project}/tests/{uuid}/result', [SiteRecordController::class, 'recordResult'])->name('tests.result');
        Route::post('/projects/{project}/inspections', [SiteRecordController::class, 'storeInspection'])->name('inspections.store');
        Route::post('/projects/{project}/inspections/{uuid}/record', [SiteRecordController::class, 'recordInspection'])->name('inspections.record');
        Route::post('/projects/{project}/completion', [SiteRecordController::class, 'certify'])->name('completion.certify');
        Route::post('/projects/{project}/equipment', [SiteRecordController::class, 'storeEquipmentInspection'])->name('equipment.store');

        // ── Project chat (§19) ────────────────────────────────────────────────
        // Polled, not pushed: this deployment runs no websocket server, and a chat that silently
        // stops updating is worse than one that refreshes on a timer.
        Route::get('/projects/{project}/chat', [ChatController::class, 'index'])->name('chat');
        Route::post('/projects/{project}/chat', [ChatController::class, 'store'])->name('chat.store');
        Route::delete('/projects/{project}/chat/{uuid}', [ChatController::class, 'destroy'])->name('chat.destroy');

        // ── Client and contractor portals (§20, §21) ──────────────────────────
        // Not a second application: a portal user is a project member with the client or
        // contractor role, so the existing permission model and cost redaction apply unchanged.
        Route::get('/portal', [PortalController::class, 'index'])->name('portal');
        Route::post('/portal/{project}/documents', [PortalController::class, 'upload'])->name('portal.upload');
        Route::post('/portal/{project}/time', [PortalController::class, 'logTime'])->name('portal.time');
        Route::post('/portal/{project}/rating', [PortalController::class, 'rate'])->name('portal.rate');
        // A client raising a request on their own project. It becomes an ordinary task on the
        // board, so the delivery team schedules and answers it the way they do everything else.
        Route::post('/portal/{project}/tasks', [PortalController::class, 'storeTask'])->name('portal.tasks.store');
        // A contractor's own invoices: theirs to prepare, ours to decide. Every one of these is
        // guarded by contractor membership of the project in the URL.
        Route::post('/portal/{project}/invoices', [PortalController::class, 'openInvoice'])->name('portal.invoices.store');
        Route::post('/portal/{project}/invoices/{invoiceUuid}/lines', [PortalController::class, 'saveInvoiceLine'])->name('portal.invoices.lines.store');
        Route::delete('/portal/{project}/invoices/{invoiceUuid}/lines/{lineUuid}', [PortalController::class, 'removeInvoiceLine'])->name('portal.invoices.lines.destroy');
        Route::post('/portal/{project}/invoices/{invoiceUuid}/document', [PortalController::class, 'attachInvoiceDocument'])->name('portal.invoices.document');
        Route::post('/portal/{project}/invoices/{invoiceUuid}/submit', [PortalController::class, 'submitInvoice'])->name('portal.invoices.submit');


        // ── Project Invoicing (add-on) ────────────────────────────────────────
        // Its own ribbon and its own tables; core Project Management is unchanged whether or not a
        // tenant uses it. Every route additionally requires pm.costs.view — an account is nothing
        // but cost, so there is no redacted view of one to show.
        Route::get('/invoicing/invoices', [InvoicingController::class, 'index'])->name('invoicing.index');
        Route::get('/invoicing/cashflow', [InvoicingController::class, 'cashflow'])->name('invoicing.cashflow');

        // Reached from the project's own tab strip: that job's accounts, not the portfolio's.
        Route::get('/projects/{project}/invoicing', [InvoicingController::class, 'forProject'])->name('projects.invoicing');
        Route::post('/projects/{project}/invoicing/accounts', [InvoicingController::class, 'store'])->name('invoicing.store');
        Route::get('/invoicing/accounts/{uuid}', [InvoicingController::class, 'show'])->name('invoicing.show');
        Route::post('/invoicing/accounts/{uuid}/recalculate', [InvoicingController::class, 'recalculate'])->name('invoicing.recalculate');
        Route::post('/invoicing/accounts/{uuid}/generate', [InvoicingController::class, 'generate'])->name('invoicing.generate');
        Route::post('/invoicing/accounts/{uuid}/sent', [InvoicingController::class, 'markSent'])->name('invoicing.sent');
        Route::post('/invoicing/accounts/{uuid}/sign', [InvoicingController::class, 'sign'])->name('invoicing.sign');
        Route::post('/invoicing/accounts/{uuid}/cancel', [InvoicingController::class, 'cancel'])->name('invoicing.cancel');
        Route::patch('/invoicing/accounts/{uuid}/stages/{stageUuid}', [InvoicingController::class, 'updateStage'])->name('invoicing.stages.update');
        Route::post('/invoicing/accounts/{uuid}/annexures', [InvoicingController::class, 'addAnnexure'])->name('invoicing.annexures.store');
        // Preparing the account: the value of the works, the stage rows, the annexure lines and
        // the disbursements. All refuse once the account has been issued.
        Route::put('/invoicing/accounts/{uuid}/details', [InvoicingController::class, 'updateDetails'])->name('invoicing.details.update');
        Route::put('/invoicing/accounts/{uuid}/fee-account', [InvoicingController::class, 'updateFeeAccount'])->name('invoicing.fee_account.update');
        Route::post('/invoicing/accounts/{uuid}/stages', [InvoicingController::class, 'storeStage'])->name('invoicing.stages.store');
        Route::post('/invoicing/accounts/{uuid}/stages/seed', [InvoicingController::class, 'seedStages'])->name('invoicing.stages.seed');
        Route::delete('/invoicing/accounts/{uuid}/stages/{stageUuid}', [InvoicingController::class, 'destroyStage'])->name('invoicing.stages.destroy');
        Route::post('/invoicing/accounts/{uuid}/annexures/{annexureUuid}/items', [InvoicingController::class, 'storeAnnexureItem'])->name('invoicing.annexure_items.store');
        Route::delete('/invoicing/accounts/{uuid}/annexure-items/{itemUuid}', [InvoicingController::class, 'destroyAnnexureItem'])->name('invoicing.annexure_items.destroy');
        Route::post('/invoicing/accounts/{uuid}/disbursements', [InvoicingController::class, 'storeDisbursement'])->name('invoicing.disbursements.store');
        Route::delete('/invoicing/accounts/{uuid}/disbursements/{rowUuid}', [InvoicingController::class, 'destroyDisbursement'])->name('invoicing.disbursements.destroy');

        // The Implementing Agent's payment certificate, where the practice takes those
        // appointments. Optional per account: adding one is a deliberate act.
        Route::post('/invoicing/accounts/{uuid}/certificate', [InvoicingController::class, 'openCertificate'])->name('invoicing.certificate.open');
        Route::put('/invoicing/accounts/{uuid}/certificate', [InvoicingController::class, 'updateCertificate'])->name('invoicing.certificate.update');
        Route::post('/invoicing/accounts/{uuid}/certificate/kpis', [InvoicingController::class, 'storeCertificateKpi'])->name('invoicing.certificate.kpis.store');
        Route::patch('/invoicing/accounts/{uuid}/certificate/kpis/{kpiUuid}', [InvoicingController::class, 'updateCertificateKpi'])->name('invoicing.certificate.kpis.update');
        Route::delete('/invoicing/accounts/{uuid}/certificate/kpis/{kpiUuid}', [InvoicingController::class, 'destroyCertificateKpi'])->name('invoicing.certificate.kpis.destroy');
        Route::post('/invoicing/accounts/{uuid}/certificate/payments', [InvoicingController::class, 'storeCertificatePayment'])->name('invoicing.certificate.payments.store');
        Route::patch('/invoicing/accounts/{uuid}/certificate/payments/{rowUuid}', [InvoicingController::class, 'updateCertificatePayment'])->name('invoicing.certificate.payments.update');
        Route::delete('/invoicing/accounts/{uuid}/certificate/payments/{rowUuid}', [InvoicingController::class, 'destroyCertificatePayment'])->name('invoicing.certificate.payments.destroy');

        // Documents: one from the pack, or the whole pack. A plain GET so the browser downloads it.
        Route::get('/invoicing/accounts/{uuid}/documents/{kind}', [InvoicingController::class, 'download'])->name('invoicing.download');

        // ── Subcontractor register and billing (add-on) ───────────────────────
        // The ribbon lands on the register — the subject — with the queue one click away.
        // /contractors itself is the hub landing (registered above), so the register lives one
        // level down. Names are unchanged -- Register.vue and Show.vue both link by route name.
        Route::get('/contractors/register', [ContractorBillingController::class, 'register'])->name('contractors.register');
        Route::post('/contractors/register', [ContractorBillingController::class, 'store'])->name('contractors.store');
        Route::get('/contractors/invoices', [ContractorBillingController::class, 'index'])->name('contractors.index');
        Route::get('/contractors/firms/{id}', [ContractorBillingController::class, 'show'])->name('contractors.show');
        Route::post('/contractors/firms/{id}/engage', [ContractorBillingController::class, 'engage'])->name('contractors.engage');
        Route::get('/contractors/projects/{project}', [ContractorBillingController::class, 'forProject'])->name('contractors.project');
        Route::post('/contractors/invoices/{uuid}/approve', [ContractorBillingController::class, 'approve'])->name('contractors.invoices.approve');
        Route::post('/contractors/invoices/{uuid}/reject', [ContractorBillingController::class, 'reject'])->name('contractors.invoices.reject');
        Route::get('/contractors/invoices/{uuid}/document', [ContractorBillingController::class, 'download'])->name('contractors.invoices.document');

        // ── Project contracts, owned by this module ───────────────────────────
        // Drafted, amended, reviewed and signed here. Nothing in this group needs Contract
        // Management installed: the modules are sold separately, and a tenant on PM alone must be
        // able to run a subcontract end to end. See ProjectContractService.
        //
        // Templates sit above the register because they are administration — the same
        // `manageSettings` gate as calendars and approval flows. They are declared before
        // `/contracts/{uuid}` so the literal segment is not swallowed by the wildcard.
        Route::get('/contracts/templates', [ContractTemplateController::class, 'index'])->name('contracts.templates.index');
        Route::post('/contracts/templates', [ContractTemplateController::class, 'store'])->name('contracts.templates.store');
        Route::post('/contracts/templates/import', [ContractTemplateController::class, 'import'])->name('contracts.templates.import');
        Route::get('/contracts/templates/{uuid}', [ContractTemplateController::class, 'show'])->name('contracts.templates.show');
        Route::patch('/contracts/templates/{uuid}', [ContractTemplateController::class, 'update'])->name('contracts.templates.update');
        Route::delete('/contracts/templates/{uuid}', [ContractTemplateController::class, 'destroy'])->name('contracts.templates.destroy');

        Route::get('/contracts', [ProjectContractController::class, 'index'])->name('contracts.index');
        Route::post('/contracts', [ProjectContractController::class, 'store'])->name('contracts.store');
        Route::get('/contracts/{uuid}', [ProjectContractController::class, 'show'])->name('contracts.show');
        Route::post('/contracts/{uuid}/amend', [ProjectContractController::class, 'amend'])->name('contracts.amend');
        Route::post('/contracts/{uuid}/submit', [ProjectContractController::class, 'submit'])->name('contracts.submit');
        Route::post('/contracts/{uuid}/send', [ProjectContractController::class, 'send'])->name('contracts.send');
        // Signing is addressed by contract, never by signature id: the lookup is always "what is
        // pending for me", so nobody can sign in another party's place by passing their uuid.
        Route::post('/contracts/{uuid}/sign', [ProjectContractController::class, 'sign'])->name('contracts.sign');
        Route::post('/contracts/{uuid}/decline', [ProjectContractController::class, 'decline'])->name('contracts.decline');
        // Reopening a signed contract: the practice's decision, and it notifies every signatory.
        Route::post('/contracts/{uuid}/reopen', [ProjectContractController::class, 'reopen'])->name('contracts.reopen');

        // Sign the PDF on the PDF, the way Tender Pursuit's filler works.
        Route::get('/contracts/{uuid}/sign', [ProjectContractController::class, 'signView'])->name('contracts.sign-view');

        // The contract as a document: the executed copy where there is one, else what was issued.
        Route::get('/contracts/{uuid}/document', [ProjectContractController::class, 'document'])->name('contracts.document');
        // The flattened, signed PDF, produced in the browser where the signature was placed.
        Route::post('/contracts/{uuid}/signed-document', [ProjectContractController::class, 'storeSignedDocument'])->name('contracts.signed-document');

        // A signature saved for reuse, so nobody redraws it on every document.
        Route::post('/contract-signatures', [ProjectContractController::class, 'storeSignature'])->name('contracts.signature.store');
        Route::delete('/contract-signatures/{uuid}', [ProjectContractController::class, 'destroySignature'])->name('contracts.signature.destroy');

        // ── Project contracts, owned by this module ───────────────────────────
        // Drafted, amended, reviewed and signed here. Nothing in this group needs Contract
        // Management installed: the modules are sold separately, and a tenant on PM alone must be
        // able to run a subcontract end to end. See ProjectContractService.
        //
        // Templates sit above the register because they are administration — the same
        // `manageSettings` gate as calendars and approval flows.
        Route::get('/contracts/templates', [ContractTemplateController::class, 'index'])->name('contracts.templates.index');
        Route::post('/contracts/templates', [ContractTemplateController::class, 'store'])->name('contracts.templates.store');
        Route::post('/contracts/templates/import', [ContractTemplateController::class, 'import'])->name('contracts.templates.import');
        Route::get('/contracts/templates/{uuid}', [ContractTemplateController::class, 'show'])->name('contracts.templates.show');
        Route::patch('/contracts/templates/{uuid}', [ContractTemplateController::class, 'update'])->name('contracts.templates.update');
        Route::delete('/contracts/templates/{uuid}', [ContractTemplateController::class, 'destroy'])->name('contracts.templates.destroy');

        Route::get('/contracts', [ProjectContractController::class, 'index'])->name('contracts.index');
        Route::post('/contracts', [ProjectContractController::class, 'store'])->name('contracts.store');
        Route::get('/contracts/{uuid}', [ProjectContractController::class, 'show'])->name('contracts.show');
        Route::post('/contracts/{uuid}/amend', [ProjectContractController::class, 'amend'])->name('contracts.amend');
        Route::post('/contracts/{uuid}/submit', [ProjectContractController::class, 'submit'])->name('contracts.submit');
        Route::post('/contracts/{uuid}/send', [ProjectContractController::class, 'send'])->name('contracts.send');
        // Signing is addressed by contract, never by signature id: the lookup is always "what is
        // pending for me", so nobody can sign in another party's place by passing their uuid.
        Route::post('/contracts/{uuid}/sign', [ProjectContractController::class, 'sign'])->name('contracts.sign');
        Route::post('/contracts/{uuid}/decline', [ProjectContractController::class, 'decline'])->name('contracts.decline');

        // ── Monthly Project & Financial Reporting (add-on) ────────────────────
        // Its own ribbon and its own tables. The months a report shows are derived from its
        // reporting month, never stored as columns.
        Route::get('/reporting/reports', [MonthlyReportingController::class, 'index'])->name('reporting.index');
        Route::post('/reporting/reports', [MonthlyReportingController::class, 'store'])->name('reporting.store');
        Route::get('/reporting/projects', [MonthlyReportingController::class, 'projectReports'])->name('reporting.projects');
        Route::get('/reporting/dashboard', [MonthlyReportingController::class, 'dashboard'])->name('reporting.dashboard');
        Route::get('/reporting/reports/{uuid}', [MonthlyReportingController::class, 'show'])->name('reporting.show');
        Route::patch('/reporting/reports/{uuid}', [MonthlyReportingController::class, 'updatePeriod'])->name('reporting.update');
        Route::post('/reporting/reports/{uuid}/projects', [MonthlyReportingController::class, 'addProject'])->name('reporting.projects.store');
        Route::patch('/reporting/reports/{uuid}/rows/{rowUuid}', [MonthlyReportingController::class, 'updateRow'])->name('reporting.rows.update');
        Route::patch('/reporting/reports/{uuid}/rows/{rowUuid}/cashflow', [MonthlyReportingController::class, 'updateCashflow'])->name('reporting.cashflow.update');
        Route::post('/reporting/reports/{uuid}/rows/{rowUuid}/pull-invoiced', [MonthlyReportingController::class, 'pullInvoiced'])->name('reporting.rows.pull_invoiced');
        Route::get('/reporting/reports/{uuid}/download/{format}', [MonthlyReportingController::class, 'download'])->name('reporting.download');
        Route::post('/reporting/reports/{uuid}/issue', [MonthlyReportingController::class, 'issue'])->name('reporting.issue');

        // ── Agile: sprints, backlog, epics, releases (§24) ────────────────────
        // Stories are ordinary tasks carrying sprint/epic/points, so nothing here duplicates task
        // creation — it only ever arranges them.
        Route::get('/projects/{project}/agile', [AgileController::class, 'index'])->name('agile');
        Route::post('/projects/{project}/sprints', [AgileController::class, 'storeSprint'])->name('sprints.store');
        Route::post('/projects/{project}/sprints/{uuid}/start', [AgileController::class, 'startSprint'])->name('sprints.start');
        Route::post('/projects/{project}/sprints/{uuid}/close', [AgileController::class, 'closeSprint'])->name('sprints.close');
        Route::patch('/projects/{project}/stories/{uuid}', [AgileController::class, 'updateStory'])->name('stories.update');
        Route::patch('/projects/{project}/stories/{uuid}/rank', [AgileController::class, 'rankStory'])->name('stories.rank');
        Route::post('/projects/{project}/epics', [AgileController::class, 'storeEpic'])->name('epics.store');
        Route::post('/projects/{project}/releases', [AgileController::class, 'storeRelease'])->name('releases.store');
        Route::post('/projects/{project}/releases/{uuid}/ship', [AgileController::class, 'shipRelease'])->name('releases.ship');

        // ── Meetings (§18) ────────────────────────────────────────────────────
        Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings');
        Route::post('/meetings', [MeetingController::class, 'store'])->name('meetings.store');
        Route::put('/meetings/{uuid}', [MeetingController::class, 'update'])->name('meetings.update');
        Route::post('/meetings/{uuid}/minutes', [MeetingController::class, 'recordMinutes'])->name('meetings.minutes');
        Route::delete('/meetings/{uuid}', [MeetingController::class, 'destroy'])->name('meetings.destroy');

        // ── Risk, issue and change registers (§11, §12, §13) ──────────────────
        // One page, three tabs: a steering meeting reads them together.
        Route::get('/projects/{project}/registers', [RegisterController::class, 'index'])->name('registers');
        Route::post('/projects/{project}/risks', [RegisterController::class, 'storeRisk'])->name('risks.store');
        Route::put('/projects/{project}/risks/{uuid}', [RegisterController::class, 'updateRisk'])->name('risks.update');
        Route::post('/projects/{project}/risks/{uuid}/close', [RegisterController::class, 'closeRisk'])->name('risks.close');
        Route::post('/projects/{project}/risks/{uuid}/realise', [RegisterController::class, 'promoteRisk'])->name('risks.realise');
        Route::delete('/projects/{project}/risks/{uuid}', [RegisterController::class, 'destroyRisk'])->name('risks.destroy');

        Route::post('/projects/{project}/issues', [RegisterController::class, 'storeIssue'])->name('issues.store');
        Route::put('/projects/{project}/issues/{uuid}', [RegisterController::class, 'updateIssue'])->name('issues.update');
        Route::post('/projects/{project}/issues/{uuid}/resolve', [RegisterController::class, 'resolveIssue'])->name('issues.resolve');
        Route::delete('/projects/{project}/issues/{uuid}', [RegisterController::class, 'destroyIssue'])->name('issues.destroy');

        Route::post('/projects/{project}/changes', [RegisterController::class, 'storeChange'])->name('changes.store');
        Route::put('/projects/{project}/changes/{uuid}', [RegisterController::class, 'updateChange'])->name('changes.update');
        Route::post('/projects/{project}/changes/{uuid}/submit', [RegisterController::class, 'submitChange'])->name('changes.submit');
        Route::post('/projects/{project}/changes/{uuid}/implement', [RegisterController::class, 'implementChange'])->name('changes.implement');
        Route::delete('/projects/{project}/changes/{uuid}', [RegisterController::class, 'destroyChange'])->name('changes.destroy');

        // ── Delivery: people, hours and cost ──────────────────────────────────
        Route::get('/projects/{project}/delivery', [DeliveryController::class, 'index'])->name('delivery');
        Route::post('/projects/{project}/tasks/{uuid}/assign', [DeliveryController::class, 'assign'])->name('assign.store');
        Route::delete('/projects/{project}/tasks/{uuid}/assign/{memberUuid}', [DeliveryController::class, 'unassign'])->name('assign.destroy');
        Route::post('/projects/{project}/time', [DeliveryController::class, 'logTime'])->name('time.store');
        Route::post('/projects/{project}/time/{uuid}/approve', [DeliveryController::class, 'approveTime'])->name('time.approve');
        Route::post('/projects/{project}/time/{uuid}/reject', [DeliveryController::class, 'rejectTime'])->name('time.reject');

        // The team. Everything above needs somebody to be on the project first.
        Route::get('/projects/{project}/members/candidates', [DeliveryController::class, 'memberCandidates'])->name('members.candidates');
        Route::post('/projects/{project}/members', [DeliveryController::class, 'addMember'])->name('members.store');
        Route::patch('/projects/{project}/members/{uuid}', [DeliveryController::class, 'updateMember'])->name('members.update');
        Route::delete('/projects/{project}/members/{uuid}', [DeliveryController::class, 'removeMember'])->name('members.destroy');

        // Portal invitations. Authorised by manageMembers, like adding the member in the first
        // place: whoever may put a client on a project may send them the link to see it. The
        // accept side is unauthenticated and lives in project-management-public.php.
        Route::post('/projects/{project}/members/{uuid}/invite', [InviteController::class, 'store'])->name('members.invite');
        Route::delete('/projects/{project}/invites/{uuid}', [InviteController::class, 'destroy'])->name('invites.destroy');

        // Non-labour cost: materials, plant, subcontractors.
        Route::post('/projects/{project}/expenses', [DeliveryController::class, 'storeExpense'])->name('expenses.store');
        Route::post('/projects/{project}/expenses/{uuid}/paid', [DeliveryController::class, 'markExpensePaid'])->name('expenses.paid');
        Route::delete('/projects/{project}/expenses/{uuid}', [DeliveryController::class, 'destroyExpense'])->name('expenses.destroy');

        // ── Derived figures and their evidence ────────────────────────────────
        Route::get('/projects/{project}/figures', [FiguresController::class, 'index'])->name('figures');
        Route::post('/projects/{project}/figures/recalculate', [FiguresController::class, 'recalculate'])->name('figures.recalculate');
        Route::get('/projects/{project}/figures/{uuid}', [FiguresController::class, 'show'])->name('figures.show');
        Route::post('/projects/{project}/figures/{uuid}/override', [FiguresController::class, 'override'])->name('figures.override');
        Route::delete('/projects/{project}/figures/{uuid}/override', [FiguresController::class, 'clearOverride'])->name('figures.override.clear');

        // ── Board, calendar and timeline ──────────────────────────────────────
        Route::get('/projects/{project}/board', [BoardController::class, 'index'])->name('board');
        Route::patch('/projects/{project}/board/{uuid}', [BoardController::class, 'moveCard'])->name('board.move');
        // The board's own columns, named by whoever runs the project (T-0008).
        Route::put('/projects/{project}/board/steps', [BoardController::class, 'updateSteps'])->name('board.steps');

        // ── Schedule: critical path, float, baselines ─────────────────────────
        Route::get('/projects/{project}/schedule', [ScheduleController::class, 'index'])->name('schedule');
        Route::post('/projects/{project}/schedule/run', [ScheduleController::class, 'reschedule'])->name('schedule.run');
        Route::post('/projects/{project}/baselines', [ScheduleController::class, 'storeBaseline'])->name('baselines.store');

        // ── Inside a task: checklist, conversation, watchers, files, recurrence ─
        Route::post('/projects/{project}/tasks/{uuid}/checklist', [TaskCollaborationController::class, 'storeChecklistItem'])->name('checklist.store');
        Route::patch('/projects/{project}/tasks/{uuid}/checklist/reorder', [TaskCollaborationController::class, 'reorderChecklist'])->name('checklist.reorder');
        Route::patch('/projects/{project}/checklist/{uuid}', [TaskCollaborationController::class, 'updateChecklistItem'])->name('checklist.update');
        Route::delete('/projects/{project}/checklist/{uuid}', [TaskCollaborationController::class, 'destroyChecklistItem'])->name('checklist.destroy');

        Route::post('/projects/{project}/tasks/{uuid}/comments', [TaskCollaborationController::class, 'storeComment'])->name('comments.store');
        Route::patch('/projects/{project}/comments/{uuid}', [TaskCollaborationController::class, 'updateComment'])->name('comments.update');
        Route::delete('/projects/{project}/comments/{uuid}', [TaskCollaborationController::class, 'destroyComment'])->name('comments.destroy');

        Route::post('/projects/{project}/tasks/{uuid}/watch', [TaskCollaborationController::class, 'watch'])->name('watch.store');
        Route::delete('/projects/{project}/tasks/{uuid}/watch', [TaskCollaborationController::class, 'unwatch'])->name('watch.destroy');

        Route::post('/projects/{project}/tasks/{uuid}/attachments', [TaskCollaborationController::class, 'storeAttachment'])->name('attachments.store');
        Route::get('/projects/{project}/attachments/{uuid}', [TaskCollaborationController::class, 'downloadAttachment'])->name('attachments.show');
        Route::delete('/projects/{project}/attachments/{uuid}', [TaskCollaborationController::class, 'destroyAttachment'])->name('attachments.destroy');

        Route::post('/projects/{project}/tasks/{uuid}/recurrence', [TaskCollaborationController::class, 'storeRecurrence'])->name('recurrence.store');
        Route::patch('/projects/{project}/recurrence/{uuid}', [TaskCollaborationController::class, 'updateRecurrence'])->name('recurrence.update');
        Route::delete('/projects/{project}/recurrence/{uuid}', [TaskCollaborationController::class, 'destroyRecurrence'])->name('recurrence.destroy');

        // ── Evidence Ledger ───────────────────────────────────────────────────
        // No update and no destroy: the ledger is append-only (PM-ADR-002). Corrections go through
        // supersede, which writes a new event and leaves the original in place.
        Route::get('/evidence/timeline', [EvidenceController::class, 'timeline'])->name('evidence.timeline');
        Route::get('/evidence/audit', [EvidenceController::class, 'audit'])->name('evidence.audit');
        Route::post('/evidence', [EvidenceController::class, 'store'])->name('evidence.store');
        Route::post('/evidence/{uuid}/supersede', [EvidenceController::class, 'supersede'])->name('evidence.supersede');

        // ── Setup (ADMIN tier, enforced by ProjectHubs::guards()) ─────────────
        Route::get('/setup/calendars', [SetupController::class, 'calendars'])->name('setup.calendars');
        Route::post('/setup/calendars', [SetupController::class, 'storeCalendar'])->name('setup.calendars.store');
        Route::patch('/setup/calendars/{calendar}', [SetupController::class, 'updateCalendar'])->name('setup.calendars.update');
        Route::get('/setup/integrations', [SetupController::class, 'integrations'])->name('setup.integrations');

        // Approval routing — designed per tenant, because companies do not agree on who signs what.
        Route::get('/setup/approvals', [SetupController::class, 'approvals'])->name('setup.approvals');
        Route::post('/setup/approvals', [SetupController::class, 'storeFlow'])->name('setup.approvals.store');
        Route::put('/setup/approvals/{uuid}', [SetupController::class, 'updateFlow'])->name('setup.approvals.update');
        Route::delete('/setup/approvals/{uuid}', [SetupController::class, 'destroyFlow'])->name('setup.approvals.destroy');

        // Rooms, licences and plant that no other module owns.
        Route::get('/setup/resources', [SetupController::class, 'resources'])->name('setup.resources');
        Route::post('/setup/resources', [SetupController::class, 'storeResource'])->name('setup.resources.store');
        Route::put('/setup/resources/{uuid}', [SetupController::class, 'updateResource'])->name('setup.resources.update');
        Route::delete('/setup/resources/{uuid}', [SetupController::class, 'destroyResource'])->name('setup.resources.destroy');

        // Clients, and adoption into CRM once that module arrives.
        Route::get('/setup/clients', [SetupController::class, 'clients'])->name('setup.clients');
        Route::patch('/setup/clients/{uuid}', [SetupController::class, 'updateClient'])->name('setup.clients.update');
        // The practice's own invoicing details — who invoices are from, and where clients pay.
        Route::get('/setup/invoicing', [SetupController::class, 'invoicing'])->name('setup.invoicing');
        Route::put('/setup/invoicing', [SetupController::class, 'updateInvoicing'])->name('setup.invoicing.update');
        Route::post('/setup/clients', [SetupController::class, 'storeClient'])->name('setup.clients.store');
        Route::post('/setup/clients/sync', [SetupController::class, 'syncClients'])->name('setup.clients.sync');
    });
