<?php

use App\Modules\Operations\Sheq\Http\Api\V1\FileApiController;
use App\Modules\Operations\Sheq\Http\Api\V1\ResourceApiController;
use App\Modules\Operations\Sheq\Http\Api\V1\SyncApiController;
use App\Modules\Operations\Sheq\Http\Api\V1\WebhookApiController;
use Illuminate\Support\Facades\Route;

/*
 * SHEQ API — /api/v1/sheq/ (§8.3).
 *
 * Errors on every path below are RFC 9457 problem details (SHEQ-API-4), applied by a path-scoped
 * renderer in bootstrap/app.php rather than middleware — Laravel's priority sorting hoists
 * `auth:sanctum` ahead of unlisted middleware, so a 401 would have been the one response that
 * escaped the contract.
 *
 * The stack, in order:
 *   auth:sanctum          bearer token identifies the user
 *   sheq.tenant           refuses the request if no tenant resolved (the api group's
 *                         ResolveCompanyFromToken sets it from the token's user)
 *   module.active         the tenant's plan must include SHEQ
 *
 * No idempotency middleware on the sync routes, deliberately. SHEQ-OFF-2 puts the idempotency key
 * on each operation rather than on the request, which is the stronger guarantee: a device retrying
 * a batch where half the operations already landed gets those replayed and the rest applied. A
 * per-request key would make the whole batch all-or-nothing.
 */
Route::middleware(['auth:sanctum', 'sheq.tenant', 'module.active:sheq'])
    ->prefix('v1/sheq')
    ->name('api.sheq.')
    ->group(function () {
        // ── Discovery ─────────────────────────────────────────────────────────
        // Generated from the same declarations the controllers read, so the description cannot
        // disagree with the implementation.
        Route::get('/openapi.json', [ResourceApiController::class, 'openapi'])->name('openapi');
        Route::get('/catalogue', [ResourceApiController::class, 'catalogue'])->name('catalogue');

        // ── Offline sync (§6.1) ───────────────────────────────────────────────
        Route::get('/sync/pull', [SyncApiController::class, 'pull'])->name('sync.pull');
        Route::post('/sync/push', [SyncApiController::class, 'push'])->name('sync.push');

        /*
         * ── Evidence, a part at a time (SHEQ-OFF-7) ───────────────────────────
         *
         * The resumable-upload machinery has always been in FileService and the API had no file
         * endpoint, so the only way to attach a photograph was the browser form — one request
         * carrying the whole file. That is the one thing a phone at a shaft on a weak signal
         * cannot reliably do: it fails at ninety per cent, starts again from nothing, and the
         * person gives up, leaving an injury described with no picture of the missing guard.
         *
         * PUT for a part, because sending the same part twice must mean the same thing as sending
         * it once.
         */
        Route::post('/files', [FileApiController::class, 'begin'])->name('files.begin');
        Route::put('/files/{uuid}/chunks/{sequence}', [FileApiController::class, 'chunk'])
            ->whereUuid('uuid')->whereNumber('sequence')->name('files.chunk');
        Route::get('/files/{uuid}', [FileApiController::class, 'show'])
            ->whereUuid('uuid')->name('files.show');

        // What a device shows in its sync indicator (SHEQ-OFF-9).
        Route::get('/sync/status', [SyncApiController::class, 'status'])->name('sync.status');

        // ── Conflicts (SHEQ-OFF-5) ────────────────────────────────────────────
        Route::get('/sync/conflicts', [SyncApiController::class, 'conflicts'])->name('sync.conflicts');
        Route::post('/sync/conflicts/{uuid}/resolve', [SyncApiController::class, 'resolveConflict'])
            ->whereUuid('uuid')->name('sync.conflicts.resolve');

        // ── Webhook subscriptions (§8.2, SHEQ-API-5) ──────────────────────────
        Route::get('/events', [WebhookApiController::class, 'events'])->name('events');
        Route::get('/webhooks', [WebhookApiController::class, 'index'])->name('webhooks.index');
        Route::post('/webhooks', [WebhookApiController::class, 'store'])->name('webhooks.store');
        Route::patch('/webhooks/{uuid}', [WebhookApiController::class, 'update'])
            ->whereUuid('uuid')->name('webhooks.update');
        Route::post('/webhooks/{uuid}/rotate', [WebhookApiController::class, 'rotate'])
            ->whereUuid('uuid')->name('webhooks.rotate');
        Route::delete('/webhooks/{uuid}', [WebhookApiController::class, 'destroy'])
            ->whereUuid('uuid')->name('webhooks.destroy');

        /*
         * ── Registers (§8.3) ──────────────────────────────────────────────────
         *
         * Last, and constrained by a regex, so `/webhooks` and `/sync/...` are matched by their own
         * routes first. Without the constraint this wildcard would swallow every path above and
         * answer "no such resource" to a perfectly good webhooks call.
         *
         * Read-only by design. Writes into SHEQ carry classification, statutory obligations and
         * evidence-ledger entries as side effects; an integration creating incidents through a
         * generic CRUD endpoint would bypass all of it and produce records that look real and are
         * not. Where writes are wanted they get purpose-built endpoints with the rules attached.
         */
        Route::get('/{resource}', [ResourceApiController::class, 'index'])
            ->where('resource', '[a-z-]+')->name('resource.index');
        Route::get('/{resource}/{uuid}', [ResourceApiController::class, 'show'])
            ->where('resource', '[a-z-]+')->whereUuid('uuid')->name('resource.show');
    });

/*
 * Gate webhook — the vendor posts here (§ access integrations).
 *
 * Outside the Sanctum group deliberately: an access controller behind a customer's firewall has no
 * user session and cannot hold a rotating token. It authenticates with the integration's own key,
 * checked by the controller against the encrypted value on the row, and the tenant comes from that
 * record rather than from anything in the request.
 */
Route::post('/v1/sheq/gate/{uuid}', [\App\Modules\Operations\Sheq\Http\Api\V1\GateWebhookController::class, 'handle'])
    ->whereUuid('uuid')->name('api.sheq.gate.webhook');

/*
 * Mobile app endpoints (§6.2).
 *
 * Same middleware as the rest of the SHEQ API. Kept apart from the resource routes because these
 * are shaped for a screen rather than for a register — one call per screen, on the assumption the
 * connection is poor and expensive.
 */
Route::middleware(['auth:sanctum', 'sheq.tenant', 'module.active:sheq'])
    ->prefix('v1/sheq/mobile')->name('api.sheq.mobile.')
    ->group(function () {
        $c = \App\Modules\Operations\Sheq\Http\Api\V1\MobileApiController::class;

        Route::get('/me', [$c, 'me'])->name('me');
        Route::get('/bootstrap', [$c, 'bootstrap'])->name('bootstrap');
        Route::get('/gate', [$c, 'gate'])->name('gate');
        Route::get('/induction', [$c, 'induction'])->name('induction');
        Route::post('/induction/{uuid}/complete', [$c, 'completeInduction'])
            ->whereUuid('uuid')->name('induction.complete');
        Route::get('/scan/{code}', [$c, 'scan'])->name('scan');
        Route::post('/equipment/{uuid}/inspect', [$c, 'inspectEquipment'])
            ->whereUuid('uuid')->name('equipment.inspect');
        Route::post('/ppe/issue', [$c, 'issuePpe'])->name('ppe.issue');
        Route::get('/approvals', [$c, 'approvals'])->name('approvals');
        Route::post('/approvals/{uuid}/decide', [$c, 'decide'])
            ->whereUuid('uuid')->name('approvals.decide');
    });
