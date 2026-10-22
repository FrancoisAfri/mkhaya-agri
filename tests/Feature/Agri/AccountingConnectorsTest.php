<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Accounting\AccountingSync;
use App\Modules\Agri\Models\AccountingConnection;
use App\Modules\Agri\Models\AccountingLink;
use App\Modules\Agri\Models\AccountingMapping;
use App\Modules\Agri\Models\AgriOrgSetting;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Services\AgriLedger;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Shared\Party\PartyDirectory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Xero, QuickBooks Online and Sage Business Cloud Accounting, faked at the HTTP boundary from their
 * documented APIs: the OAuth callback keeps the tokens encrypted, an expired token is refreshed, a
 * farm invoice goes out with its accounts, tax and tracking mapped and keeps its external id, a
 * retry never sends it twice, a payment recorded there marks ours paid, a refusal is logged and
 * retryable, and the book-of-record setting decides whether farm postings are exported.
 */
class AccountingConnectorsTest extends AgriTestCase
{
    private Farm $north;

    /** Requests the fake provider has seen, by kind. */
    private array $seen = [];

    /** Answer the next invoice push with a refusal. */
    private bool $refuseInvoice = false;

    private string $access = 'at-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->north = $this->farm('NORTH');
        foreach (['xero', 'quickbooks', 'sage'] as $p) {
            config(["agri.accounting.providers.{$p}.client_id" => "{$p}-client", "agri.accounting.providers.{$p}.client_secret" => "{$p}-secret"]);
        }
    }

    public static function providers(): array
    {
        return ['xero' => ['xero'], 'quickbooks' => ['quickbooks'], 'sage' => ['sage']];
    }

    // ── the fake providers ───────────────────────────────────────────────────────────────────

    private function fake(string $provider): void
    {
        $this->seen = [];
        Http::fake(function (HttpRequest $r) use ($provider) {
            $url = $r->url();
            $m = $r->method();
            $mark = function (string $k) use ($r) { $this->seen[$k][] = $r; };

            // OAuth token endpoints: a code or a refresh token in, a pair out.
            if (str_contains($url, 'identity.xero.com/connect/token') || str_contains($url, 'oauth.platform.intuit.com') || str_contains($url, 'oauth.accounting.sage.com/token')) {
                $mark('token:'.$r['grant_type']);
                $this->access = $r['grant_type'] === 'refresh_token' ? 'at-2' : 'at-1';

                return Http::response(['access_token' => $this->access, 'refresh_token' => 'rt-'.$this->access, 'expires_in' => 1800, 'token_type' => 'Bearer']);
            }
            if (! $r->hasHeader('Authorization', 'Bearer '.$this->access)) {
                return Http::response(['error' => 'stale token'], 401);
            }

            return match ($provider) {
                'xero' => $this->xero($r, $url, $m, $mark),
                'quickbooks' => $this->quickbooks($r, $url, $m, $mark),
                'sage' => $this->sage($r, $url, $m, $mark),
            };
        });
    }

    private function xero(HttpRequest $r, string $url, string $m, callable $mark)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === '/connections') {
            return Http::response([['id' => 'conn-1', 'tenantId' => 'tenant-1', 'tenantType' => 'ORGANISATION', 'tenantName' => 'Boplaas Boerdery (Xero)']]);
        }
        $this->assertTrue($r->hasHeader('xero-tenant-id', 'tenant-1'), 'Every Xero call names the tenant.');

        return match (true) {
            str_ends_with($path, '/Accounts') => Http::response(['Accounts' => [['AccountID' => 'acc-200', 'Code' => '200', 'Name' => 'Sales', 'Type' => 'REVENUE', 'Status' => 'ACTIVE'],
                ['AccountID' => 'acc-1100', 'Code' => '1100', 'Name' => 'Bank', 'Type' => 'BANK', 'Status' => 'ACTIVE'], ['AccountID' => 'acc-5720', 'Code' => '5720', 'Name' => 'Farm costs', 'Type' => 'EXPENSE', 'Status' => 'ACTIVE']]]),
            str_ends_with($path, '/TaxRates') => Http::response(['TaxRates' => [['Name' => '15% VAT on Income', 'TaxType' => 'OUTPUT2', 'EffectiveRate' => 15, 'Status' => 'ACTIVE']]]),
            str_ends_with($path, '/TrackingCategories') => Http::response(['TrackingCategories' => [['TrackingCategoryID' => 'tc-farm', 'Name' => 'Farm', 'Options' => [['TrackingOptionID' => 'opt-north', 'Name' => 'North']]]]]),
            str_ends_with($path, '/Contacts') && $m === 'GET' => Http::response(['Contacts' => []]),
            str_ends_with($path, '/Contacts') && $m === 'POST' => tap(Http::response(['Contacts' => [['ContactID' => 'contact-1']]]), fn () => $mark('contact')),
            str_ends_with($path, '/Invoices') => $this->invoiceAnswer($mark, $r, Http::response(['Invoices' => [['InvoiceID' => 'ext-inv-1', 'InvoiceNumber' => 'INV-X1']]]),
                Http::response(['Type' => 'ValidationException', 'Elements' => [['ValidationErrors' => [['Message' => 'Account code \'999\' is not a valid code for this document.']]]]], 400)),
            str_ends_with($path, '/ManualJournals') => tap(Http::response(['ManualJournals' => [['ManualJournalID' => 'mj-'.(count($this->seen['journal'] ?? []) + 1)]]]), fn () => $mark('journal')),
            str_ends_with($path, '/Payments') => Http::response(['Payments' => [['PaymentID' => 'pay-1', 'Date' => '/Date('.(today()->getTimestamp() * 1000).'+0000)/', 'Amount' => 1150.0,
                'Status' => 'AUTHORISED', 'PaymentType' => 'ACCRECPAYMENT', 'Invoice' => ['InvoiceID' => 'ext-inv-1'], 'Reference' => 'EFT 7781']]]),
            default => Http::response(['Message' => "Unexpected {$m} {$path}"], 404),
        };
    }

    private function quickbooks(HttpRequest $r, string $url, string $m, callable $mark)
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->assertStringContainsString('/v3/company/9130/', $path, 'Every QuickBooks call names the realm.');
        if (str_contains($path, '/companyinfo/')) {
            return Http::response(['CompanyInfo' => ['CompanyName' => 'Boplaas Boerdery (QBO)']]);
        }
        if (str_ends_with($path, '/query')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $sql = $q['query'] ?? '';
            $entity = preg_match('/from (\w+)/', $sql, $mm) ? $mm[1] : '';
            $rows = match ($entity) {
                'Account' => [['Id' => '79', 'AcctNum' => '200', 'Name' => 'Sales'], ['Id' => '35', 'AcctNum' => '5720', 'Name' => 'Farm costs'], ['Id' => '36', 'AcctNum' => '1100', 'Name' => 'Bank']],
                'TaxCode' => [['Id' => '5', 'Name' => 'VAT 15%']],
                'Class' => [['Id' => 'cls-north', 'Name' => 'North']],
                'Department' => [],
                'Item' => [['Id' => 'item-1', 'Name' => 'Produce', 'IncomeAccountRef' => ['value' => '79']]],
                'Customer', 'Vendor' => [],
                'Payment' => [['Id' => 'qpay-1', 'TxnDate' => today()->toDateString(), 'TotalAmt' => 1150.0, 'Line' => [['Amount' => 1150.0, 'LinkedTxn' => [['TxnId' => 'ext-inv-1', 'TxnType' => 'Invoice']]]]]],
                'BillPayment' => [],
                default => [],
            };

            return Http::response(['QueryResponse' => $rows ? [$entity => $rows] : []]);
        }

        return match (true) {
            str_ends_with($path, '/customer') => tap(Http::response(['Customer' => ['Id' => 'cust-1']]), fn () => $mark('contact')),
            str_ends_with($path, '/invoice') => $this->invoiceAnswer($mark, $r, Http::response(['Invoice' => ['Id' => 'ext-inv-1', 'DocNumber' => 'INV-X1']]),
                Http::response(['Fault' => ['Error' => [['Message' => 'Invalid Reference Id', 'Detail' => 'Item assigned to this transaction has been deleted']]]], 400)),
            str_ends_with($path, '/journalentry') => tap(Http::response(['JournalEntry' => ['Id' => 'je-'.(count($this->seen['journal'] ?? []) + 1)]]), fn () => $mark('journal')),
            default => Http::response(['Fault' => ['Error' => [['Message' => "Unexpected {$m} {$path}"]]]], 404),
        };
    }

    private function sage(HttpRequest $r, string $url, string $m, callable $mark)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (str_ends_with($path, '/businesses')) {
            return Http::response([['id' => 'biz-1', 'name' => 'Boplaas Boerdery (Sage)']]);
        }
        $this->assertTrue($r->hasHeader('X-Business', 'biz-1'), 'Every Sage call names the business.');

        return match (true) {
            str_ends_with($path, '/ledger_accounts') => Http::response(['$items' => [['id' => 'la-200', 'nominal_code' => 200, 'displayed_as' => 'Sales'],
                ['id' => 'la-5720', 'nominal_code' => 5720, 'displayed_as' => 'Farm costs'], ['id' => 'la-1100', 'nominal_code' => 1100, 'displayed_as' => 'Bank']], '$next' => null]),
            str_ends_with($path, '/tax_rates') => Http::response(['$items' => [['id' => 'GB_STANDARD', 'displayed_as' => 'Standard 15%', 'percentage' => 15]], '$next' => null]),
            str_ends_with($path, '/analysis_types') => Http::response(['$items' => [['id' => 'at-1', 'displayed_as' => 'Farm', 'analysis_type_categories' => [['id' => 'atc-north', 'displayed_as' => 'North']]]], '$next' => null]),
            str_ends_with($path, '/contacts') && $m === 'GET' => Http::response(['$items' => [], '$next' => null]),
            str_ends_with($path, '/contacts') && $m === 'POST' => tap(Http::response(['id' => 'sc-1']), fn () => $mark('contact')),
            str_ends_with($path, '/sales_invoices') => $this->invoiceAnswer($mark, $r, Http::response(['id' => 'ext-inv-1', 'invoice_number' => 'INV-X1']),
                Http::response([['$severity' => 'error', '$dataCode' => 'RecordInvalid', '$message' => 'Ledger account is not valid for sales', '$source' => 'ledger_account']], 422)),
            str_ends_with($path, '/journals') => tap(Http::response(['id' => 'sj-'.(count($this->seen['journal'] ?? []) + 1)]), fn () => $mark('journal')),
            str_ends_with($path, '/contact_payments') => Http::response(['$items' => [['id' => 'cp-1', 'date' => today()->toDateString(), 'transaction_type' => ['id' => 'CUSTOMER_RECEIPT'],
                'total_amount' => 1150.0, 'allocated_artefacts' => [['artefact' => ['id' => 'ext-inv-1'], 'amount' => 1150.0]]]], '$next' => null]),
            default => Http::response([['$message' => "Unexpected {$m} {$path}"]], 404),
        };
    }

    private function invoiceAnswer(callable $mark, HttpRequest $r, $ok, $refusal)
    {
        $mark('invoice');
        if ($this->refuseInvoice) {
            $this->refuseInvoice = false;

            return $refusal;
        }

        return $ok;
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** Through the screens: connect, come back from the provider, have the one organisation chosen. */
    private function connect(string $provider): AccountingConnection
    {
        $this->fake($provider);
        $go = $this->actingAs($this->admin)->get(route('agri.accounting.connect', $provider).($provider === 'sage' ? '?region=uk' : ''));
        $go->assertRedirect();
        $to = $go->headers->get('Location');
        parse_str((string) parse_url($to, PHP_URL_QUERY), $q);
        $this->assertSame("{$provider}-client", $q['client_id']);
        $this->assertSame(route('agri.accounting.callback', $provider), $q['redirect_uri']);
        $this->actingAs($this->admin)->get(route('agri.accounting.callback', $provider).'?'.http_build_query(['code' => 'auth-code', 'state' => $q['state']] + ($provider === 'quickbooks' ? ['realmId' => '9130'] : [])))
            ->assertRedirect()->assertSessionHas('success');

        return AccountingConnection::withoutGlobalScopes()->where('company_id', $this->company->id)->where('provider', $provider)->firstOrFail();
    }

    private function account(string $code): int
    {
        return (int) FinAccount::withoutGlobalScopes()->where('code', $code)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))->orderByDesc('company_id')->value('id');
    }

    private function farmInvoice(): FinInvoice
    {
        return $this->asCompany($this->company, function () {
            $buyer = app(PartyDirectory::class)->create(['company_id' => $this->company->id, 'kind' => 'customer', 'name' => 'Noordwes Milling', 'vat_number' => '4123456789',
                'credit_terms_days' => 30], 'finance');
            $this->actingAs($this->admin);

            return app(ArApService::class)->createInvoice(['party_id' => $buyer->id, 'direction' => 'receivable', 'invoice_date' => today()->toDateString(), 'description' => 'White maize',
                'dimensions' => AgriLedger::dimensions($this->north, null, null, 'produce'), 'relieve_stock' => false,
                'lines' => [['account_id' => $this->account(config('agri.accounts.produce_sales')), 'description' => 'White maize WM1, 0.5 t', 'quantity' => 0.5, 'unit_price' => 2000,
                    'tax_code' => 'STD', 'tax_rate' => 15]]]);
        });
    }

    /** Map our produce-sales account, STD tax and the farm through the mapping screen, from the chart just read. */
    private function mapEverything(AccountingConnection $c, bool $withTax = true): void
    {
        $this->actingAs($this->admin)->post(route('agri.accounting.pull', $c->provider))->assertSessionHas('success');
        $cat = $c->fresh()->catalog;
        $sales = collect($cat['accounts'])->firstWhere('code', '200');
        $tax = $cat['tax_rates'][0];
        $track = $cat['tracking'][0];
        $rows = [['kind' => 'account', 'local_key' => (string) config('agri.accounts.produce_sales'), 'external_id' => $sales['id'], 'external_code' => $sales['code'], 'external_name' => $sales['name']],
            ['kind' => 'tracking', 'local_key' => 'farm:'.$this->north->id, 'external_id' => $track['options'][0]['id'], 'external_name' => $track['options'][0]['name'],
                'extra' => ['category_id' => $track['id'], 'category' => $track['name']]]];
        if ($withTax) {
            $rows[] = ['kind' => 'tax', 'local_key' => 'STD', 'external_id' => $tax['id'], 'external_code' => $tax['code'], 'external_name' => $tax['name']];
        }
        if ($c->provider === 'quickbooks') {
            $rows[] = ['kind' => 'item', 'local_key' => (string) config('agri.accounts.produce_sales'), 'external_id' => 'item-1', 'external_name' => 'Produce'];
        }
        $this->actingAs($this->admin)->post(route('agri.accounting.mappings', $c->provider), ['rows' => $rows])->assertSessionHasNoErrors();
    }

    // ── tests ────────────────────────────────────────────────────────────────────────────────

    #[DataProvider('providers')]
    public function test_the_oauth_callback_keeps_the_tokens_encrypted_and_picks_the_organisation(string $provider): void
    {
        $c = $this->connect($provider);

        $this->assertSame('connected', $c->status);
        $this->assertNotNull($c->tenant_id);
        $this->assertStringContainsString('Boplaas Boerdery', (string) $c->tenant_name);
        $raw = DB::table('agri_accounting_connections')->where('id', $c->id)->value('tokens');
        $this->assertStringNotContainsString('at-1', $raw);
        $this->assertStringNotContainsString('rt-at-1', $raw);
        $this->assertSame('at-1', $c->tokens()['access_token']);
        $this->assertSame('rt-at-1', $c->tokens()['refresh_token']);
        $this->assertArrayNotHasKey('tokens', $c->toArray());
        $this->assertCount(1, $this->seen['token:authorization_code']);

        // A callback that was not started here (or replayed) is refused.
        $this->actingAs($this->admin)->get(route('agri.accounting.callback', $provider).'?code=x&state=forged')->assertRedirect()->assertSessionHas('error');
    }

    #[DataProvider('providers')]
    public function test_an_expired_access_token_is_refreshed_and_the_new_pair_kept(string $provider): void
    {
        $c = $this->connect($provider);
        $this->travel(31)->minutes();

        $this->asCompany($this->company, fn () => app(AccountingSync::class)->pullCatalog($c->fresh()));

        $this->assertCount(1, $this->seen['token:refresh_token'] ?? []);
        $this->assertSame('rt-at-1', $this->seen['token:refresh_token'][0]['refresh_token']);
        $this->assertSame('at-2', $c->fresh()->tokens()['access_token']);
        $this->assertNotEmpty($c->fresh()->catalog['accounts']);
    }

    #[DataProvider('providers')]
    public function test_an_invoice_goes_out_mapped_once_and_its_payment_comes_back(string $provider): void
    {
        $c = $this->connect($provider);
        $this->mapEverything($c, withTax: false);
        $this->actingAs($this->admin)->post(route('agri.accounting.book'), ['book_of_record' => $provider])->assertSessionHasNoErrors();
        $invoice = $this->farmInvoice();

        // The tax code is not mapped yet: the push stops, says what to map, and waits for a retry.
        $this->asCompany($this->company, fn () => app(AccountingSync::class)->run($this->company->id));
        $link = AccountingLink::withoutGlobalScopes()->where('record_type', 'invoice')->where('local_id', $invoice->id)->firstOrFail();
        $this->assertSame('failed', $link->status);
        $this->assertStringContainsString('Tax code STD is not mapped', $link->last_error);
        $this->assertSame('failed', IntegrationEvent::withoutGlobalScopes()->find($link->event_id)->status);
        $this->assertArrayNotHasKey('invoice', $this->seen);

        // Mapped; the provider refuses once (its message is kept); the retry goes through.
        $this->mapEverything($c);
        $this->refuseInvoice = true;
        $this->actingAs($this->admin)->post(route('agri.accounting.links.retry', $link->uuid))->assertSessionHas('error');
        $this->assertSame('failed', $link->fresh()->status);
        $this->assertMatchesRegularExpression('/not a valid code|Invalid Reference Id|not valid for sales/', $link->fresh()->last_error);
        $this->actingAs($this->admin)->post(route('agri.accounting.links.retry', $link->uuid))->assertSessionHas('success');
        $link->refresh();
        $this->assertSame('synced', $link->status);
        $this->assertSame('ext-inv-1', $link->external_id);
        $this->assertCount(2, $this->seen['invoice']);
        $this->assertCount(1, $this->seen['contact']);   // created once, then remembered

        // What went out: our account, tax and farm as theirs.
        $sent = json_decode($this->seen['invoice'][1]->body(), true);
        match ($provider) {
            'xero' => [$this->assertSame('ACCREC', $sent['Invoices'][0]['Type']), $this->assertSame('200', $sent['Invoices'][0]['LineItems'][0]['AccountCode']),
                $this->assertSame('OUTPUT2', $sent['Invoices'][0]['LineItems'][0]['TaxType']), $this->assertSame(['Name' => 'Farm', 'Option' => 'North'], $sent['Invoices'][0]['LineItems'][0]['Tracking'][0]),
                $this->assertSame('contact-1', $sent['Invoices'][0]['Contact']['ContactID']), $this->assertTrue($this->seen['invoice'][1]->hasHeader('Idempotency-Key'))],
            'quickbooks' => [$this->assertSame('item-1', $sent['Line'][0]['SalesItemLineDetail']['ItemRef']['value']), $this->assertSame('5', $sent['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value']),
                $this->assertSame('cls-north', $sent['Line'][0]['SalesItemLineDetail']['ClassRef']['value']), $this->assertSame('cust-1', $sent['CustomerRef']['value']),
                $this->assertEquals(1000.0, $sent['Line'][0]['Amount']), $this->assertStringContainsString('requestid=', $this->seen['invoice'][1]->url())],
            'sage' => [$this->assertSame('la-200', $sent['sales_invoice']['invoice_lines'][0]['ledger_account_id']), $this->assertSame('GB_STANDARD', $sent['sales_invoice']['invoice_lines'][0]['tax_rate_id']),
                $this->assertSame([['id' => 'atc-north']], $sent['sales_invoice']['invoice_lines'][0]['analysis_type_categories']), $this->assertSame('sc-1', $sent['sales_invoice']['contact_id'])],
        };

        // Syncing again, or replaying the message, sends nothing more.
        $this->asCompany($this->company, fn () => app(AccountingSync::class)->run($this->company->id));
        $this->asCompany($this->company, fn () => app(AccountingSync::class)->queue($c->fresh(), 'invoice', $invoice->id, $invoice->number));
        $this->assertCount(2, $this->seen['invoice']);
        $this->assertSame(1, AccountingLink::withoutGlobalScopes()->where('record_type', 'invoice')->count());

        // The payment recorded there marks ours paid — once, however often it is read.
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEquals(1150.0, (float) $invoice->fresh()->amount_paid);
        $c->fresh()->forceFill(['payments_pulled_at' => null])->save();
        $this->asCompany($this->company, fn () => app(AccountingSync::class)->run($this->company->id));
        $this->assertEquals(1150.0, (float) $invoice->fresh()->amount_paid);
        $this->assertSame(1, DB::table('fin_payment_allocations')->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, AccountingLink::withoutGlobalScopes()->where('record_type', 'payment')->where('direction', 'in')->count());

        $this->actingAs($this->admin)->get(route('agri.accounting.index', ['tab' => 'log', 'provider' => $provider]))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Accounting/Index')->has('data.links', 2)->where('data.links.1.status', 'synced'));
    }

    public function test_the_book_of_record_decides_whether_farm_postings_are_exported(): void
    {
        $c = $this->connect('xero');
        $this->mapEverything($c);
        $this->asCompany($this->company, fn () => AccountingMapping::withoutGlobalScopes()->updateOrCreate(['connection_id' => $c->id, 'kind' => 'account', 'local_key' => (string) config('finance.default_bank')],
            ['company_id' => $this->company->id, 'external_id' => 'acc-1100', 'external_code' => '1100', 'external_name' => 'Bank']));
        $this->asCompany($this->company, fn () => AccountingMapping::withoutGlobalScopes()->updateOrCreate(['connection_id' => $c->id, 'kind' => 'account', 'local_key' => (string) config('agri.accounts.direct_expense')],
            ['company_id' => $this->company->id, 'external_id' => 'acc-5720', 'external_code' => '5720', 'external_name' => 'Farm costs']));
        $expense = fn (string $key) => $this->asCompany($this->company, fn () => app(AgriLedger::class)->expense($this->north, 420.0, today()->toDateString(), 'Diesel for the planter',
            AgriLedger::dimensions($this->north, null, null, 'fuel'), 'bank', null, $key));

        // Our own ledger: the posting is the books; nothing is queued.
        $this->assertSame('internal', AgriOrgSetting::for($this->company->id)->book_of_record);
        $j1 = $expense('test-expense-1');
        $this->assertSame(0, IntegrationEvent::withoutGlobalScopes()->where('integration', 'accounting_push')->count());
        $this->assertArrayNotHasKey('journal', $this->seen);

        // Xero is the books: the same kind of posting is still recorded here and goes out as a manual journal.
        $this->travel(1)->minutes();
        $this->actingAs($this->admin)->post(route('agri.accounting.book'), ['book_of_record' => 'xero'])->assertSessionHasNoErrors();
        $j2 = $expense('test-expense-2');
        $this->assertDatabaseHas('fin_journals', ['id' => $j2->id, 'status' => 'posted']);
        $link = AccountingLink::withoutGlobalScopes()->where('record_type', 'journal')->where('local_id', $j2->id)->firstOrFail();
        $this->assertSame('synced', $link->status);
        $this->assertSame('mj-1', $link->external_id);
        $lines = json_decode($this->seen['journal'][0]->body(), true)['ManualJournals'][0]['JournalLines'];
        $this->assertEqualsCanonicalizing([420.0, -420.0], array_column($lines, 'LineAmount'));
        $this->assertSame(['Name' => 'Farm', 'Option' => 'North'], collect($lines)->firstWhere('AccountCode', '5720')['Tracking'][0]);
        $this->assertFalse(AccountingLink::withoutGlobalScopes()->where('record_type', 'journal')->where('local_id', $j1->id)->exists(), 'A posting from before the switch is not part of it.');

        // An invoice's own posting is not sent again as a journal: the invoice carries it.
        $invoice = $this->farmInvoice();
        $this->asCompany($this->company, fn () => app(AccountingSync::class)->run($this->company->id));
        $this->assertFalse(AccountingLink::withoutGlobalScopes()->where('record_type', 'journal')->where('local_id', $invoice->journal_id)->exists());
        $this->assertTrue(AccountingLink::withoutGlobalScopes()->where('record_type', 'invoice')->where('local_id', $invoice->id)->where('status', 'synced')->exists());
        $this->assertCount(1, $this->seen['journal']);

        // Back to our own ledger: nothing more goes out.
        $this->actingAs($this->admin)->post(route('agri.accounting.book'), ['book_of_record' => 'internal'])->assertSessionHasNoErrors();
        $expense('test-expense-3');
        $this->assertCount(1, $this->seen['journal']);
    }

    public function test_the_screens_render_and_refuse_without_permission(): void
    {
        config(['agri.accounting.providers.quickbooks.client_id' => null]);
        Http::fake();
        $this->actingAs($this->admin)->get(route('agri.accounting.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Accounting/Index')->where('book_of_record', 'internal')->has('providers', 3)
                ->where('providers.1.provider', 'quickbooks')->where('providers.1.status', 'needs_registration')->where('providers.1.registered', false));
        foreach (['mapping', 'log'] as $tab) {
            $this->actingAs($this->admin)->get(route('agri.accounting.index', ['tab' => $tab, 'provider' => 'xero']))->assertOk()
                ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Accounting/Index')->where('tab', $tab));
        }
        // No app registration: connecting says so instead of sending anyone anywhere.
        $this->actingAs($this->admin)->get(route('agri.accounting.connect', 'quickbooks'))->assertRedirect()->assertSessionHasErrors('provider');
        // Sage in South Africa is a different API: refused before connecting.
        $this->actingAs($this->admin)->get(route('agri.accounting.connect', 'sage').'?region=za')->assertSessionHasErrors('region');
        // The books cannot move to a system that is not connected.
        $this->actingAs($this->admin)->post(route('agri.accounting.book'), ['book_of_record' => 'xero'])->assertSessionHasErrors('book_of_record');
        Http::assertNothingSent();

        foreach ([$this->outsider, $this->worker, $this->supervisor] as $who) {
            $this->actingAs($who)->get(route('agri.accounting.index'))->assertForbidden();
            $this->actingAs($who)->get(route('agri.accounting.connect', 'xero'))->assertForbidden();
            $this->actingAs($who)->post(route('agri.accounting.book'), ['book_of_record' => 'internal'])->assertForbidden();
        }
    }
}
