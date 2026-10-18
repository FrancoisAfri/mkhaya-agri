<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinBankFeed;
use App\Modules\Finance\Accounting\Models\FinYodleeSetting;
use App\Modules\Finance\Accounting\Models\FinYodleeUser;
use App\Modules\Finance\Accounting\Services\BankFeedService;
use App\Modules\Finance\Accounting\Services\YodleeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BankFeedController extends Controller
{
    public function __construct(private BankFeedService $feeds)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Modules/Finance/Accounting/BankFeeds', [
            'feeds' => FinBankFeed::with('bankAccount:id,name')->orderByDesc('id')->get()->map(fn (FinBankFeed $f) => [
                'id' => $f->id, 'provider' => $f->provider,
                // Named on screen only so a row left on a retired connector is recognisable.
                'provider_label' => $f->provider === BankFeedService::PROVIDER_YODLEE ? 'Yodlee' : ucfirst((string) $f->provider),
                'account_name' => $f->provider_account_name, 'account_mask' => $f->provider_account_mask,
                'bank' => $f->bankAccount?->name, 'bank_account_id' => $f->bank_account_id, 'status' => $f->status,
                'import_from' => $f->import_from?->toDateString(), 'refresh_mode' => $f->refresh_mode,
                'last_synced_at' => $f->last_synced_at?->diffForHumans(), 'last_error' => $f->last_error,
                'unmatched' => $f->bankAccount?->statementLines()->where('status', 'unmatched')->count() ?? 0,
            ]),
            'banks'     => FinBankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name]),
            // What the screen needs to decide whether to offer "Connect a bank" at all, and what to
            // say instead when it cannot.
            // Usually Mkhaya's subscription. A tenant sees only whether bank feeds are available —
            // the credentials behind them are not theirs and are not sent.
            /*
             * Whether bank feeds work, and nothing about what makes them work.
             *
             * The credentials are Mkhaya's and are edited from Platform tools. Sending them to this
             * page — even to a platform admin, even unrendered — would put a key behind every
             * customer's bank data into a payload on a tenant's accounting screen.
             */
            'yodlee'    => ($s = FinYodleeSetting::resolve())
                ? ['configured' => $s->isConfigured(), 'is_active' => $s->is_active]
                : ['configured' => false, 'is_active' => false],
            // A platform admin gets a link to go and fix it; a tenant gets told to ask.
            'yodleeSettingsUrl' => $this->managesPlatformYodlee() ? route('tenancy.yodlee.index') : null,
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bank_account_id' => 'required|integer|exists:fin_bank_accounts,id',
            // There is no provider to choose — every feed is Yodlee.
            'provider_account_id'   => 'nullable|string|max:60',
            'provider_ref'          => 'nullable|string|max:60',
            'provider_account_name' => 'nullable|string|max:120',
            'provider_account_mask' => 'nullable|string|max:20',
            'import_from'     => 'nullable|date',
            'refresh_mode'    => 'required|in:login,daily,manual',
        ]);

        if (blank($data['provider_ref'] ?? null)) {
            return back()->withErrors(['provider_ref' => 'Choose which of the linked bank accounts this feed should pull.']);
        }

        $feed = $this->feeds->connect($data);

        $count = $this->feeds->refresh($feed); // pull straight away, so the link is visibly working

        if ($feed->fresh()->status === 'error') {
            return back()->with('warning', 'Feed connected, but the first pull failed: ' . $feed->fresh()->last_error);
        }

        return back()->with('success', "Feed connected — imported {$count} transaction(s).");
    }

    public function refresh(FinBankFeed $feed): RedirectResponse
    {
        $count = $this->feeds->refresh($feed);

        return $feed->status === 'error'
            ? back()->with('error', $feed->last_error)
            : back()->with('success', "Pulled {$count} new transaction(s).");
    }

    public function disconnect(FinBankFeed $feed): RedirectResponse
    {
        $this->feeds->disconnect($feed);

        return back()->with('success', 'Feed disconnected.');
    }

    // ── Yodlee ───────────────────────────────────────────────────────────────

    /**
     * What the FastLink widget needs to open.
     *
     * FastLink is Yodlee's hosted bank-login screen. It runs in the browser precisely so that the
     * customer's online banking credentials are typed into Yodlee and never touch this application
     * — which is the whole reason to use an aggregator rather than asking for them ourselves.
     */
    public function yodleeLink(): \Illuminate\Http\JsonResponse
    {
        $settings = FinYodleeSetting::resolve();

        if (! $settings || ! $settings->isConfigured()) {
            return response()->json(['message' => 'Bank feeds are not available yet.'], 422);
        }

        try {
            $client = YodleeClient::for($settings, $this->yodleeUser());
            $client->ensureUser();

            return response()->json([
                'fastlink_url' => $settings->fastlinkUrl(),
                'access_token' => $client->fastlinkToken(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * The accounts the user linked, ready to be mapped onto our bank accounts.
     *
     * Mapping is deliberately a human step. One bank login often exposes a cheque account, a savings
     * account and a credit card, and only the person who keeps the books knows which of ours each
     * one is. Guessing produces a feed that reconciles against the wrong ledger.
     */
    public function yodleeAccounts(Request $request): \Illuminate\Http\JsonResponse
    {
        $settings = FinYodleeSetting::resolve();

        if (! $settings || ! $settings->isConfigured()) {
            return response()->json(['message' => 'Bank feeds are not available yet.'], 422);
        }

        try {
            $accounts = YodleeClient::for($settings, $this->yodleeUser())
                ->accounts($request->query('provider_account_id'));

            return response()->json([
                'accounts' => collect($accounts)->map(fn (array $a) => [
                    'id'                  => (string) ($a['id'] ?? ''),
                    'provider_account_id' => (string) ($a['providerAccountId'] ?? ''),
                    'name'                => $a['accountName'] ?? $a['providerName'] ?? 'Account',
                    'mask'                => $a['accountNumber'] ?? null,
                    'type'                => $a['accountType'] ?? $a['CONTAINER'] ?? null,
                    'currency'            => $a['balance']['currency'] ?? null,
                    'balance'             => $a['balance']['amount'] ?? null,
                ])->filter(fn ($a) => $a['id'] !== '')->values(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * This company's own Yodlee identity inside the shared subscription.
     *
     * Resolved per request rather than passed around, so no call path can reach Yodlee without one
     * — which is the only thing separating one customer's linked bank accounts from another's.
     */
    private function yodleeUser(): FinYodleeUser
    {
        return FinYodleeUser::forCurrentCompany(
            app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? Auth::user()?->company_id
        );
    }

    /** Whether the signed-in user may go and change Mkhaya's own Yodlee credentials. */
    private function managesPlatformYodlee(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->is_platform_admin || $user?->is_master_admin);
    }
}
