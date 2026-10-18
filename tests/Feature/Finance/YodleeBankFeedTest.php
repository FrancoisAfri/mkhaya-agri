<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinBankFeed;
use App\Modules\Finance\Accounting\Models\FinBankStatementLine;
use App\Modules\Finance\Accounting\Models\FinYodleeSetting;
use App\Modules\Finance\Accounting\Models\FinYodleeUser;
use App\Modules\Finance\Accounting\Services\BankFeedService;
use App\Modules\Finance\Accounting\Services\YodleeClient;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Yodlee bank feed, against a faked Yodlee.
 *
 * Nobody can point this at the real API in CI — it needs a paid subscription and a human logging
 * into a real bank. What can be pinned down is everything between their response and our ledger,
 * which is where the damage would be: the sign of an amount, which date a line is filed under,
 * whether a second pull duplicates the first, and whether one customer's token can reach another's
 * transactions.
 */
class YodleeBankFeedTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = 'https://sandbox.api.yodlee.com/ysl';

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '1100')->exists()) {
            (new FinanceGlSeeder())->run();
        }

        Cache::flush();   // tokens are cached; a stale one would mask a broken auth path
    }

    /** Mkhaya's own subscription — the null-company row every tenant reads. */
    private function settings(): FinYodleeSetting
    {
        $s = new FinYodleeSetting([
            'environment' => 'sandbox',
            'is_active'   => true,
            'credentials' => ['client_id' => 'test-client', 'secret' => 'test-secret'],
        ]);
        $s->company_id = null;
        $s->save();

        return $s;
    }

    private function bank(): FinBankAccount
    {
        return FinBankAccount::create([
            'account_id' => FinAccount::where('code', '1100')->value('id'),
            'name' => 'Yodlee Bank', 'type' => 'bank', 'currency' => 'ZAR',
        ]);
    }

    private function feed(FinBankAccount $bank, array $attributes = []): FinBankFeed
    {
        return FinBankFeed::create(array_merge([
            'bank_account_id' => $bank->id,
            'provider'        => 'yodlee',
            'provider_ref'    => '55501',
            'status'          => 'connected',
            'refresh_mode'    => 'daily',
            'import_from'     => now()->subDays(20)->toDateString(),
        ], $attributes));
    }

    /** A Yodlee transaction in the shape their API returns. */
    private function transaction(string $id, string $date, float $amount, string $baseType, string $description = 'CARD PURCHASE'): array
    {
        return [
            'id'          => $id,
            'date'        => $date,
            'baseType'    => $baseType,
            'amount'      => ['amount' => $amount, 'currency' => 'ZAR'],
            'description' => ['simple' => $description, 'original' => $description],
        ];
    }

    /** @param array<int, array<string,mixed>> $transactions */
    private function fakeYodlee(array $transactions): void
    {
        Http::fake([
            self::BASE . '/auth/token' => Http::response(['token' => ['accessToken' => 'tok-abc', 'issuedAt' => now()->toIso8601String()]]),
            self::BASE . '/transactions*' => Http::response(['transaction' => $transactions]),
            self::BASE . '/accounts*' => Http::response(['account' => []]),
        ]);
    }

    // ── The sign of the money ────────────────────────────────────────────────

    public function test_a_debit_is_stored_as_money_out_and_a_credit_as_money_in(): void
    {
        /*
         * Yodlee reports every amount as a positive number and says DEBIT or CREDIT beside it.
         * Taking the amount at face value would file every payment as income — the month would
         * reconcile to roughly double the real balance, and it would look plausible.
         */
        $bank = $this->bank();
        $this->settings();
        $this->fakeYodlee([
            $this->transaction('t1', now()->subDays(3)->toDateString(), 1500.00, 'DEBIT', 'SUPPLIER PAYMENT'),
            $this->transaction('t2', now()->subDays(2)->toDateString(), 9000.00, 'CREDIT', 'CLIENT EFT'),
        ]);

        $imported = app(BankFeedService::class)->refresh($this->feed($bank));

        $this->assertSame(2, $imported);
        $this->assertEqualsWithDelta(-1500.00, (float) FinBankStatementLine::where('reference', 't1')->value('amount'), 0.01);
        $this->assertEqualsWithDelta(9000.00, (float) FinBankStatementLine::where('reference', 't2')->value('amount'), 0.01);
    }

    // ── Pulling twice must not pay twice ─────────────────────────────────────

    public function test_re_reading_the_same_window_imports_nothing_the_second_time(): void
    {
        // Each refresh deliberately overlaps the last, because banks post late. That is only safe
        // if the dedup holds.
        $bank = $this->bank();
        $this->settings();
        $this->fakeYodlee([$this->transaction('t1', now()->subDays(3)->toDateString(), 100.00, 'DEBIT')]);

        $feed = $this->feed($bank);
        $this->assertSame(1, app(BankFeedService::class)->refresh($feed));
        $this->assertSame(0, app(BankFeedService::class)->refresh($feed->fresh()));
        $this->assertSame(1, FinBankStatementLine::where('bank_account_id', $bank->id)->count());
    }

    public function test_a_transaction_whose_description_changes_after_settling_is_not_imported_twice(): void
    {
        /*
         * Real behaviour on card payments: the line reads PENDING for a day or two and then becomes
         * the merchant's name. Hashing the description would make that a second transaction — the
         * reason the hash is built on Yodlee's stable transaction id instead.
         */
        $bank = $this->bank();
        $this->settings();
        $date = now()->subDays(2)->toDateString();

        $this->fakeYodlee([$this->transaction('t9', $date, 250.00, 'DEBIT', 'PENDING TRANSACTION')]);
        app(BankFeedService::class)->refresh($this->feed($bank));

        $this->fakeYodlee([$this->transaction('t9', $date, 250.00, 'DEBIT', 'WOOLWORTHS CLAREMONT')]);
        app(BankFeedService::class)->refresh($this->feed($bank)->fresh());

        $this->assertSame(1, FinBankStatementLine::where('bank_account_id', $bank->id)->count());
    }

    // ── Refusing to guess ────────────────────────────────────────────────────

    public function test_a_feed_with_no_linked_account_reports_it_rather_than_importing_nothing_quietly(): void
    {
        $bank = $this->bank();
        $this->settings();

        $feed = $this->feed($bank, ['provider_ref' => null]);
        $imported = app(BankFeedService::class)->refresh($feed);

        $this->assertSame(0, $imported);
        $this->assertSame('error', $feed->fresh()->status);
        $this->assertStringContainsString('not linked', $feed->fresh()->last_error);
    }

    public function test_a_platform_with_no_subscription_says_so_without_blaming_the_tenant(): void
    {
        /*
         * The credentials are Mkhaya's now, so telling a customer to "add the client ID and secret"
         * would be sending them after something they cannot reach and do not own.
         */
        $feed = $this->feed($this->bank());

        $this->assertSame(0, app(BankFeedService::class)->refresh($feed));
        $this->assertStringContainsString('not available', $feed->fresh()->last_error);
        $this->assertStringNotContainsString('client ID', $feed->fresh()->last_error);
    }

    public function test_a_yodlee_outage_marks_the_feed_and_keeps_the_error(): void
    {
        // The desk needs to see why, and Yodlee's own code is what their support asks for first.
        $bank = $this->bank();
        $this->settings();

        Http::fake([
            self::BASE . '/auth/token' => Http::response(['token' => ['accessToken' => 'tok-abc']]),
            self::BASE . '/transactions*' => Http::response([
                'errorCode' => 'Y804', 'errorMessage' => 'Invalid account', 'referenceCode' => 'ref-77',
            ], 400),
        ]);

        $feed = $this->feed($bank);
        $this->assertSame(0, app(BankFeedService::class)->refresh($feed));
        $this->assertSame('error', $feed->fresh()->status);
        $this->assertStringContainsString('Y804', $feed->fresh()->last_error);
    }

    public function test_a_transaction_with_no_amount_yet_is_skipped_rather_than_stored_as_zero(): void
    {
        $bank = $this->bank();
        $this->settings();
        $this->fakeYodlee([
            ['id' => 'pending', 'date' => now()->toDateString(), 'baseType' => 'DEBIT', 'description' => ['simple' => 'AUTH']],
            $this->transaction('real', now()->toDateString(), 42.00, 'DEBIT'),
        ]);

        $this->assertSame(1, app(BankFeedService::class)->refresh($this->feed($bank)));
    }

    // ── Credentials and tenancy ──────────────────────────────────────────────

    public function test_the_secret_is_encrypted_at_rest_and_never_serialised(): void
    {
        $settings = $this->settings();

        $raw = \Illuminate\Support\Facades\DB::table('fin_yodlee_settings')->where('id', $settings->id)->value('credentials');

        $this->assertStringNotContainsString('test-secret', (string) $raw, 'The Yodlee secret must not be readable in the database.');
        $this->assertArrayNotHasKey('credentials', $settings->toArray());
        $this->assertArrayNotHasKey('secret', $settings->toSafeArray());
        $this->assertTrue($settings->toSafeArray()['has_secret']);
    }

    // ── One subscription, many tenants ───────────────────────────────────────

    public function test_the_platform_subscription_is_readable_by_a_tenant(): void
    {
        /*
         * The whole point of the shared model: a customer links a bank without ever holding a
         * Yodlee contract. Both halves have to be right — the RLS policy must let the null-company
         * row through, and CompanyScope must stop filtering it out. Get one wrong and the
         * subscription installs cleanly and is invisible to everybody.
         */
        $this->settings();

        DB::statement("SELECT set_config('app.company_id', '4242', false)");

        try {
            $resolved = FinYodleeSetting::resolve();
        } finally {
            DB::statement("SELECT set_config('app.company_id', '', false)");
        }

        $this->assertNotNull($resolved, 'A tenant must be able to read the platform subscription.');
        $this->assertTrue($resolved->isPlatformWide());
    }

    public function test_two_tenants_get_different_yodlee_users(): void
    {
        /*
         * The leak this design exists to prevent.
         *
         * Yodlee scopes accounts and transactions to a loginName, not to the subscription. Sharing
         * the subscription is safe; sharing the login name would have let every customer list — and
         * pull from — every other customer's linked bank accounts, through an API behaving exactly
         * as documented.
         */
        $ours = FinYodleeUser::forCurrentCompany(101);
        $theirs = FinYodleeUser::forCurrentCompany(202);

        $this->assertNotSame($ours->login_name, $theirs->login_name);
        $this->assertSame($ours->id, FinYodleeUser::forCurrentCompany(101)->id, 'A company keeps the identity its banks hang off.');
    }

    public function test_a_login_name_cannot_be_reused_by_a_second_company(): void
    {
        // Enforced by the database rather than by the code remembering to check, because this is
        // the single constraint separating one customer's bank data from another's.
        $first = FinYodleeUser::forCurrentCompany(303);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $clash = new FinYodleeUser(['login_name' => $first->login_name]);
        $clash->company_id = 404;
        $clash->save();
    }

    public function test_one_tenants_token_is_not_served_to_another(): void
    {
        /*
         * The cache key is the login name, which is unique platform-wide. Keying it on the
         * subscription would now be the same value for every tenant, so the first tenant's token
         * would be handed to whoever asked next.
         */
        $this->fakeYodlee([]);
        $settings = $this->settings();

        YodleeClient::for($settings, FinYodleeUser::forCurrentCompany(11))->userToken();
        YodleeClient::for($settings, FinYodleeUser::forCurrentCompany(22))->userToken();

        // Two distinct tenants asked, so two token calls must have gone out.
        Http::assertSentCount(2);
    }

    public function test_a_tenant_reuses_its_own_cached_token(): void
    {
        // The other side of the same coin: caching per tenant must actually cache.
        $this->fakeYodlee([]);
        $settings = $this->settings();
        $user = FinYodleeUser::forCurrentCompany(33);

        YodleeClient::for($settings, $user)->userToken();
        YodleeClient::for($settings, $user)->userToken();

        Http::assertSentCount(1);
    }

    // ── There is no provider to choose ───────────────────────────────────────

    public function test_every_new_feed_is_a_yodlee_feed(): void
    {
        /*
         * The provider is not an input any more. It used to be a dropdown of four where three did
         * not work, so the only thing the choice could add was a way to get it wrong — and one of
         * the three wrote invented transactions into real books.
         */
        $feed = app(BankFeedService::class)->connect([
            'bank_account_id' => $this->bank()->id,
            'provider'        => 'demo',        // ignored: there is nothing else to be
            'provider_ref'    => '900',
        ]);

        $this->assertSame('yodlee', $feed->provider);
    }

    public function test_a_feed_left_on_a_removed_provider_says_what_to_do(): void
    {
        $feed = $this->feed($this->bank(), ['provider' => 'saltedge']);

        $this->assertSame(0, app(BankFeedService::class)->refresh($feed));
        $this->assertStringContainsString('Yodlee', $feed->fresh()->last_error);
    }
}
