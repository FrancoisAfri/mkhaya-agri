<?php

namespace App\Core\Auth\Controllers;

use App\Core\Auth\Mail\SendOtpsMail;
use App\Core\Auth\Support\LandingPage;
use App\Core\Auth\Notifications\TwoFactorDeliveryFailedNotification;
use App\Core\Auth\Requests\RegisterRequest;
use App\Core\Auth\Services\AuthService;
use App\Core\Module\Models\Modules;
use App\Core\SMS\Services\SmsService;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Shared\Traits\BuildsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    use BuildsNavigation;

    public function __construct(
        protected AuthService $service
    ) {}

    // ── Password-based login ──────────────────────────────────────────────────

    public function showLoginForm(): Response
    {
        return Inertia::render('Pages/Auth/Login', [
            // Azure AD sign-in is only offered when the Board & Committee
            // Management module is installed and active (board members use Azure AD).
            'boardModuleActive' => Modules::where('code_name', 'board_committee')
                ->where('active', 1)
                ->exists(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Emails are stored normalised (lower-cased + trimmed, see User::setEmailAttribute),
        // so normalise the login input the same way — "Khaya@Gmail.com " must match "khaya@gmail.com".
        $credentials['email'] = strtolower(trim($credentials['email']));

        $throttleKey = 'login:'.md5($credentials['email'].'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            activity('auth')
                ->withProperties(['email' => $credentials['email'], 'ip' => $request->ip()])
                ->log('Login rate-limited');

            return back()->withErrors([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);

            /** @var User $user */
            $user = Auth::user();

            activity('auth')
                ->causedBy($user)
                ->withProperties(['ip' => $request->ip()])
                ->log('User logged in');

            // ── 2FA ──────────────────────────────────────────────────────────
            if (SmsService::isTwoFactorEnabled()) {
                $phone = $user->person?->cell_number;
                $otp = $this->service->generate2FaOtp($user->id);

                Auth::logout();

                if ($phone) {
                    // Primary path: SMS
                    session([
                        '2fa_pending_user_id' => $user->id,
                        '2fa_pending_remember' => $request->boolean('remember'),
                        '2fa_phone_masked' => $this->maskPhone($phone),
                        '2fa_pending_expires_at' => now()->addMinutes(15)->timestamp,
                    ]);

                    SmsService::send(
                        $phone,
                        "Your Mkhaya Agri login OTP is: {$otp}. Valid for 10 minutes. Do not share this code."
                    );

                    return redirect()->route('2fa.index');
                }

                // Fallback: no phone — try email
                if ($user->email) {
                    try {
                        Mail::to($user->email)
                            ->send(new SendOtpsMail($otp, $user->person?->first_name ?? 'User'));

                        session([
                            '2fa_pending_user_id' => $user->id,
                            '2fa_pending_remember' => $request->boolean('remember'),
                            '2fa_phone_masked' => null,
                            '2fa_pending_expires_at' => now()->addMinutes(15)->timestamp,
                        ]);

                        return redirect()->route('2fa.index');
                    } catch (\Throwable $e) {
                        // Email failed — notify admins and block login
                        $this->notifyAdmins2FaFailed($user, 'No phone number on record and email delivery failed.');

                        activity('auth')
                            ->withProperties(['user_id' => $user->id, 'error' => $e->getMessage()])
                            ->log('2FA OTP delivery failed');

                        return back()->withErrors([
                            'email' => 'There was an error sending your OTP and your phone number is not configured. Please contact the system administrator.',
                        ]);
                    }
                }

                // No phone, no email — hard block
                $this->notifyAdmins2FaFailed($user, 'No phone number and no email address on record.');

                activity('auth')
                    ->withProperties(['user_id' => $user->id])
                    ->log('2FA OTP delivery failed — no contact details');

                return back()->withErrors([
                    'email' => 'There was an error sending your OTP and your phone number is not configured. Please contact the system administrator.',
                ]);
            }
            // ─────────────────────────────────────────────────────────────────

            $request->session()->regenerate();

            // Not always the dashboard: a project client or contractor is created with no role and
            // no module access, so the dashboard answers them with a 403 they can never satisfy.
            return redirect()->to(LandingPage::for($request->user()));
        }

        RateLimiter::hit($throttleKey, 60);

        activity('auth')
            ->withProperties(['email' => $credentials['email'], 'ip' => $request->ip()])
            ->log('Failed login attempt');

        return back()->withErrors([
            'email' => 'These credentials do not match our records.',
        ]);
    }

    // ── Two-Factor Authentication ─────────────────────────────────────────────

    public function showTwoFactor(): Response|RedirectResponse
    {
        if (! session('2fa_pending_user_id')) {
            return redirect()->route('login');
        }

        $expiresAt = session('2fa_pending_expires_at');
        if (! $expiresAt || now()->timestamp > $expiresAt) {
            session()->forget(['2fa_pending_user_id', '2fa_pending_remember', '2fa_phone_masked', '2fa_pending_expires_at']);

            return redirect()->route('login')->withErrors(['email' => 'Session expired. Please log in again.']);
        }

        return Inertia::render('Pages/Auth/TwoFactor', [
            'phoneMasked' => session('2fa_phone_masked', '***'),
        ]);
    }

    public function verifyTwoFactor(Request $request): RedirectResponse
    {
        $pendingUserId = session('2fa_pending_user_id');

        if (! $pendingUserId) {
            return redirect()->route('login')->withErrors(['otp' => 'Session expired. Please log in again.']);
        }

        $expiresAt = session('2fa_pending_expires_at');
        if (! $expiresAt || now()->timestamp > $expiresAt) {
            session()->forget(['2fa_pending_user_id', '2fa_pending_remember', '2fa_phone_masked', '2fa_pending_expires_at']);

            return redirect()->route('login')->withErrors(['email' => 'Session expired. Please log in again.']);
        }

        $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $ip = $request->ip();
        $user = User::find($pendingUserId);

        if (! $user) {
            return redirect()->route('login')->withErrors(['otp' => 'Invalid session.']);
        }

        try {
            $this->service->verify2FaOtp($pendingUserId, $request->otp);

            Auth::login($user, session('2fa_pending_remember', false));

            session()->forget(['2fa_pending_user_id', '2fa_pending_remember', '2fa_phone_masked', '2fa_pending_expires_at']);
            $request->session()->regenerate();

            activity('auth')
                ->causedBy($user)
                ->withProperties(['ip' => $ip])
                ->log('User completed 2FA login');

            // '/' is the dashboard, which a portal-only user cannot open.
            return redirect()->intended(LandingPage::for($user));
        } catch (\Exception $e) {
            activity('auth')
                ->withProperties(['user_id' => $pendingUserId, 'ip' => $ip])
                ->log('Failed 2FA attempt');

            return back()->withErrors(['otp' => $e->getMessage()]);
        }
    }

    public function resendTwoFactor(Request $request): RedirectResponse
    {
        $pendingUserId = session('2fa_pending_user_id');

        if (! $pendingUserId) {
            return redirect()->route('login');
        }

        $throttleKey = '2fa_resend:'.$pendingUserId.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->with('twoFactorResult', [
                'success' => false,
                'message' => "Too many resend requests. Please wait {$seconds} seconds.",
            ]);
        }
        RateLimiter::hit($throttleKey, 300);

        $user = User::find($pendingUserId);
        $phone = $user?->person?->cell_number;
        $otp = $this->service->generate2FaOtp($pendingUserId);

        if ($phone) {
            SmsService::send($phone, "Your Mkhaya Agri login OTP is: {$otp}. Valid for 10 minutes. Do not share this code.");

            return back()->with('twoFactorResult', ['success' => true, 'message' => 'OTP resent to your registered phone number.']);
        }

        if ($user?->email) {
            try {
                Mail::to($user->email)
                    ->send(new SendOtpsMail($otp, $user->person?->first_name ?? 'User'));

                return back()->with('twoFactorResult', ['success' => true, 'message' => 'OTP resent to your email address.']);
            } catch (\Throwable) {
                $this->notifyAdmins2FaFailed($user, 'No phone number on record and email delivery failed during OTP resend.');

                return back()->with('twoFactorResult', [
                    'success' => false,
                    'message' => 'There was an error sending your OTP and your phone number is not configured. Please contact the system administrator.',
                ]);
            }
        }

        $this->notifyAdmins2FaFailed($user, 'No phone number and no email address on record during OTP resend.');

        return back()->with('twoFactorResult', [
            'success' => false,
            'message' => 'There was an error sending your OTP and your phone number is not configured. Please contact the system administrator.',
        ]);
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    public function logout(Request $request): RedirectResponse
    {
        activity('auth')
            ->causedBy(Auth::user())
            ->withProperties(['ip' => $request->ip()])
            ->log('User logged out');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // ── Registration ──────────────────────────────────────────────────────────

    public function register(RegisterRequest $request): RedirectResponse
    {
        $this->service->register($request->validated());

        return redirect()->route('login')->with('status', 'Account created. You can now log in.');
    }

    // ── OTP email login (existing) ────────────────────────────────────────────

    public function sendOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $throttleKey = 'otp_send:'.md5($request->email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->with('otpResult', [
                'success' => false,
                'message' => "Too many OTP requests. Please wait {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($throttleKey, 600);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            activity('auth')
                ->withProperties(['email' => $request->email, 'ip' => $request->ip()])
                ->log('OTP requested for non-existing email');

            return back()->with('otpResult', [
                'success' => true,
                'message' => 'If that email is registered, an OTP has been sent.',
            ]);
        }

        try {
            $otp = $this->service->generateOtp($request->email);

            /*
             * Sent, not queued, and tolerant of a user with no personnel record.
             *
             * Two faults met here, and either one alone closed the only other way in for somebody
             * who has forgotten their password.
             *
             * `$user->person` is null for any account without an `hr_people` row — a client, a
             * portal user, anyone created outside the employee flow. Reading `->first_name` on it
             * threw, the catch below turned that into "Failed to send OTP. Please try again
             * later.", and trying again could never help. The two sibling call sites in this class
             * already guard it; this one was missed.
             *
             * And it queued the mail. An OTP that waits for a worker is not an OTP — on an install
             * with no worker running it never leaves at all, which is silent because nothing
             * fails. The siblings send immediately, and a one-time code is worth the moment it
             * costs.
             */
            Mail::to($request->email)
                ->send(new SendOtpsMail($otp, $user->person?->first_name ?? 'User'));

            activity('auth')
                ->causedBy($user)
                ->withProperties(['ip' => $request->ip()])
                ->log('OTP queued');

            return back()->with('otpResult', [
                'success' => true,
                'message' => 'If that email is registered, an OTP has been sent.',
            ]);
        } catch (\Exception $e) {
            report($e);

            return back()->with('otpResult', [
                'success' => false,
                'message' => 'Failed to send OTP. Please try again later.',
            ]);
        }
    }

    public function loginWithOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $ip = $request->ip();
        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return back()->withErrors([
                'otp' => 'Invalid OTP or email.',
            ])->withInput($request->except('otp'));
        }

        try {
            $this->service->verifyOtp($request->email, $request->otp);

            Auth::login($user, $request->boolean('remember_otp'));
            $request->session()->regenerate();

            activity('auth')
                ->causedBy($user)
                ->withProperties(['ip' => $ip])
                ->log('User logged in via OTP');

            return redirect()->intended(LandingPage::for($user));
        } catch (\Exception $e) {
            activity('auth')
                ->withProperties(['email' => $request->email, 'ip' => $ip])
                ->log('Failed OTP login attempt');

            return back()->withErrors([
                'otp' => $e->getMessage(),
            ])->withInput($request->except('otp'));
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function maskPhone(string $phone): string
    {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        $len = strlen($cleaned);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($cleaned, 0, 3).str_repeat('*', max(0, $len - 5)).substr($cleaned, -2);
    }

    private function notifyAdmins2FaFailed(User $user, string $reason): void
    {
        User::role(['administrator', 'super_admin'])->each(
            fn (User $admin) => $admin->notify(new TwoFactorDeliveryFailedNotification($user, $reason))
        );
    }
}
