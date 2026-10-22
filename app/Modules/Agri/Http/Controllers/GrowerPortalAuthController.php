<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agri\Models\GrowerAccount;
use App\Modules\Agri\Services\GrowerPortal;
use App\Modules\Tenancy\Services\CompanyBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Getting a contracted grower into their own portal, and no further: a one-time code to the mobile
 * number or email address the company invited, or the password they set. Throttled by address and
 * origin; the same answer whether or not the address is on an account, so the page cannot be used
 * to find out who the company's growers are.
 */
class GrowerPortalAuthController extends Controller
{
    public function __construct(private readonly GrowerPortal $portal) {}

    /** The brand a portal page wears: the company's own where white-labelled, else the product's. */
    public static function brand(?int $companyId): array
    {
        $b = app(CompanyBranding::class)->for($companyId, withLogoData: false);

        return ['white_label' => $b['white_label'], 'name' => $b['name'], 'company' => $b['company_name'],
            'logo_url' => $b['white_label'] ? ($b['has_logo'] ? route('grower.portal.logo', [$b['company_uuid'], 'v' => $b['logo_version']]) : null) : CompanyBranding::DEFAULT_LOGO,
            'powered_by' => ! $b['white_label']];
    }

    public function showLogin(Request $request): Response
    {
        $companyId = $request->query('c') ? DB::table('companies')->where('uuid', (string) $request->query('c'))->value('id') : null;

        return Inertia::render('Modules/Agri/GrowerPortal/Login', [
            'company' => $companyId ? (string) $request->query('c') : null,
            'brand' => self::brand($companyId ? (int) $companyId : null),
            'pending' => $request->session()->get('grower_login'),
            'locale' => $request->session()->get('grower_locale', 'en'),
        ]);
    }

    /** Send a sign-in code. */
    public function requestCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:190'], 'company' => ['nullable', 'string', 'max:64'], 'via' => ['nullable', Rule::in(['whatsapp', 'sms'])]]);
        $this->throttle('grower-code:'.mb_strtolower($data['identifier']).'|'.$request->ip(), 5, 600);

        $account = $this->portal->find($data['identifier'], $data['company'] ?? null);
        if (! $account && $this->portal->findRevoked($data['identifier'], $data['company'] ?? null)) {
            throw ValidationException::withMessages(['identifier' => 'Your access to the grower portal was withdrawn. Please contact the company.']);
        }
        if ($account && $account->canSignIn()) {
            $this->portal->sendCode($account, $data['identifier'], $data['via'] ?? null, $request->ip(), $request->userAgent());
        }
        $request->session()->put('grower_login', ['identifier' => $data['identifier'], 'company' => $data['company'] ?? null]);

        return back()->with('success', 'If that number or address is on a grower account, a code is on its way. It expires in '.GrowerPortal::CODE_MINUTES.' minutes.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:190'], 'company' => ['nullable', 'string', 'max:64'], 'code' => ['required', 'string', 'max:12']]);
        $this->throttle('grower-verify:'.mb_strtolower($data['identifier']).'|'.$request->ip(), 10, 600);
        $account = $this->portal->find($data['identifier'], $data['company'] ?? null);
        if (! $account || ! $this->portal->verifyCode($account, $data['code'], $request->ip(), $request->userAgent())) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }

        return $this->signIn($request, $account);
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:190'], 'company' => ['nullable', 'string', 'max:64'], 'password' => ['required', 'string', 'max:200']]);
        $this->throttle('grower-password:'.mb_strtolower($data['identifier']).'|'.$request->ip(), 10, 600);
        $account = $this->portal->find($data['identifier'], $data['company'] ?? null);
        if (! $account || ! $this->portal->attemptPassword($account, $data['password'], $request->ip(), $request->userAgent())) {
            throw ValidationException::withMessages(['password' => 'Those details do not match a grower account.']);
        }

        return $this->signIn($request, $account);
    }

    private function signIn(Request $request, GrowerAccount $account): RedirectResponse
    {
        Auth::guard('grower_portal')->login($account);
        $request->session()->regenerate();
        $request->session()->forget('grower_login');
        $request->session()->put('grower_locale', $account->locale);

        return redirect()->route('grower.portal.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($a = Auth::guard('grower_portal')->user()) {
            $this->portal->event($a, 'signed_out', 'web', $request->ip(), $request->userAgent());
        }
        $c = $a ? app(CompanyBranding::class)->for((int) $a->company_id, withLogoData: false)['company_uuid'] : null;
        Auth::guard('grower_portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('grower.portal.login', array_filter(['c' => $c]));
    }

    public function locale(Request $request): RedirectResponse
    {
        $locale = $request->validate(['locale' => ['required', Rule::in(GrowerAccount::LOCALES)]])['locale'];
        $request->session()->put('grower_locale', $locale);
        if ($a = Auth::guard('grower_portal')->user()) {
            $a->forceFill(['locale' => $locale])->save();
        }

        return back();
    }

    /** The company's own logo for the portal pages — only where it is white-labelled. */
    public function logo(string $company): SymfonyResponse
    {
        $id = DB::table('companies')->where('uuid', $company)->value('id');
        abort_unless($id, 404);
        $branding = app(CompanyBranding::class);
        abort_unless($branding->for((int) $id, withLogoData: false)['white_label'], 404);
        $logo = $branding->logo((int) $id);
        abort_unless($logo, 404);

        return response($logo['binary'], 200)->header('Content-Type', $logo['mime'])->header('Cache-Control', 'public, max-age=86400');
    }

    private function throttle(string $key, int $max, int $decay): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['identifier' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        RateLimiter::hit($key, $decay);
    }
}
