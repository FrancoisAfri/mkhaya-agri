<?php

namespace App\Core\Auth\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Core\UserManagement\Models\PasswordHistory;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forgetting a password, and getting back in without asking anybody.
 *
 * There was no way to do this. The login page offered "Forgot Password?" as an anchor with no
 * href, and no reset routes existed behind it, so the link looked like a way out and did nothing.
 * The only remaining route in was a one-time code, and that threw for any account without a
 * personnel record — which is every client and subcontractor. Somebody who forgot their password
 * had to find an administrator.
 *
 * ── What is deliberate here ──────────────────────────────────────────────────
 *
 * The same answer whether the address is registered or not. Anything else turns this form into a
 * way of asking which email addresses have accounts.
 *
 * Sent, not queued. The queue on at least one installation has not been consumed since July, and
 * a reset mail that waits for a worker is a reset that silently never arrives. Laravel's broker
 * sends through the notification, which is dispatched immediately because the notification does
 * not implement ShouldQueue.
 *
 * Nothing here reads the personnel record. Identity lives in `hr_people` and plenty of accounts
 * have none; that is exactly the population this has to serve.
 *
 * Disabled accounts are refused, quietly. CheckBanned logs out anybody whose status is 0 on their
 * next request, so letting them set a password would hand them a credential that cannot be used.
 */
class PasswordResetController extends Controller
{
    /** Attempts allowed per email and IP before the form stops answering. */
    private const MAX_REQUESTS = 5;

    private const DECAY_SECONDS = 900;

    public function request(): Response
    {
        return Inertia::render('Pages/Auth/ForgotPassword');
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $key = 'password_reset:'.md5(Str::lower($request->email).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS)) {
            return back()->with('status', $this->sameAnswer(RateLimiter::availableIn($key)));
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        /*
         * A disabled account is treated as if it did not exist, rather than refused by name.
         * Refusing it out loud would tell an unauthenticated visitor that the address is real and
         * that the account is suspended, which is more than they are owed.
         */
        $user = User::withoutGlobalScopes()->where('email', $request->email)->first();

        if ($user && (int) $user->status !== 0) {
            Password::sendResetLink(['email' => $request->email]);

            activity('auth')->causedBy($user)
                ->withProperties(['ip' => $request->ip()])
                ->log('Password reset link requested');
        } else {
            activity('auth')
                ->withProperties(['email' => $request->email, 'ip' => $request->ip()])
                ->log('Password reset requested for an unusable address');
        }

        return back()->with('status', $this->sameAnswer());
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Pages/Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            // The same rules the signed-in change uses, so a reset cannot be the weaker door in.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // No reuse of the last five, exactly as PasswordChangeController enforces.
                $reused = PasswordHistory::where('user_id', $user->id)
                    ->latest()->take(5)->get()
                    ->contains(fn ($h) => Hash::check($password, $h->password));

                if ($reused) {
                    throw new \RuntimeException('reused');
                }

                $user->forceFill([
                    'password'            => Hash::make($password),
                    'password_changed_at' => now(),
                    'remember_token'      => Str::random(60),
                ])->save();

                PasswordHistory::createPassword($user->id, $user->password);

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __('Your password has been reset. You can sign in now.'));
        }

        return back()->withErrors([
            'email' => $status === Password::INVALID_TOKEN
                ? __('That reset link has expired or has already been used. Please ask for a new one.')
                : __('We could not reset the password with that link. Please ask for a new one.'),
        ]);
    }

    /** One answer, whatever the address turns out to be. */
    private function sameAnswer(?int $seconds = null): string
    {
        return $seconds
            ? __('If that email is registered, a reset link is on its way. You can ask again in :seconds seconds.', ['seconds' => $seconds])
            : __('If that email is registered, a reset link is on its way. It expires in an hour.');
    }
}
