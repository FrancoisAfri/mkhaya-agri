<?php

use App\Modules\Operations\ProjectManagement\Http\Api\V1\EvidenceApiController;
use App\Modules\Operations\ProjectManagement\Http\Api\V1\FieldApiController;
use App\Modules\Operations\ProjectManagement\Http\Api\V1\ProjectApiController;
use App\Modules\Operations\ProjectManagement\Http\Api\V1\SyncApiController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
 * Project Management API — /api/v1/pm/ (§4 of the PM requirements).
 *
 * The stack, in order:
 *   stateful              lets a first-party browser session authenticate here as well as a token.
 *                         Without it `auth:sanctum` only ever accepts a bearer token, and the
 *                         offline field shell — which is a page of this application, carrying the
 *                         session cookie and no token — is answered 401 on every sync. It is
 *                         applied to this prefix rather than the whole api group so third-party
 *                         API clients elsewhere keep their token-only contract.
 *   auth:sanctum          bearer token, or that session, identifies the user
 *   pm.tenant             refuses the request if no tenant resolved (the api group's
 *                         ResolveCompanyFromToken sets it from the token's user)
 *   module.active         the tenant's plan/licence must include Project Management
 *   pm.idempotent         a repeat of an Idempotency-Key replays the first response
 *
 * Errors are problem+json for every route under this prefix (see bootstrap/app.php).
 * Routes bind on the public UUID; an id from another tenant does not resolve, so the client
 * receives 404 rather than 403 — a 403 would confirm the record exists (PM-1010).
 */
Route::middleware([
    EnsureFrontendRequestsAreStateful::class,
    'auth:sanctum',
    'pm.tenant',
    'module.active:project_management',
])
    ->prefix('v1/pm')
    ->name('api.pm.')
    ->group(function () {
        Route::get('/projects', [ProjectApiController::class, 'index'])->name('projects.index');
        Route::get('/projects/{project}', [ProjectApiController::class, 'show'])->name('projects.show');

        // The contract, generated from these routes so it cannot drift from them (EPIC-070).
        Route::get('/openapi.json', function (\App\Modules\Operations\ProjectManagement\Support\Api\OpenApiGenerator $generator) {
            return response()->json($generator->generate(), 200, [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        })->name('openapi');

        // ── The field loop (EPIC-060, EPIC-061) ───────────────────────────────
        // One request assembles a day's work; `?since=` makes every sync after the first a delta.
        Route::get('/sync', [SyncApiController::class, 'show'])->name('sync');

        // ── Evidence (PM-1003) ────────────────────────────────────────────────
        // The vocabulary is public to any authenticated client: a device validates against it
        // before spending a bad connection on a round trip.
        Route::get('/evidence/schema', [EvidenceApiController::class, 'schema'])->name('evidence.schema');
        Route::get('/evidence', [EvidenceApiController::class, 'index'])->name('evidence.index');
        Route::get('/evidence/dead-letters', [EvidenceApiController::class, 'deadLetters'])->name('evidence.dead-letters');

        // Mutations are idempotent when the client supplies a key.
        Route::middleware('pm.idempotent')->group(function () {
            Route::post('/projects', [ProjectApiController::class, 'store'])->name('projects.store');
            Route::patch('/projects/{project}', [ProjectApiController::class, 'update'])->name('projects.update');
            Route::delete('/projects/{project}', [ProjectApiController::class, 'destroy'])->name('projects.destroy');

            // Write-backs from the field. Idempotent because a device that loses signal mid-sync
            // retries its whole queue, and the second attempt must change nothing.
            Route::patch('/tasks/{uuid}', [FieldApiController::class, 'updateTask'])->name('tasks.update');
            Route::patch('/checklist/{uuid}', [FieldApiController::class, 'updateChecklistItem'])->name('checklist.update');
            Route::post('/time', [FieldApiController::class, 'logTime'])->name('time.store');

            Route::post('/evidence/{uuid}/supersede', [EvidenceApiController::class, 'supersede'])->name('evidence.supersede');
            Route::post('/evidence/dead-letters/{uuid}/replay', [EvidenceApiController::class, 'replay'])->name('evidence.replay');
        });

        // Ingestion requires a key: this is the sync path, and a retried batch that duplicated a
        // shift's work would be the worst failure the field loop could have (PM-1003, PM-ADR-004).
        Route::post('/evidence', [EvidenceApiController::class, 'store'])
            ->middleware('pm.idempotent:required')
            ->name('evidence.store');
    });
