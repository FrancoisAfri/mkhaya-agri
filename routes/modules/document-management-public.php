<?php

use App\Modules\Operations\DocumentManagement\Http\Controllers\PublicDocumentShareController;
use Illuminate\Support\Facades\Route;

/*
 * A shared document, opened by somebody outside the organisation.
 *
 * Public by necessity: the recipient is an auditor, a bank or a client with no account here and no
 * reason to get one. The 64-character token in the URL is the credential, and it is what tells the
 * request which tenant it belongs to — row-level security has nothing else to go on.
 *
 * Kept in its own file, outside the authenticated group in web.php, for the reason the payroll
 * portal's own comment gives: declared inside the module's route file it silently inherits `auth`
 * and every link redirects to a login page the recipient cannot pass.
 *
 * Throttled hard. These URLs are public and the token is the only thing between a request and a
 * document, so the rate limit is what makes working through guesses pointless rather than merely
 * slow.
 */
Route::middleware(['web', 'throttle:30,1'])
    ->prefix('shared/documents')
    ->name('documents.shared.')
    ->group(function () {
        Route::get('/{token}', [PublicDocumentShareController::class, 'show'])->name('show');

        // GET asks for the password, POST answers it. A mail scanner following the link must not be
        // able to spend an attempt, and the answer must not end up in a server log as a query string.
        Route::post('/{token}/unlock', [PublicDocumentShareController::class, 'unlock'])->name('unlock');

        /*
         * The emailed code. Both POST, for the same reasons as the password — and the send is a POST
         * rather than a GET so a mail scanner opening the link cannot trigger an email to the
         * recipient before they have read the first one.
         */
        Route::post('/{token}/send-code', [PublicDocumentShareController::class, 'sendCode'])->name('send-code');
        Route::post('/{token}/verify-code', [PublicDocumentShareController::class, 'verifyCode'])->name('verify-code');

        Route::get('/{token}/view', [PublicDocumentShareController::class, 'view'])->name('view');
        Route::get('/{token}/download', [PublicDocumentShareController::class, 'download'])->name('download');
    });
