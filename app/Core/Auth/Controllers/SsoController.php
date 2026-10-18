<?php

namespace App\Core\Auth\Controllers;

use App\Core\Auth\Services\SsoService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The SSO sign-in endpoints (§9.1).
 *
 * Thin: every decision that carries security lives in {@see SsoService}, where it can be tested
 * without an HTTP layer in the way.
 */
class SsoController extends Controller
{
    public function __construct(private readonly SsoService $sso) {}

    /**
     * `POST /auth/sso/discover` — does this address use SSO?
     *
     * Called by the login page as the user types their email, so they see "Continue with your
     * organisation" instead of a password field they cannot use.
     *
     * Answers only whether a connection exists and what to call it. Not whether the *account*
     * exists — that would turn the login page into a way to enumerate a customer's staff.
     */
    public function discover(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'max:255']]);

        $connection = $this->sso->connectionForEmail($validated['email']);

        return response()->json([
            'sso' => $connection !== null,
            'enforced' => (bool) ($connection->enforce_sso ?? false),
            'display_name' => $connection->display_name ?? null,
        ]);
    }

    /** `GET /auth/sso/redirect` — hand the browser to the identity provider. */
    public function redirect(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'max:255']]);

        $connection = $this->sso->connectionForEmail($validated['email']);

        if ($connection === null) {
            return redirect()->route('login')
                ->with('error', 'Single sign-on is not configured for that address.');
        }

        try {
            $begin = $this->sso->beginLogin($connection, route('sso.callback'));
        } catch (Throwable $e) {
            Log::error('SSO redirect failed', ['exception' => $e, 'company' => $connection->company_id]);

            return redirect()->route('login')->with('error',
                'Your organisation’s sign-in service could not be reached. Try again shortly.');
        }

        return redirect()->away($begin['url']);
    }

    /** `GET /auth/sso/callback` — the provider sends the browser back here. */
    public function callback(Request $request): RedirectResponse
    {
        // The provider reports its own failures this way — consent declined, account blocked.
        if ($request->filled('error')) {
            return redirect()->route('login')->with('error',
                'Your organisation’s sign-in service refused the request: '
                .$request->string('error_description')->value() ?: $request->string('error')->value());
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $user = $this->sso->completeLogin($validated['code'], $validated['state']);
        } catch (Throwable $e) {
            // The message is written for the person in front of the screen, and the service raises
            // it deliberately for each refusal, so it is safe to show. Everything else is logged.
            Log::warning('SSO sign-in refused', ['exception' => $e]);

            return redirect()->route('login')->with('error', $e->getMessage());
        }

        Auth::login($user, remember: false);

        // A fresh session id after authentication — otherwise a session fixed before login stays
        // valid afterwards.
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}
