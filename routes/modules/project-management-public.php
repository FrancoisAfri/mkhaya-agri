<?php

use App\Modules\Operations\ProjectManagement\Http\Controllers\InviteController;
use Illuminate\Support\Facades\Route;

/**
 * Public (unauthenticated) Project Management endpoints — taking up a portal invitation.
 *
 * Included from web.php OUTSIDE the auth group, and deliberately outside `module.active` and
 * `module.tier` too: the person following this link has no account, no company context and no
 * module tier, which is the entire point. The invitation token is the whole of the authorisation,
 * and MemberInviteService checks that it is unused, unrevoked and in date before anything happens.
 *
 * Throttled because these are the only two Project Management routes reachable without a session.
 * A 64-character token is not realistically guessable, so the limit is there to keep the pages
 * from being used as an unauthenticated load generator rather than to stop a search.
 */
Route::middleware(['web', 'throttle:10,1'])->group(function () {
    Route::prefix('project-management/invite')->name('project-management.invite.')->group(function () {
        Route::get('/{token}', [InviteController::class, 'show'])->name('show');
        Route::post('/{token}', [InviteController::class, 'accept'])->name('accept');
    });
});
