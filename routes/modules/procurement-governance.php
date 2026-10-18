<?php

use App\Http\Controllers\ModuleHubController;
use App\Modules\Procurement\Governance\Http\Controllers\ApprovalController;
use App\Modules\Procurement\Governance\Http\Controllers\CommitteeController;
use App\Modules\Procurement\Governance\Http\Controllers\DeviationController;
use App\Modules\Procurement\Governance\Http\Controllers\DocumentController;
use App\Modules\Procurement\Governance\Http\Controllers\EvaluationController;
use App\Modules\Procurement\Governance\Http\Controllers\GovernanceDashboardController;
use App\Modules\Procurement\Governance\Http\Controllers\InsightsController;
use App\Modules\Procurement\Governance\Http\Controllers\PlanController;
use App\Modules\Procurement\Governance\Http\Controllers\RecordController;
use App\Modules\Procurement\Governance\Http\Controllers\SettingsController;
use App\Modules\Procurement\Governance\Http\Controllers\TenderReviewController;
use Illuminate\Support\Facades\Route;

/*
 * Procurement Governance / ESPPRA — tender evaluation, procurement planning, deviations,
 * reporting, workload and the universal audit trail.
 *
 * Four gates, in order:
 *   module.active:procurement_governance  — the module is installed, on, and in the tenant's plan
 *   module.tier:procurement_governance    — the user's seat is high enough for this area
 *   procgov.capability:<capability>       — the tenant bought / switched on this capability (§12)
 *   crud.permissions:<permission>         — this user may do this particular thing (§10)
 *
 * The §10 permission is named on every route rather than left to be inferred. EnforceCrudPermissions
 * derives a permission from the route name when none is given, which would ask for
 * 'procgov.plans.view' — a name this module never defines — and refuse everyone except platform
 * admins, who bypass it. Naming it also means the rule is readable here instead of scattered
 * through eleven controllers, and a route added later cannot quietly inherit no check at all.
 *
 * Where several permissions are listed, holding any one of them is enough.
 *
 * Routes bind on the public UUID, never the internal id. Tenant data is isolated by row-level
 * security, so an id belonging to another company does not resolve here at all.
 */
Route::middleware(['module.active:procurement_governance', 'module.tier:procurement_governance'])
    ->prefix('procurement-governance')
    ->name('procgov.')
    ->group(function () {

        Route::get('/', [GovernanceDashboardController::class, 'index'])
            ->middleware('crud.permissions:procgov.dashboard.view')->name('dashboard');

        // ── Hub landings — role-filtered feature cards ────────────────────────────────────────────
        // The cards on a hub are already filtered to what the viewer may open, so the landing itself
        // needs no more than admission to the module.
        foreach ([
            'planning-hub' => 'planning',
            'tenders-hub' => 'tenders',
            'deviations-hub' => 'deviations',
            'governance-hub' => 'governance',
            'insights-hub' => 'insights',
            'configure-hub' => 'configure',
        ] as $path => $group) {
            Route::get("/{$path}", [ModuleHubController::class, 'show'])
                ->defaults('module', 'procurement_governance')
                ->defaults('group', $group)
                ->middleware('crud.permissions:procgov.dashboard.view')
                ->name('hub.'.$group);
        }

        // ── §2 Procurement Planning ───────────────────────────────────────────────────────────────
        Route::middleware('procgov.capability:planning')->group(function () {
            // `create` and `dashboard` are declared before `{uuid}` so the literal segments are not
            // swallowed by the wildcard — otherwise "create" resolves as a plan uuid and 404s.
            Route::get('/planning/dashboard', [PlanController::class, 'dashboard'])
                ->middleware('crud.permissions:procgov.plan.view')->name('planning.dashboard');

            Route::get('/plans', [PlanController::class, 'index'])
                ->middleware('crud.permissions:procgov.plan.view')->name('plans.index');
            Route::get('/plans/create', [PlanController::class, 'create'])
                ->middleware('crud.permissions:procgov.plan.create')->name('plans.create');
            Route::post('/plans', [PlanController::class, 'store'])
                ->middleware('crud.permissions:procgov.plan.create')->name('plans.store');
            Route::get('/plans/{uuid}', [PlanController::class, 'show'])
                ->middleware('crud.permissions:procgov.plan.view')->name('plans.show');
            Route::post('/plans/{uuid}/items', [PlanController::class, 'storeItem'])
                ->middleware('crud.permissions:procgov.plan.edit')->name('plans.items.store');
            Route::patch('/plans/{uuid}/items/{itemUuid}', [PlanController::class, 'updateItem'])
                ->middleware('crud.permissions:procgov.plan.edit')->name('plans.items.update');
            // Activating an item is what commits the money, so it is its own permission rather than
            // ordinary editing (§2.4).
            Route::post('/plans/{uuid}/items/{itemUuid}/activate', [PlanController::class, 'activateItem'])
                ->middleware('crud.permissions:procgov.plan.activate')->name('plans.items.activate');
            // One endpoint moves the plan through submit → approve → activate; the stage itself is
            // checked in the service, so any of the three may reach it (§2.2).
            Route::post('/plans/{uuid}/transition', [PlanController::class, 'transition'])
                ->middleware('crud.permissions:procgov.plan.submit|procgov.plan.approve|procgov.plan.activate')
                ->name('plans.transition');
            Route::post('/plans/{uuid}/refresh-financials', [PlanController::class, 'refreshFinancials'])
                ->middleware('crud.permissions:procgov.plan.edit')->name('plans.refresh');
        });

        // ── §1 Tender Review & Evaluation ─────────────────────────────────────────────────────────
        Route::middleware('procgov.capability:tender_evaluation')->group(function () {
            Route::get('/tenders', [TenderReviewController::class, 'index'])
                ->middleware('crud.permissions:procgov.tender.view')->name('tenders.index');
            Route::get('/tenders/create', [TenderReviewController::class, 'create'])
                ->middleware('crud.permissions:procgov.tender.create')->name('tenders.create');
            Route::post('/tenders', [TenderReviewController::class, 'store'])
                ->middleware('crud.permissions:procgov.tender.create')->name('tenders.store');
            Route::get('/tenders/{uuid}', [TenderReviewController::class, 'show'])
                ->middleware('crud.permissions:procgov.tender.view')->name('tenders.show');

            // §1.4 criteria
            Route::post('/tenders/{uuid}/criteria', [TenderReviewController::class, 'storeCriterion'])
                ->middleware('crud.permissions:procgov.tender.edit')->name('tenders.criteria.store');
            // Reuse the matrix from an earlier tender instead of retyping twenty lines of it.
            Route::post('/tenders/{uuid}/criteria/copy', [TenderReviewController::class, 'copyCriteria'])
                ->middleware('crud.permissions:procgov.tender.edit')->name('tenders.criteria.copy');
            Route::patch('/tenders/{uuid}/criteria/{criterionId}', [TenderReviewController::class, 'updateCriterion'])
                ->middleware('crud.permissions:procgov.tender.edit')
                ->name('tenders.criteria.update')->whereNumber('criterionId');
            Route::delete('/tenders/{uuid}/criteria/{criterionId}', [TenderReviewController::class, 'destroyCriterion'])
                ->middleware('crud.permissions:procgov.tender.edit')
                ->name('tenders.criteria.destroy')->whereNumber('criterionId');
            // Criteria are frozen once bidding opens; changing them afterwards is an approval
            // request, not an edit (§1.4).
            Route::post('/tenders/{uuid}/criteria-change', [TenderReviewController::class, 'requestCriteriaChange'])
                ->middleware('crud.permissions:procgov.tender.edit')->name('tenders.criteria.change');

            // §1.1 electronic bid intake. Inviting and withdrawing are ordinary tender editing;
            // opening is the ceremony, and is deliberately held to the moderator's permission
            // because it is the single irreversible moment in the whole tender.
            Route::post('/tenders/{uuid}/invitations', [TenderReviewController::class, 'inviteBidder'])
                ->middleware('crud.permissions:procgov.tender.edit')->name('tenders.invitations.store');
            Route::delete('/tenders/{uuid}/invitations/{invitationId}', [TenderReviewController::class, 'withdrawBid'])
                ->middleware('crud.permissions:procgov.tender.edit')
                ->name('tenders.invitations.withdraw')->whereNumber('invitationId');
            Route::post('/tenders/{uuid}/open-bids', [TenderReviewController::class, 'openBids'])
                ->middleware('crud.permissions:procgov.tender.moderate')->name('tenders.open-bids');
            /*
             * Reading a bid once it has been opened. Sealing, submitting and the opening ceremony all
             * existed; nothing could read the result, so a tender could be opened and still not
             * evaluated on anything but the headline figures. The service refuses before opening, so
             * this route cannot leak an unopened bid whoever holds the permission.
             */
            Route::get('/tenders/{uuid}/bid-documents/{documentId}', [TenderReviewController::class, 'downloadBidDocument'])
                ->middleware('crud.permissions:procgov.tender.view|procgov.evaluate')
                ->name('tenders.bid-documents.download')->whereNumber('documentId');

            // Bidders
            Route::post('/tenders/{uuid}/bidders', [TenderReviewController::class, 'storeBidder'])
                ->middleware('crud.permissions:procgov.tender.edit')->name('tenders.bidders.store');
            Route::post('/tenders/{uuid}/bidders/{bidderId}/disqualify', [TenderReviewController::class, 'disqualifyBidder'])
                ->middleware('crud.permissions:procgov.tender.edit')
                ->name('tenders.bidders.disqualify')->whereNumber('bidderId');
            Route::post('/tenders/{uuid}/bidders/{bidderId}/reinstate', [TenderReviewController::class, 'reinstateBidder'])
                ->middleware('crud.permissions:procgov.tender.edit')
                ->name('tenders.bidders.reinstate')->whereNumber('bidderId');

            // Lifecycle
            Route::post('/tenders/{uuid}/committee', [TenderReviewController::class, 'assignCommittee'])
                ->middleware('crud.permissions:procgov.tender.edit')->name('tenders.committee');
            Route::post('/tenders/{uuid}/open-evaluation', [TenderReviewController::class, 'openEvaluation'])
                ->middleware('crud.permissions:procgov.tender.edit')->name('tenders.open');
            Route::post('/tenders/{uuid}/transition', [TenderReviewController::class, 'transition'])
                ->middleware('crud.permissions:procgov.tender.edit|procgov.tender.approve')->name('tenders.transition');
            Route::post('/tenders/{uuid}/recompute', [TenderReviewController::class, 'recompute'])
                ->middleware('crud.permissions:procgov.tender.edit|procgov.tender.moderate')->name('tenders.recompute');
            Route::post('/tenders/{uuid}/compliance-check', [TenderReviewController::class, 'complianceCheck'])
                ->middleware('crud.permissions:procgov.tender.edit')->name('tenders.compliance');

            // §1.7 moderation
            Route::get('/tenders/{uuid}/moderation', [TenderReviewController::class, 'moderation'])
                ->middleware('crud.permissions:procgov.tender.moderate')->name('tenders.moderation');
            Route::post('/tenders/{uuid}/moderation/{itemId}', [TenderReviewController::class, 'moderateItem'])
                ->middleware('crud.permissions:procgov.tender.moderate')
                ->name('tenders.moderation.act')->whereNumber('itemId');
            Route::post('/tenders/{uuid}/approve-scores', [TenderReviewController::class, 'approveScores'])
                ->middleware('crud.permissions:procgov.tender.moderate')->name('tenders.approve-scores');

            // §1.8 recommendation
            Route::post('/tenders/{uuid}/recommendation', [TenderReviewController::class, 'generateRecommendation'])
                ->middleware('crud.permissions:procgov.tender.recommend')
                ->name('tenders.recommendation.generate');
            Route::post('/tenders/{uuid}/recommendation/{recommendationId}/submit', [TenderReviewController::class, 'submitRecommendation'])
                ->middleware('crud.permissions:procgov.tender.recommend')
                ->name('tenders.recommendation.submit')->whereNumber('recommendationId');
            // Deciding is the award itself — separated from writing the recommendation so the same
            // person cannot both propose and approve (§1.8).
            Route::post('/tenders/{uuid}/recommendation/{recommendationId}/decide', [TenderReviewController::class, 'decideRecommendation'])
                ->middleware('crud.permissions:procgov.tender.approve')
                ->name('tenders.recommendation.decide')->whereNumber('recommendationId');
            Route::get('/tenders/{uuid}/recommendation/{recommendationId}/pdf', [TenderReviewController::class, 'recommendationPdf'])
                ->middleware('crud.permissions:procgov.tender.view')
                ->name('tenders.recommendation.pdf')->whereNumber('recommendationId');

            // §1.5 the evaluator's own workspace. Appointment to the panel is checked per tender in
            // the controller; this permission only says the user may score at all.
            Route::get('/evaluations', [EvaluationController::class, 'index'])
                ->middleware('crud.permissions:procgov.evaluate')->name('evaluations.index');
            Route::get('/evaluations/{uuid}', [EvaluationController::class, 'review'])
                ->middleware('crud.permissions:procgov.evaluate')->name('evaluations.review');
            Route::post('/evaluations/{uuid}/declare', [EvaluationController::class, 'declare'])
                ->middleware('crud.permissions:procgov.evaluate')->name('evaluations.declare');
            Route::post('/evaluations/{uuid}/confidentiality', [EvaluationController::class, 'acceptConfidentiality'])
                ->middleware('crud.permissions:procgov.evaluate')->name('evaluations.confidentiality');
            Route::get('/evaluations/{uuid}/bidders/{bidderUuid}', [EvaluationController::class, 'workspace'])
                ->middleware('crud.permissions:procgov.evaluate')->name('evaluations.workspace');
            Route::post('/evaluations/{uuid}/bidders/{bidderUuid}/draft', [EvaluationController::class, 'saveDraft'])
                ->middleware('crud.permissions:procgov.evaluate')->name('evaluations.draft');
            Route::post('/evaluations/{uuid}/bidders/{bidderUuid}/submit', [EvaluationController::class, 'submit'])
                ->middleware('crud.permissions:procgov.evaluate')->name('evaluations.submit');

            /*
             * §1.7 the evaluator's reply to a clarification.
             *
             * A moderator raising CLARIFY, RE_EVALUATE or ADJUST puts the item in AWAITING_RESPONSE,
             * and `approveScores` refuses while anything sits in OPEN or AWAITING_RESPONSE. Replying
             * was the only way out of that state and had no route, so raising a clarification stopped
             * the tender permanently — it could not reach §1.8 recommendation without an edit to the
             * database.
             *
             * Deliberately on `procgov.evaluate` rather than the moderator's permission: this is the
             * evaluator answering, and if the moderator could answer their own clarification the
             * round trip would be a formality.
             */
            Route::post('/evaluations/{uuid}/moderation/{itemId}/respond', [EvaluationController::class, 'respondToModeration'])
                ->middleware('crud.permissions:procgov.evaluate')
                ->name('evaluations.moderation.respond')->whereNumber('itemId');

            // §1.2 committees. An evaluator needs to see the panel they sit on, so reading is open
            // to anyone who may work on tenders; changing its membership is not.
            Route::get('/committees', [CommitteeController::class, 'index'])
                ->middleware('crud.permissions:procgov.committee.manage|procgov.tender.view|procgov.evaluate')
                ->name('committees.index');
            Route::post('/committees', [CommitteeController::class, 'store'])
                ->middleware('crud.permissions:procgov.committee.manage')->name('committees.store');
            Route::get('/committees/{uuid}', [CommitteeController::class, 'show'])
                ->middleware('crud.permissions:procgov.committee.manage|procgov.tender.view|procgov.evaluate')
                ->name('committees.show');
            Route::post('/committees/{uuid}/members', [CommitteeController::class, 'addMember'])
                ->middleware('crud.permissions:procgov.committee.manage')->name('committees.members.store');
            Route::post('/committees/{uuid}/members/{memberId}/end', [CommitteeController::class, 'endMembership'])
                ->middleware('crud.permissions:procgov.committee.manage')
                ->name('committees.members.end')->whereNumber('memberId');
            Route::post('/committees/{uuid}/activate', [CommitteeController::class, 'activate'])
                ->middleware('crud.permissions:procgov.committee.manage')->name('committees.activate');

            // §1.3 conflict resolution by an administrator
            Route::post('/declarations/{declarationUuid}/resolve', [CommitteeController::class, 'resolveConflict'])
                ->middleware('crud.permissions:procgov.committee.manage')->name('declarations.resolve');
        });

        // ── §5 Deviation Management ───────────────────────────────────────────────────────────────
        Route::middleware('procgov.capability:deviations')->group(function () {
            Route::get('/deviations', [DeviationController::class, 'index'])
                ->middleware('crud.permissions:procgov.deviation.view')->name('deviations.index');
            Route::get('/deviations/create', [DeviationController::class, 'create'])
                ->middleware('crud.permissions:procgov.deviation.create')->name('deviations.create');
            Route::post('/deviations', [DeviationController::class, 'store'])
                ->middleware('crud.permissions:procgov.deviation.create')->name('deviations.store');
            // Adding a deviation type is configuration, not a request (§5.3).
            Route::post('/deviation-types', [DeviationController::class, 'storeType'])
                ->middleware('crud.permissions:procgov.configure')->name('deviations.types.store');
            Route::get('/deviations/{uuid}', [DeviationController::class, 'show'])
                ->middleware('crud.permissions:procgov.deviation.view')->name('deviations.show');
            Route::patch('/deviations/{uuid}', [DeviationController::class, 'update'])
                ->middleware('crud.permissions:procgov.deviation.edit')->name('deviations.update');
            Route::post('/deviations/{uuid}/submit', [DeviationController::class, 'submit'])
                ->middleware('crud.permissions:procgov.deviation.create|procgov.deviation.edit')->name('deviations.submit');
            Route::post('/deviations/{uuid}/transition', [DeviationController::class, 'transition'])
                ->middleware('crud.permissions:procgov.deviation.approve')->name('deviations.transition');
            Route::post('/deviations/{uuid}/documents', [DeviationController::class, 'uploadDocument'])
                ->middleware('crud.permissions:procgov.deviation.edit|procgov.deviation.create')->name('deviations.documents');
        });

        // ── §6 360° records, §7 audit trail, §8 documents, approvals ──────────────────────────────
        Route::middleware('procgov.capability:governance')->group(function () {
            Route::get('/records', [RecordController::class, 'index'])
                ->middleware('crud.permissions:procgov.record.view')->name('records.index');
            Route::get('/records/{uuid}', [RecordController::class, 'show'])
                ->middleware('crud.permissions:procgov.record.view')->name('records.show');
            // §7: the audit trail is its own permission — being able to read a record is not the
            // same as being trusted to read who did what to it.
            Route::get('/records/{uuid}/audit', [RecordController::class, 'audit'])
                ->middleware('crud.permissions:procgov.audit.view')->name('records.audit');
            Route::post('/records/{uuid}/transition', [RecordController::class, 'transition'])
                ->middleware('crud.permissions:procgov.record.edit')->name('records.transition');
            Route::post('/records/{uuid}/officer', [RecordController::class, 'assignOfficer'])
                ->middleware('crud.permissions:procgov.record.edit|procgov.workload.manage')->name('records.officer');
            // §11: re-discover the requisitions, orders, contracts and invoices raised for this
            // procurement in the other modules, without waiting for the nightly reconciliation.
            Route::post('/records/{uuid}/sync-links', [RecordController::class, 'syncLinks'])
                ->middleware('crud.permissions:procgov.record.edit')->name('records.sync');
            Route::post('/records/{uuid}/link-contract', [RecordController::class, 'linkContract'])
                ->middleware('crud.permissions:procgov.record.edit')->name('records.contract');
            Route::post('/records/{uuid}/documents', [RecordController::class, 'uploadDocument'])
                ->middleware('crud.permissions:procgov.record.edit|procgov.document.manage')->name('records.documents');

            Route::get('/documents', [DocumentController::class, 'index'])
                ->middleware('crud.permissions:procgov.document.view')->name('documents.index');
            Route::get('/documents/{uuid}', [DocumentController::class, 'show'])
                ->middleware('crud.permissions:procgov.document.view')->name('documents.show');
            Route::get('/documents/{uuid}/download', [DocumentController::class, 'download'])
                ->middleware('crud.permissions:procgov.document.view')->name('documents.download');
            Route::post('/documents/{uuid}/versions', [DocumentController::class, 'addVersion'])
                ->middleware('crud.permissions:procgov.document.manage')->name('documents.versions');
            // §8: attaching an existing document to another record is the whole point of the
            // library — it is how the same paper is never uploaded twice.
            Route::post('/documents/{uuid}/attach', [DocumentController::class, 'attach'])
                ->middleware('crud.permissions:procgov.document.manage')->name('documents.attach');
            // Acknowledging is something the reader does for themselves, not an act of management.
            Route::post('/documents/{uuid}/acknowledge', [DocumentController::class, 'acknowledge'])
                ->middleware('crud.permissions:procgov.document.view')->name('documents.acknowledge');
            Route::post('/documents/{uuid}/grant', [DocumentController::class, 'grant'])
                ->middleware('crud.permissions:procgov.document.manage')->name('documents.grant');

            Route::get('/compliance', [InsightsController::class, 'compliance'])
                ->middleware('crud.permissions:procgov.report.view|procgov.audit.view')->name('compliance');
        });

        // Approvals are not capability-gated: a chain can approve a plan, a deviation or a
        // recommendation, and an approver with only one of those capabilities must still be able to
        // clear their queue. Holding any approval permission is enough to reach the queue; whether
        // this particular step is theirs to decide is checked against the chain in the controller.
        Route::middleware('crud.permissions:procgov.plan.approve|procgov.deviation.approve|procgov.tender.approve')
            ->group(function () {
                Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
                Route::get('/approvals/{uuid}', [ApprovalController::class, 'show'])->name('approvals.show');
                Route::post('/approvals/{uuid}/decide', [ApprovalController::class, 'decide'])->name('approvals.decide');
            });

        // ── §3 Reporting ──────────────────────────────────────────────────────────────────────────
        Route::middleware('procgov.capability:reporting')->group(function () {
            // The governance picture across the whole module. Held to report.view like the rest of
            // §3: it aggregates the same records, so anyone who may read a spend report may read
            // how competitive the tenders behind it were.
            Route::get('/analytics', [InsightsController::class, 'analytics'])
                ->middleware('crud.permissions:procgov.report.view')->name('analytics');

            Route::get('/reports', [InsightsController::class, 'reports'])
                ->middleware('crud.permissions:procgov.report.view')->name('reports.index');
            Route::post('/reports/generate', [InsightsController::class, 'generateReport'])
                ->middleware('crud.permissions:procgov.report.view')->name('reports.generate');
            Route::get('/reports/runs/{uuid}', [InsightsController::class, 'showRun'])
                ->middleware('crud.permissions:procgov.report.view')->name('reports.run');
        });

        // ── §4 Workload & capacity ────────────────────────────────────────────────────────────────
        Route::middleware('procgov.capability:workload')->group(function () {
            Route::get('/workload', [InsightsController::class, 'workload'])
                ->middleware('crud.permissions:procgov.workload.view')->name('workload.index');
            Route::post('/workload/alerts/{uuid}', [InsightsController::class, 'acknowledgeAlert'])
                ->middleware('crud.permissions:procgov.workload.manage')->name('workload.alerts.update');
        });

        // ── §12 configuration and the §5.3 rules engine ───────────────────────────────────────────
        // Everything here rewrites the rules the rest of the module enforces, so it is one
        // permission and a deliberately small group of people.
        Route::prefix('settings')->name('settings.')
            ->middleware('crud.permissions:procgov.configure')
            ->group(function () {
                Route::get('/', [SettingsController::class, 'index'])->name('index');
                Route::post('/', [SettingsController::class, 'update'])->name('update');
                Route::post('/rule-sets', [SettingsController::class, 'storeRuleSet'])->name('rule-sets.store');
                Route::post('/rule-sets/{ruleSetId}/freeze', [SettingsController::class, 'freezeRuleSet'])
                    ->name('rule-sets.freeze')->whereNumber('ruleSetId');
                Route::post('/rules', [SettingsController::class, 'storeRule'])->name('rules.store');
                Route::delete('/rules/{ruleId}', [SettingsController::class, 'destroyRule'])
                    ->name('rules.destroy')->whereNumber('ruleId');
                // One endpoint creates and edits: a chain is identified by its code, so saving an
                // existing code updates that chain rather than creating a second one with the same
                // name — two chains claiming the same code would make routing ambiguous.
                Route::post('/chains', [SettingsController::class, 'storeChain'])->name('chains.store');
                Route::delete('/chains/{chainId}', [SettingsController::class, 'destroyChain'])
                    ->name('chains.destroy')->whereNumber('chainId');

                // §1.6. The module ships no country's preference statute, so the people who hold
                // the current instrument have to be able to enter it themselves.
                Route::post('/preference-frameworks', [SettingsController::class, 'storeFramework'])
                    ->name('frameworks.store');
                Route::delete('/preference-frameworks/{frameworkId}', [SettingsController::class, 'destroyFramework'])
                    ->name('frameworks.destroy')->whereNumber('frameworkId');
                Route::post('/notification-rules', [SettingsController::class, 'storeNotificationRule'])->name('notifications.store');
                Route::post('/document-categories', [SettingsController::class, 'storeDocumentCategory'])->name('document-categories.store');
            });
    });
