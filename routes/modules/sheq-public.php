<?php

use App\Modules\Operations\Sheq\Http\Controllers\AccessPassController;
use App\Modules\Operations\Sheq\Http\Controllers\ContractorPortalController;
use Illuminate\Support\Facades\Route;

/*
 * A contractor or worker pass, scanned at a gate (CON-12, CON-13).
 *
 * Public by necessity. The person scanning is a guard at a boom holding their own phone: they have
 * no account here and no reason to get one, and a check that requires a login is a check that gets
 * replaced by waving people through. The sixty-four character token in the URL is the credential,
 * and it is also what tells the request which tenant it belongs to — row-level security has
 * nothing else to go on.
 *
 * Kept in its own file, outside the authenticated group in web.php, for the reason the other
 * public portals give in their own comments: declared inside the module's route file it silently
 * inherits `auth`, and every scan redirects to a login page the guard cannot pass. That is exactly
 * what happened on the first attempt here, and the test that caught it is the one asserting a scan
 * answers without an account.
 *
 * Throttled hard. This URL is public and the token is the only thing between a request and a
 * compliance verdict, so the rate limit is what makes working through guesses pointless rather
 * than merely slow. Sixty a minute is far more than a gate produces.
 */
Route::middleware(['web', 'throttle:60,1'])
    ->get('/sheq/pass/{token}', [AccessPassController::class, 'verify'])
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('sheq.pass.verify');

/*
 * The contractor's own view of their compliance (CON-18).
 *
 * Under `supplier-portal` and not under `sheq`, because the credential is the supplier's
 * existing `portal_token` and the page sits inside the portal they already sign into. There is
 * deliberately no second SHEQ login: one contractor holding two credentials for the same
 * customer is one credential they will lose.
 *
 * Public for the same reason the rest of that portal is — the holder has no account here — and
 * throttled harder than the pass verification, because this one accepts a file upload. Ten a
 * minute is far more than a contractor sending certificates produces, and few enough that the
 * endpoint is not worth pointing anything at.
 */
Route::middleware(['web', 'throttle:10,1'])
    ->prefix('supplier-portal/{token}/safety')
    ->name('sheq.portal.')
    ->group(function () {
        Route::get('/', [ContractorPortalController::class, 'show'])->name('show');
        Route::post('/documents', [ContractorPortalController::class, 'upload'])
            ->name('documents.store');
    });
