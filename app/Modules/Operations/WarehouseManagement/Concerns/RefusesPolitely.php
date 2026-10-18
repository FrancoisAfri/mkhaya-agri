<?php

namespace App\Modules\Operations\WarehouseManagement\Concerns;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Turns a business rule's refusal into a message on the form rather than a 500.
 *
 * The services throw RuntimeException with a sentence written for the person at the screen ("A-01
 * still holds stock"). HTTP exceptions -- a 403, a 404 -- are not refusals and pass straight
 * through, or an authorisation failure would come back as a polite validation message; so do
 * database errors, whose text is SQL.
 */
trait RefusesPolitely
{
    protected function attempt(Closure $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            // A database error is not a refusal: its message is SQL, which nobody at the screen
            // should read (and which can name tables and values). It goes to the error handler.
            if ($e instanceof HttpExceptionInterface || $e instanceof QueryException) {
                throw $e;
            }

            return back()->withErrors(['refused' => $e->getMessage()])->withInput();
        }

        return back()->with('success', $done);
    }
}
