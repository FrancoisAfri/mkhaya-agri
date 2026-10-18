<?php

namespace App\Modules\Operations\Sheq\Exceptions;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A refused business rule, shown to the person instead of crashing in front of them.
 *
 * Every service in this module signals a refusal by throwing a RuntimeException or an
 * InvalidArgumentException whose message is already written for the person reading it — "only 2 of
 * Safety boots left in stock", "you raised this request, so you cannot approve it", "an extension
 * needs a reason". That convention is deliberate and the API has honoured it since it was written:
 * {@see \App\Modules\Operations\Sheq\Http\Api\ProblemDetails} turns those into a 422 problem
 * document rather than a 500.
 *
 * The browser never got the same treatment. The identical refusal, reached from a screen instead of
 * a token, rendered Laravel's error page: a red "Internal Server Error", a class name, a file path
 * and a stack trace — for a sentence that already said exactly what to do about it. A visitor could
 * not be signed in because no approval workflow was configured, and the person was shown
 * `RuntimeException at ApprovalService.php:58`.
 *
 * So this is the web half of the same rule. It is a renderer rather than a new exception class
 * because there are 194 throw sites across the module: converting them one at a time would convert
 * most of them, and the ones missed would be exactly the rare paths nobody exercises until a
 * customer does.
 *
 * ── What is deliberately NOT caught ──────────────────────────────────────────
 *
 * Only exceptions thrown from this module's own files. A RuntimeException raised inside a vendor
 * package during a SHEQ request is not a refusal written for anybody and may carry a path, a query
 * or a credential, so it keeps going to the error handler and the log. The module never throws
 * RuntimeException for an internal failure — those surface as QueryException, TypeError and the
 * like, which are untouched here.
 */
class RefusedRule
{
    /** This module's own code. A refusal is only a refusal if we wrote it. */
    private const OWN_CODE = 'app/Modules/Operations/Sheq/';

    /**
     * Render a SHEQ refusal for a browser, or return null to let the handler deal with it.
     *
     * Null means "not mine": anything that is not one of the two refusal types, anything thrown
     * outside this module, and anything on an API route, which has its own problem-document shape.
     */
    public static function render(Throwable $e, Request $request): ?RedirectResponse
    {
        if (! self::isRefusal($e) || ! self::thrownBySheq($e) || ! self::isScreen($request)) {
            return null;
        }

        $message = trim($e->getMessage());

        if ($message === '') {
            return null;
        }

        /*
         * Back to where they were, with what they typed.
         *
         * `withInput()` is the part that matters: a visitor's details, an incident description, a
         * permit's scope of work — all of it retyped otherwise, which is how somebody learns not to
         * use the system. The message goes to `error` because the layout already shows that as a
         * snackbar everywhere, and onto an errors bag as well so a form can show it inline.
         */
        return back()
            ->withInput()
            ->with('error', $message)
            ->withErrors(['sheq' => $message]);
    }

    private static function isRefusal(Throwable $e): bool
    {
        /*
         * An HttpException is NOT a refused business rule, even though it is a RuntimeException.
         *
         * `AccessDeniedHttpException` extends `HttpException` extends `RuntimeException`, so this
         * caught it and turned a 403 into a 302 with a snackbar. That is wrong in a way that
         * matters: an authorisation failure rendered as a redirect is invisible to an API client,
         * to a security audit and to any test asserting the status — and the person who was
         * refused is handed the page they were refused, with a message, which reads as a
         * validation problem rather than as "you may not look at this".
         *
         * An HttpException already carries the status it wants and the framework renders it
         * correctly. Found when the site entitlement (SEC-2) started throwing one from inside
         * this module, which is the first SHEQ code to do so directly — `abort_unless()` throws
         * from the framework, so `thrownBySheq()` was already filtering those out.
         */
        if ($e instanceof HttpExceptionInterface) {
            return false;
        }

        return $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException;
    }

    private static function thrownBySheq(Throwable $e): bool
    {
        return str_contains(str_replace('\\', '/', $e->getFile()), self::OWN_CODE);
    }

    /**
     * Somebody looking at a screen, rather than a client holding a token.
     *
     * Deliberately not restricted to `sheq/*`. Fleet, Assets and the other modules raise incidents
     * and actions through SHEQ's bridges, so the identical refusal is reachable from their screens
     * too — and it was 500ing there for the same reason. What makes a refusal safe to show is that
     * this module wrote the message, which `thrownBySheq` already establishes; where the person
     * happens to be standing does not change that.
     *
     * The API is excluded because it is answered before this by RFC 9457 problem details, and a
     * redirect would be useless to a token holder.
     */
    private static function isScreen(Request $request): bool
    {
        return ! $request->is('api/*') && ! $request->expectsJson();
    }
}
