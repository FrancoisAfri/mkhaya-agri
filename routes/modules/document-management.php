<?php

use App\Modules\Operations\DocumentManagement\Http\Controllers\BulkDocumentController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\AccessManagementController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentAccessRequestController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentChecklistController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentCommentController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentQuotaController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentRequestController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\RecycleBinController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentActivityController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentAiController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentApprovalController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentCategoryController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentDashboardController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentFolderController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentGovernanceController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentGroupController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentInternalShareController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentSettingsController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentShareController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\RecordDocumentController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentSigningController;
use App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\ModuleHubController;
use App\Modules\Operations\DocumentManagement\Support\DocumentHubs;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Document Management — the central repository (Operations & Support).
 *
 * Gated by module entitlement (module.active) and hub tier (module.tier); tenant data is isolated
 * by row-level security, so a document belonging to another company does not resolve here at all.
 *
 * Routes bind on the public uuid, never the internal id — a sequential id in a URL invites
 * somebody to try the next one, and the only thing standing behind it would be the RLS policy.
 */
Route::middleware(['module.active:document_management', 'module.tier:document_management'])
    ->prefix('document-management')->name('document-management.')
    ->group(function () {
        Route::get('/', [DocumentController::class, 'index'])->name('index');

        // The module's own front page, and the storage screen behind it.
        Route::get('/dashboard', [DocumentDashboardController::class, 'index'])->name('dashboard');
        Route::get('/storage', [DocumentDashboardController::class, 'storage'])->name('storage');

        /*
         * Hub landings, rendered by the platform's own hub controller.
         *
         * These used to answer with the document library, which meant every ribbon in the
         * navigation led to the same page and not one of the module's cards was reachable through
         * it. The cards existed, their routes existed, and the only thing missing was the screen
         * that lists them — a gap no route check could see, because every path it looked for was
         * genuinely there.
         *
         * Registered from the hub config rather than by hand, so a hub added to DocumentHubs gets
         * its landing without anything here to keep in sync.
         */
        foreach (array_keys(DocumentHubs::hubs()) as $group) {
            $path = ltrim(DocumentHubs::hubs()[$group]['path'], '/');

            Route::get('/' . Str::after($path, 'document-management/'), [ModuleHubController::class, 'show'])
                ->defaults('module', DocumentHubs::code())
                ->defaults('group', $group)
                ->name($group);
        }

        // The library, sliced the ways people actually ask for it.
        Route::get('/upload', [DocumentController::class, 'index'])->name('upload');
        Route::get('/archived', [DocumentController::class, 'index'])->defaults('status', 'archived')->name('archived');
        Route::get('/confidential', [DocumentController::class, 'index'])
            ->defaults('confidential', true)->name('confidential');
        // The flat listing of held documents, complementing the register at /legal-holds: one
        // answers "what are we holding for case X", this one answers "what is held at all".
        Route::get('/legal-hold', [DocumentController::class, 'index'])
            ->defaults('legally_sensitive', true)->name('legal-hold');
        // Documents nobody can search inside — scans without a text layer, and files that have
        // never been read. Worth a screen of its own, because otherwise the only way to notice is
        // to search for something you know is in a document and get nothing back.
        Route::get('/unreadable', [DocumentController::class, 'index'])
            ->defaults('unreadable', true)->name('unreadable');

        /*
         * The personal slices from the module's menu. Four different answers to "where is that
         * document" — I put it there, somebody sent it to me, I had it open on Tuesday, I starred
         * it — and none of them is a search anybody would think to construct.
         */
        Route::get('/my-documents', [DocumentController::class, 'index'])
            ->defaults('mine', true)->name('mine');
        Route::get('/shared-with-me', [DocumentController::class, 'index'])
            ->defaults('shared_with_me', true)->name('shared-with-me');
        Route::get('/recent', [DocumentController::class, 'index'])
            ->defaults('recent', true)->name('recent');
        Route::get('/favourites', [DocumentController::class, 'index'])
            ->defaults('favourites', true)->name('favourites');

        Route::post('/documents/{document}/favourite', [DocumentController::class, 'toggleFavourite'])->name('documents.favourite');

        /*
         * Governance: what is being preserved, and what has come due.
         *
         * Placing a hold and reviewing a disposal are separate permissions on purpose, and the
         * routes are separate for the same reason — approving a destruction is not carrying it out,
         * and neither of them is ordinary editing.
         */
        Route::get('/legal-holds', [DocumentGovernanceController::class, 'holds'])->name('holds.index');
        Route::post('/documents/{document}/hold', [DocumentGovernanceController::class, 'place'])->name('holds.place');
        Route::post('/holds/{hold}/release', [DocumentGovernanceController::class, 'release'])->name('holds.release');
        Route::post('/holds/release-matter', [DocumentGovernanceController::class, 'releaseMatter'])->name('holds.release-matter');

        // Reading a document with help. Gated on viewing it, because neither discloses anything
        // the person could not already read by opening the file.
        Route::post('/documents/{document}/summarise', [DocumentAiController::class, 'summarise'])->name('ai.summarise');
        Route::post('/documents/{document}/ask', [DocumentAiController::class, 'ask'])->name('ai.ask');

        // Reading the fields off a document, and the two ways a person answers a reading. Gated on
        // editing rather than viewing, because accepting one writes to the document.
        Route::post('/documents/{document}/extract-fields', [DocumentAiController::class, 'suggestFields'])->name('ai.fields');
        Route::post('/insights/{insight}/apply', [DocumentAiController::class, 'applyFields'])->name('ai.fields.apply');
        Route::post('/insights/{insight}/dismiss', [DocumentAiController::class, 'dismissFields'])->name('ai.fields.dismiss');

        // Sharing. Issuing a link is its own permission, checked by the policy — it hands a copy
        // to somebody the organisation has no other relationship with.
        Route::post('/documents/{document}/share', [DocumentShareController::class, 'store'])->name('shares.store');

        // Internally, to a colleague, a role, a department or everybody. Same permission as an
        // external link: both hand a document to somebody who could not otherwise open it.
        Route::post('/documents/{document}/share-internally', [DocumentInternalShareController::class, 'store'])->name('grants.store');
        Route::delete('/grants/{grant}', [DocumentInternalShareController::class, 'destroy'])->name('grants.destroy');
        Route::delete('/shares/{share}', [DocumentShareController::class, 'destroy'])->name('shares.destroy');

        /*
         * Asking to be let in. Requesting needs nothing but being able to use the module — refusing
         * somebody the right to ask leaves them where the bare 403 did — while answering needs
         * document.share, because a grant is what an answer creates.
         */
        /*
         * The recycle bin. Restoring needs the restore permission; emptying needs purge, because
         * that is what emptying does — deliberately not the same person's decision.
         */
        /*
         * Groups. Managing them is folder management: what a group is for is being given access to
         * folders, and splitting the two would let somebody assemble a group they cannot then use.
         */
        Route::get('/groups', [DocumentGroupController::class, 'index'])->name('groups');
        Route::post('/groups', [DocumentGroupController::class, 'store'])->name('groups.store');
        Route::put('/groups/{group}', [DocumentGroupController::class, 'update'])->name('groups.update');
        Route::delete('/groups/{group}', [DocumentGroupController::class, 'destroy'])->name('groups.destroy');
        Route::post('/groups/{group}/members', [DocumentGroupController::class, 'addMembers'])->name('groups.members');
        Route::delete('/groups/{group}/members/{user}', [DocumentGroupController::class, 'removeMember'])->name('groups.members.remove');
        Route::post('/groups/{group}/folders', [DocumentGroupController::class, 'grantFolders'])->name('groups.folders');
        Route::delete('/groups/{group}/folders/{folderUuid}', [DocumentGroupController::class, 'revokeFolder'])->name('groups.folders.revoke');

        Route::get('/recycle-bin', [RecycleBinController::class, 'index'])->name('recycle-bin');
        Route::post('/recycle-bin/{uuid}/restore', [RecycleBinController::class, 'restore'])->name('recycle-bin.restore');
        Route::delete('/recycle-bin/{uuid}', [RecycleBinController::class, 'purge'])->name('recycle-bin.purge');
        Route::post('/recycle-bin/empty', [RecycleBinController::class, 'empty'])->name('recycle-bin.empty');

        // One action, many documents — each checked against the same policy the single-document
        // action uses.
        Route::post('/bulk', [BulkDocumentController::class, 'handle'])->name('bulk');

        Route::post('/quotas', [DocumentQuotaController::class, 'store'])->name('quotas.store');
        Route::delete('/quotas/{quota}', [DocumentQuotaController::class, 'destroy'])->name('quotas.destroy');
        Route::get('/checklists', [DocumentChecklistController::class, 'index'])->name('checklists');
        Route::post('/checklists/templates', [DocumentChecklistController::class, 'storeTemplate'])->name('checklists.templates.store');
        Route::post('/checklists', [DocumentChecklistController::class, 'store'])->name('checklists.store');
        Route::post('/checklists/items/{item}/satisfy', [DocumentChecklistController::class, 'satisfy'])->name('checklists.items.satisfy');
        Route::post('/checklists/{checklist}/chase', [DocumentChecklistController::class, 'chase'])->name('checklists.chase');
        Route::delete('/checklists/{checklist}', [DocumentChecklistController::class, 'destroy'])->name('checklists.destroy');
        Route::get('/requests', [DocumentRequestController::class, 'index'])->name('requests');
        Route::post('/requests', [DocumentRequestController::class, 'store'])->name('requests.store');
        Route::post('/requests/{documentRequest}/fulfil', [DocumentRequestController::class, 'fulfil'])->name('requests.fulfil');
        Route::post('/requests/{documentRequest}/fulfil-with', [DocumentRequestController::class, 'fulfilWith'])->name('requests.fulfil-with');
        Route::post('/requests/{documentRequest}/remind', [DocumentRequestController::class, 'remind'])->name('requests.remind');
        Route::post('/requests/{documentRequest}/cancel', [DocumentRequestController::class, 'cancel'])->name('requests.cancel');
        Route::get('/access-management', [AccessManagementController::class, 'index'])->name('access-management');
        Route::post('/access-management/revoke', [AccessManagementController::class, 'revoke'])->name('access-management.revoke');
        Route::get('/access-requests', [DocumentAccessRequestController::class, 'index'])->name('access-requests');
        Route::post('/access-requests', [DocumentAccessRequestController::class, 'store'])->name('access-requests.store');
        Route::post('/access-requests/many', [DocumentAccessRequestController::class, 'storeMany'])->name('access-requests.store-many');
        Route::post('/documents/{document}/request-download', [DocumentAccessRequestController::class, 'requestDownload'])->name('documents.request-download');
        Route::post('/access-requests/{accessRequest}/grant', [DocumentAccessRequestController::class, 'grant'])->name('access-requests.grant');
        Route::post('/access-requests/{accessRequest}/decline', [DocumentAccessRequestController::class, 'decline'])->name('access-requests.decline');

        Route::get('/disposal', [DocumentGovernanceController::class, 'disposal'])->name('disposal');
        Route::post('/documents/{document}/retain', [DocumentGovernanceController::class, 'retain'])->name('disposal.retain');
        Route::post('/documents/{document}/approve-disposal', [DocumentGovernanceController::class, 'approveDisposal'])->name('disposal.approve');
        // The audit trail across the repository. Had a card pointing at it since the module was
        // built and the ordinary document listing behind it.
        Route::get('/activity', [DocumentActivityController::class, 'index'])->name('activity');
        Route::get('/folders', [DocumentController::class, 'index'])->name('folders');

        /*
         * Views of the same library, pre-filtered.
         *
         * Their own routes rather than query strings on the index, because each is a card on a hub
         * and a card has to point somewhere a person can bookmark and come back to. The controller
         * reads the defaults the same way it reads the query.
         */
        Route::get('/expiring', [DocumentController::class, 'index'])
            ->defaults('expiring', true)->name('expiring');
        Route::get('/expired', [DocumentController::class, 'index'])
            ->defaults('expired', true)->name('expired');
        Route::get('/awaiting-approval', [DocumentController::class, 'index'])
            ->defaults('approval_status', 'awaiting')->name('awaiting-approval');
        Route::get('/awaiting-signature', [DocumentController::class, 'index'])
            ->defaults('signature_status', 'open')->name('awaiting-signature');

        Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
        /*
         * A zipped folder, expanded into the library with its structure intact. Same permission as
         * an ordinary upload, and every file inside goes through the same validation.
         */
        Route::post('/documents/archive', [DocumentController::class, 'storeArchive'])
            ->name('documents.archive.store');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::put('/documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
        Route::post('/documents/{document}/replace', [DocumentController::class, 'replace'])->name('documents.replace');
        Route::put('/documents/{document}/sensitivity', [DocumentController::class, 'sensitivity'])->name('documents.sensitivity');
        Route::post('/documents/{document}/archive', [DocumentController::class, 'archive'])->name('documents.archive');
        Route::post('/documents/{document}/restore', [DocumentController::class, 'restore'])->name('documents.restore');
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
        // Permanent destruction is its own route so it can carry its own permission, and so it can
        // never be reached by a stray DELETE meant for the recoverable one above.
        Route::delete('/documents/{document}/purge', [DocumentController::class, 'purge'])->name('documents.purge');

        // Putting an earlier version back. Its own route because it is not an edit to the
        // document's fields — it changes which file the document is.
        Route::post('/documents/{document}/versions/{version}/restore', [DocumentController::class, 'restoreVersion'])
            ->name('documents.versions.restore');

        // Bytes. Both go through the controller rather than a disk URL, so entitlement, tenancy and
        // confidentiality are all checked before anything is served.
        Route::get('/documents/{document}/preview/{version?}', [DocumentController::class, 'preview'])->name('documents.preview');

        // Looking inside an archive, and taking one file out of it. The listing is gated on view
        // because reading a list of names is looking; pulling a file out is gated on download.
        Route::get('/documents/{document}/archive-contents/{version?}', [DocumentController::class, 'archiveContents'])
            ->name('documents.archive-contents');
        Route::get('/documents/{document}/archive-entry/{version}/{index}', [DocumentController::class, 'archiveEntry'])
            ->whereNumber('index')->name('documents.archive-entry');
        Route::post('/documents/{document}/comments', [DocumentCommentController::class, 'store'])->name('documents.comments.store');
        Route::post('/documents/{document}/comments/{comment}/resolve', [DocumentCommentController::class, 'resolve'])->name('documents.comments.resolve');
        Route::delete('/documents/{document}/comments/{comment}', [DocumentCommentController::class, 'destroy'])->name('documents.comments.destroy');
        Route::post('/documents/{document}/shortcut', [DocumentController::class, 'shortcut'])->name('documents.shortcut');
        Route::post('/documents/{document}/supersede', [DocumentController::class, 'supersede'])->name('documents.supersede');
        Route::post('/documents/{document}/check-out', [DocumentController::class, 'checkOut'])->name('documents.check-out');
        Route::post('/documents/{document}/check-in', [DocumentController::class, 'checkIn'])->name('documents.check-in');
        Route::post('/documents/{document}/download-policy', [DocumentController::class, 'downloadPolicy'])->name('documents.download-policy');
        Route::get('/documents/{document}/download/{version?}', [DocumentController::class, 'download'])->name('documents.download');

        /*
         * The filing structure. Each rearrangement is its own route rather than a flag on update,
         * so a move cannot be mistaken for a rename by a request that carries both, and each can
         * carry its own permission later without untangling one endpoint.
         */
        Route::post('/folders', [DocumentFolderController::class, 'store'])->name('folders.store');
        Route::put('/folders/{folder}', [DocumentFolderController::class, 'update'])->name('folders.update');
        Route::post('/folders/{folder}/move', [DocumentFolderController::class, 'move'])->name('folders.move');
        Route::post('/folders/{folder}/copy', [DocumentFolderController::class, 'copy'])->name('folders.copy');
        Route::post('/folders/{folder}/archive', [DocumentFolderController::class, 'archive'])->name('folders.archive');
        Route::post('/folders/{folder}/restore', [DocumentFolderController::class, 'restore'])->name('folders.restore');
        Route::get('/folders/{folder}/permissions', [DocumentFolderController::class, 'permissionsFor'])->name('folders.permissions.show');
        Route::put('/folders/{folder}/permissions', [DocumentFolderController::class, 'permissions'])->name('folders.permissions');
        Route::delete('/folders/{folder}', [DocumentFolderController::class, 'destroy'])->name('folders.destroy');

        /*
         * Approvals. Deciding is addressed by the assignment rather than the document, because the
         * assignment is the thing that says whose turn it is — routing it through the document
         * would need a second lookup to work out which of its steps was meant.
         */
        Route::post('/documents/{document}/submit', [DocumentApprovalController::class, 'submit'])->name('documents.submit');
        Route::post('/documents/{document}/publish', [DocumentApprovalController::class, 'publish'])->name('documents.publish');
        Route::post('/approvals/{assignment}/decide', [DocumentApprovalController::class, 'decide'])->name('approvals.decide');
        Route::post('/approvals/{assignment}/delegate', [DocumentApprovalController::class, 'delegate'])->name('approvals.delegate');
        Route::post('/approval-requests/{approval}/cancel', [DocumentApprovalController::class, 'cancel'])->name('approvals.cancel');

        // Finding records to link to. A GET returning JSON, because it is a picker typing into a
        // field rather than a page.
        Route::get('/relations/search', [DocumentController::class, 'searchRelations'])->name('relations.search');

        /*
         * How the rest of the platform files a document against one of its records.
         *
         * Inside this group deliberately, so module.active and module.tier apply: a company without
         * Document Management on its subscription does not get a second way in through Fleet.
         */
        Route::post('/records/{relation}/{key}/upload', [RecordDocumentController::class, 'store'])->name('records.upload');
        Route::post('/records/{relation}/{key}/attach', [RecordDocumentController::class, 'attach'])->name('records.attach');
        Route::post('/records/{relation}/{key}/detach', [RecordDocumentController::class, 'detach'])->name('records.detach');

        // Signature requests. Addressed by the document, because that is what somebody is looking
        // at when they decide to send it.
        Route::post('/documents/{document}/sign', [DocumentSigningController::class, 'send'])->name('signing.send');
        Route::post('/documents/{document}/sign/cancel', [DocumentSigningController::class, 'cancel'])->name('signing.cancel');

        /*
         * Templates, and generating from them.
         *
         * Generating is a POST on the template rather than on a document, because no document
         * exists yet — it is the thing being created.
         */
        Route::get('/templates', [DocumentTemplateController::class, 'index'])->name('templates');
        Route::post('/templates', [DocumentTemplateController::class, 'store'])->name('templates.store');
        Route::put('/templates/{template}', [DocumentTemplateController::class, 'update'])->name('templates.update');
        Route::delete('/templates/{template}', [DocumentTemplateController::class, 'destroy'])->name('templates.destroy');
        Route::get('/templates/{template}/preview', [DocumentTemplateController::class, 'preview'])->name('templates.preview');
        Route::post('/templates/{template}/generate', [DocumentTemplateController::class, 'generate'])->name('templates.generate');

        // Categories: what a document is, as opposed to where it is filed.
        Route::post('/categories', [DocumentCategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [DocumentCategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [DocumentCategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('/settings', [DocumentSettingsController::class, 'index'])->name('settings');
        Route::put('/settings', [DocumentSettingsController::class, 'update'])->name('settings.update');
    });
