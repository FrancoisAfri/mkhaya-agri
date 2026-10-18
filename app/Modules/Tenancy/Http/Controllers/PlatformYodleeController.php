<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinYodleeSetting;
use App\Modules\Finance\Accounting\Models\FinYodleeUser;
use App\Modules\Finance\Accounting\Services\YodleeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mkhaya's own Yodlee subscription — a platform tool, not an accounting setting.
 *
 * It sat inside Accounting → Bank Feeds while the credentials were each customer's own. They are
 * not: one subscription now stands behind every tenant's bank data, so it belongs beside Payment
 * Settings with the rest of the things only Mkhaya may touch. Leaving it on a tenant screen meant a
 * platform admin editing platform-wide keys while looking at one customer's books, which is exactly
 * the context in which somebody changes the wrong thing.
 *
 * Guarded by the `platform.admin` middleware on the route, like every other tool on that row.
 */
class PlatformYodleeController extends Controller
{
    public function index(): Response
    {
        $settings = FinYodleeSetting::platform();

        return Inertia::render('Tenancy/YodleeSettings', [
            'settings' => $settings?->toSafeArray() ?? [
                'environment' => 'sandbox',
                'configured'  => false,
                'is_active'   => false,
                'has_secret'  => false,
            ],
            'environments' => array_keys(FinYodleeSetting::HOSTS),
            'hosts'        => FinYodleeSetting::HOSTS,
            'fastlinkUrls' => FinYodleeSetting::FASTLINK_URLS,
            // How many customers are already linked through this subscription. Shown because
            // changing the environment orphans every one of them, and a count is the difference
            // between an informed change and a surprise.
            'linkedCompanies' => FinYodleeUser::query()->withoutGlobalScopes()->whereNotNull('registered_at')->count(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'environment'  => 'required|in:sandbox,development,production',
            'client_id'    => 'required|string|max:200',
            'secret'       => 'nullable|string|max:400',
            'base_url'     => 'nullable|url|max:200',
            'fastlink_url' => 'nullable|url|max:200',
            'is_active'    => 'nullable|boolean',
        ]);

        $settings = FinYodleeSetting::platform() ?? new FinYodleeSetting();
        // Null is what makes it the platform's row and readable by every tenant.
        $settings->company_id = null;

        $settings->fill([
            'environment'  => $data['environment'],
            'base_url'     => $data['base_url'] ?? null,
            'fastlink_url' => $data['fastlink_url'] ?? null,
            'is_active'    => (bool) ($data['is_active'] ?? true),
            'created_by'   => $settings->created_by ?? Auth::id(),
        ]);

        /*
         * The secret is only replaced when a new one is typed.
         *
         * A form that writes every field on every save would blank a working credential the moment
         * somebody opened it to change the environment — and the failure would surface later, as
         * every customer's feed breaking at once.
         */
        $settings->credentials = [
            'client_id' => $data['client_id'],
            'secret'    => filled($data['secret'] ?? null) ? $data['secret'] : $settings->secret(),
        ];

        $settings->save();

        return back()->with('success', 'Yodlee subscription saved. Use Test connection to confirm it works.');
    }

    /** Ask Yodlee for a token and nothing else, so a bad key is found here and not by a customer. */
    public function test(): RedirectResponse
    {
        $settings = FinYodleeSetting::platform();

        if (! $settings || ! $settings->isConfigured()) {
            return back()->withErrors(['client_id' => 'Add the client ID and secret first.']);
        }

        try {
            // Mkhaya's own Yodlee identity — the null-company user, not any customer's.
            YodleeClient::for($settings, FinYodleeUser::forCurrentCompany(null))->verify();
            $settings->update(['verified_at' => now(), 'last_error' => null]);

            return back()->with('success', 'Connected to Yodlee successfully.');
        } catch (\Throwable $e) {
            $settings->update(['last_error' => Str::limit($e->getMessage(), 240)]);

            return back()->with('error', $e->getMessage());
        }
    }
}
