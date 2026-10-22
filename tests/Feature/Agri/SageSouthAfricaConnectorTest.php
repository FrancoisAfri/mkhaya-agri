<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Accounting\AccountingConnections;
use App\Modules\Agri\Accounting\AccountingSync;
use App\Modules\Agri\Accounting\SageSouthAfricaConnector;
use App\Modules\Agri\Models\AccountingConnection;
use App\Modules\Agri\Models\AccountingLink;
use App\Modules\Agri\Models\AccountingMapping;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Services\AgriLedger;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Shared\Party\PartyDirectory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Sage Business Cloud Accounting South Africa (Accounting API v2.0.0), faked at the HTTP boundary:
 * the API key on the query and the Sage sign-in as HTTP Basic on every call, the company chosen from
 * Company/Get and named on every call after, the credentials kept encrypted and never shown, lists
 * read page by page ($top / $skip), a farm invoice out as a TaxInvoice with its account, tax type and
 * analysis code mapped, a lost answer found again by our reference instead of sent twice, a refusal
 * logged, a customer receipt marking ours paid, a journal as balanced journal entries, and a 429
 * waited out.
 */
class SageSouthAfricaConnectorTest extends AgriTestCase
{
    private const BASE = 'https://resellers.accounting.sageone.co.za/api/2.0.0/';

    private const USER = 'farmer@boplaas.co.za';

    private const PASS = 's3cret!pw';

    private Farm $north;

    /** Requests seen, by "Entity/Action". */
    private array $seen = [];

    /** What "Sage" holds: TaxInvoice / JournalEntry rows saved so far. */
    private array $held = ['TaxInvoice' => [], 'JournalEntry' => []];

    /** The next TaxInvoice/Save: null answers, 'refuse' says no, 'lose' saves it and then fails the answer. */
    private ?string $invoiceMode = null;

    private string $apiKey = 'platform-za-key';

    private array $companies = [['ID' => 501, 'Name' => 'Boplaas Boerdery (Sage SA)', 'Active' => true]];

    private int $throttleOnce = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->north = $this->farm('NORTH');
        config(['agri.accounting.providers.sage.regions.za.api_key' => 'platform-za-key', 'agri.accounting.providers.sage.regions.za.page_size' => 2,
            'agri.accounting.providers.sage.client_id' => null]);   // no OAuth app needed for South Africa
        Http::preventStrayRequests();
        $this->fake();
    }

    // ── the fake Sage SA ─────────────────────────────────────────────────────────────────────

    private function fake(): void
    {
        Http::fake(function (HttpRequest $r) {
            $this->assertStringStartsWith(self::BASE, $r->url(), 'Every call goes to the configured Sage SA API.');
            $op = substr((string) parse_url($r->url(), PHP_URL_PATH), strlen(parse_url(self::BASE, PHP_URL_PATH)));
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $this->seen[$op][] = $r;
            if (! $r->hasHeader('Authorization', 'Basic '.base64_encode(self::USER.':'.self::PASS))) {
                return Http::response(['Message' => 'Authorization has been denied for this request.'], 401);
            }
            $this->assertSame($this->apiKey, $q['apikey'] ?? null, 'Every call carries the API key.');
            if ($op !== 'Company/Get') {
                $this->assertSame((string) AccountingConnection::withoutGlobalScopes()->where('provider', 'sage')->value('tenant_id'), $q['companyid'] ?? null, 'Every call names the company.');
            }
            if ($this->throttleOnce > 0 && $op === 'Account/Get') {
                $this->throttleOnce--;

                return Http::response(['Message' => 'Too many requests'], 429, ['Retry-After' => '7']);
            }
            $list = fn (array $rows) => Http::response(['TotalResults' => count($rows), 'ReturnedResults' => count(array_slice($rows, (int) ($q['$skip'] ?? 0), (int) ($q['$top'] ?? 100))),
                'Results' => array_values(array_slice($rows, (int) ($q['$skip'] ?? 0), (int) ($q['$top'] ?? 100)))]);
            $byRef = fn (string $entity) => $list(array_values(array_filter($this->held[$entity],
                fn ($x) => preg_match("/Reference eq '((?:[^']|'')*)'/", (string) ($q['$filter'] ?? ''), $m) && $x['Reference'] === str_replace("''", "'", $m[1]))));

            return match ($op) {
                'Company/Get' => $list($this->companies),
                'Account/Get' => $list([['ID' => 9001, 'Name' => 'Sales', 'Active' => true, 'Category' => ['Description' => 'Sales']],
                    ['ID' => 9002, 'Name' => 'Farm costs', 'Active' => true], ['ID' => 9003, 'Name' => 'Bank', 'Active' => true]]),
                'TaxType/Get' => $list([['ID' => 77, 'Name' => 'Standard Rate', 'Percentage' => 15, 'Active' => true]]),
                'AnalysisCategory/Get' => $list([['ID' => 3, 'Description' => 'Farm']]),
                'AnalysisType/Get' => $list([['ID' => 31, 'Description' => 'North', 'AnalysisCategoryId' => 3], ['ID' => 32, 'Description' => 'South', 'AnalysisCategoryId' => 3]]),
                'Customer/Get', 'Supplier/Get' => $list([]),
                'Customer/Save' => Http::response(['ID' => 6001, 'Name' => $r['Name']]),
                'TaxInvoice/Get' => $byRef('TaxInvoice'),
                'TaxInvoice/Save' => $this->saveInvoice($r),
                'JournalEntry/Get' => $byRef('JournalEntry'),
                'JournalEntry/Save' => Http::response($this->held['JournalEntry'][] = ['ID' => 4000 + count($this->held['JournalEntry']) + 1, 'Reference' => $r['Reference']]),
                'CustomerReceipt/Get' => $list([['ID' => 801, 'CustomerId' => 6001, 'Date' => today()->toDateString().'T00:00:00', 'Reference' => 'EFT 7781', 'Total' => 1150.0]]),
                'Allocation/Get' => $list(str_contains((string) ($q['$filter'] ?? ''), 'SourceDocumentId eq 801') ? [['SourceDocumentId' => 801, 'AllocatedToDocumentId' => 7001, 'Total' => 1150.0]] : []),
                'SupplierPayment/Get' => $list([]),
                default => Http::response(['Message' => "Unexpected {$r->method()} {$op}"], 404),
            };
        });
    }

    private function saveInvoice(HttpRequest $r)
    {
        $mode = $this->invoiceMode;
        $this->invoiceMode = null;
        if ($mode === 'refuse') {
            return Http::response(['Message' => 'The request is invalid.', 'ModelState' => ['TaxTypeId' => ['The tax type is not valid for this account.']]], 400);
        }
        $this->held['TaxInvoice'][] = $row = ['ID' => 7001, 'DocumentNumber' => 'INV0001', 'Reference' => $r['Reference'], 'CustomerId' => $r['CustomerId']];

        return $mode === 'lose' ? Http::response('<html>502 Bad Gateway</html>', 502) : Http::response($row);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function signIn(array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('agri.accounting.sage.sign-in'), $extra + ['username' => self::USER, 'password' => self::PASS]);
    }

    private function connection(): AccountingConnection
    {
        return AccountingConnection::withoutGlobalScopes()->where('company_id', $this->company->id)->where('provider', 'sage')->firstOrFail();
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

    /** Read their chart (page by page), then map produce sales, STD and the farm through the mapping screen. */
    private function mapEverything(AccountingConnection $c): void
    {
        $this->actingAs($this->admin)->post(route('agri.accounting.pull', 'sage'))->assertSessionHas('success');
        $cat = $c->fresh()->catalog;
        $this->assertCount(3, $cat['accounts'], 'Three accounts read over two pages of two.');
        $this->assertCount(2, $this->seen['Account/Get']);
        $this->assertSame(['id' => '3', 'name' => 'Farm', 'slot' => 1], array_diff_key($cat['tracking'][0], ['options' => 1]));
        $this->actingAs($this->admin)->post(route('agri.accounting.mappings', 'sage'), ['rows' => [
            ['kind' => 'account', 'local_key' => (string) config('agri.accounts.produce_sales'), 'external_id' => '9001', 'external_name' => 'Sales'],
            ['kind' => 'tax', 'local_key' => 'STD', 'external_id' => '77', 'external_code' => '77', 'external_name' => 'Standard Rate'],
            ['kind' => 'tracking', 'local_key' => 'farm:'.$this->north->id, 'external_id' => '31', 'external_name' => 'North', 'extra' => ['category_id' => '3', 'category' => 'Farm']],
        ]])->assertSessionHasNoErrors();
    }

    // ── tests ────────────────────────────────────────────────────────────────────────────────

    public function test_sign_in_sends_basic_auth_and_the_api_key_keeps_them_encrypted_and_offers_the_companies(): void
    {
        $this->companies[] = ['ID' => 502, 'Name' => 'Boplaas Vee (Sage SA)', 'Active' => true];

        // A wrong password: Sage's refusal is shown and nothing is stored.
        $this->actingAs($this->admin)->post(route('agri.accounting.sage.sign-in'), ['username' => self::USER, 'password' => 'wrong'])->assertSessionHasErrors('username');
        $this->assertStringContainsString('did not accept the username, password or API key', (string) $this->connection()->last_error);
        $this->assertNull($this->connection()->tokens);

        $this->signIn()->assertSessionHasNoErrors()->assertSessionHas('success');
        $c = $this->connection();
        $this->assertSame('za', $c->region);
        $this->assertSame('choose_tenant', $c->status);
        $this->assertSame([['id' => '501', 'name' => 'Boplaas Boerdery (Sage SA)'], ['id' => '502', 'name' => 'Boplaas Vee (Sage SA)']], $c->tenants);
        $this->assertInstanceOf(SageSouthAfricaConnector::class, app(AccountingConnections::class)->connector($c));
        $first = $this->seen['Company/Get'][1];
        $this->assertTrue($first->hasHeader('Authorization', 'Basic '.base64_encode(self::USER.':'.self::PASS)));
        $this->assertStringNotContainsString('companyid', $first->url());

        // Encrypted at rest, never in the model's array or on the screen.
        $raw = (string) DB::table('agri_accounting_connections')->where('id', $c->id)->value('tokens');
        $this->assertStringNotContainsString(self::PASS, $raw);
        $this->assertStringNotContainsString(self::USER, $raw);
        $this->assertSame(self::PASS, $c->tokens()['password']);
        $this->assertArrayNotHasKey('tokens', $c->toArray());
        $page = $this->actingAs($this->admin)->get(route('agri.accounting.index'))->assertOk();
        $page->assertInertia(fn (Assert $p) => $p->where('providers.2.sign_in', 'password')->where('providers.2.username', self::USER)->where('providers.2.has_password', true)
            ->where('providers.2.own_api_key', false)->where('providers.2.platform_api_key', true)->where('providers.2.status', 'choose_tenant')
            ->where('regions', fn ($r) => collect($r)->firstWhere('value', 'za')['sign_in'] === 'password'));
        $this->assertStringNotContainsString(self::PASS, $page->getContent());

        // The company chosen; every later call names it.
        $this->actingAs($this->admin)->post(route('agri.accounting.tenant', 'sage'), ['tenant_id' => '502'])->assertSessionHas('success');
        $this->assertTrue($this->connection()->isConnected());
        $this->assertSame('Boplaas Vee (Sage SA)', $this->connection()->tenant_name);
        $this->actingAs($this->admin)->post(route('agri.accounting.pull', 'sage'))->assertSessionHas('success');
        $this->assertStringContainsString('companyid=502', $this->seen['TaxType/Get'][0]->url());

        // Signing in again with a blank password keeps the stored one; an organisation's own API key overrides the platform's.
        $this->apiKey = 'own-key-123';
        $this->signIn(['password' => '', 'api_key' => 'own-key-123'])->assertSessionHasNoErrors();
        $c = $this->connection();
        $this->assertSame('connected', $c->status, 'The company chosen before is still there: kept.');
        $this->assertSame('502', $c->tenant_id);
        $this->assertSame(['username' => self::USER, 'password' => self::PASS, 'api_key' => 'own-key-123'], $c->tokens());
        $this->assertStringNotContainsString('own-key-123', (string) DB::table('agri_accounting_connections')->where('id', $c->id)->value('tokens'));

        // The OAuth route does not apply: it says how to sign in instead.
        $this->actingAs($this->admin)->get(route('agri.accounting.connect', 'sage').'?region=za')->assertSessionHasErrors('region');
        // Disconnecting forgets the password.
        $this->actingAs($this->admin)->post(route('agri.accounting.disconnect', 'sage'))->assertSessionHas('success');
        $this->assertSame([], $this->connection()->tokens());
        $this->assertSame('disconnected', $this->connection()->status);
    }

    public function test_an_invoice_goes_out_mapped_a_lost_answer_is_not_sent_twice_and_its_receipt_marks_it_paid(): void
    {
        $this->signIn()->assertSessionHasNoErrors();
        $c = $this->connection();
        $this->assertSame('connected', $c->status, 'One company: chosen at once.');
        $this->mapEverything($c);
        $this->actingAs($this->admin)->post(route('agri.accounting.book'), ['book_of_record' => 'sage'])->assertSessionHasNoErrors();
        $invoice = $this->farmInvoice();

        // Sage refuses it: its own message is kept on the link and the integration message.
        $this->invoiceMode = 'refuse';
        $this->asCompany($this->company, fn () => app(AccountingSync::class)->run($this->company->id));
        $link = AccountingLink::withoutGlobalScopes()->where('record_type', 'invoice')->where('local_id', $invoice->id)->firstOrFail();
        $this->assertSame('failed', $link->status);
        $this->assertStringContainsString('The tax type is not valid for this account', (string) $link->last_error);
        $this->assertStringContainsString('(400)', (string) $link->last_error);

        // Sage takes it but the answer is lost: still failed here, and Sage now holds one.
        $this->invoiceMode = 'lose';
        $this->actingAs($this->admin)->post(route('agri.accounting.links.retry', $link->uuid))->assertSessionHas('error');
        $this->assertSame('failed', $link->fresh()->status);
        $this->assertCount(1, $this->held['TaxInvoice']);

        // The retry looks it up by our reference first and takes its id: no second copy.
        $this->actingAs($this->admin)->post(route('agri.accounting.links.retry', $link->uuid))->assertSessionHas('success');
        $link->refresh();
        $this->assertSame('synced', $link->status);
        $this->assertSame('TaxInvoice:7001', $link->external_id);
        $this->assertSame('INV0001', $link->external_ref);
        $this->assertCount(2, $this->seen['TaxInvoice/Save'], 'Refused once, lost once; the retry made none.');
        $this->assertCount(3, $this->seen['TaxInvoice/Get']);
        $this->assertCount(1, $this->held['TaxInvoice']);
        $this->assertCount(1, $this->seen['Customer/Save'], 'The customer is created once, then remembered.');
        $this->assertSame('customer:6001', AccountingMapping::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', 'contact')->value('external_id'));

        // What went out: our account, tax and farm as theirs, on an account line.
        $lookup = urldecode($this->seen['TaxInvoice/Get'][0]->url());
        $this->assertStringContainsString("\$filter=Reference eq '{$invoice->number}' and CustomerId eq 6001", $lookup);
        $sent = json_decode($this->seen['TaxInvoice/Save'][1]->body(), true);
        $this->assertSame(6001, $sent['CustomerId']);
        $this->assertSame($invoice->number, $sent['Reference']);
        $line = $sent['Lines'][0];
        $this->assertSame(1, $line['LineType']);
        $this->assertSame(9001, $line['SelectionId']);
        $this->assertSame(77, $line['TaxTypeId']);
        $this->assertSame(31, $line['AnalysisCategoryId1']);
        $this->assertEquals(1000.0, $line['Exclusive']);
        $this->assertEquals(150.0, $line['Tax']);
        $this->assertEquals(1150.0, $line['Total']);
        $this->assertStringContainsString('[Farm NORTH]', $line['Description']);

        // Syncing again sends nothing more.
        $this->asCompany($this->company, fn () => app(AccountingSync::class)->run($this->company->id));
        $this->assertCount(2, $this->seen['TaxInvoice/Save']);

        // The customer receipt there (allocated to our TaxInvoice) marks ours paid — once.
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEquals(1150.0, (float) $invoice->fresh()->amount_paid);
        $this->assertStringContainsString("Modified ge datetime'", urldecode($this->seen['CustomerReceipt/Get'][0]->url()));
        $this->connection()->forceFill(['payments_pulled_at' => null])->save();
        $this->asCompany($this->company, fn () => app(AccountingSync::class)->run($this->company->id));
        $this->assertEquals(1150.0, (float) $invoice->fresh()->amount_paid);
        $this->assertSame(1, DB::table('fin_payment_allocations')->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, AccountingLink::withoutGlobalScopes()->where('record_type', 'payment')->where('direction', 'in')->count());
    }

    public function test_a_journal_goes_as_balanced_journal_entries_found_again_on_a_retry(): void
    {
        $this->signIn()->assertSessionHasNoErrors();
        $c = $this->connection();
        $this->mapEverything($c);
        $connector = app(AccountingConnections::class)->connector($c->fresh());
        $acc = fn (string $id) => ['id' => $id, 'code' => $id, 'name' => null, 'local' => null];
        $doc = ['number' => 'JNL-000042', 'date' => today()->toDateString(), 'narration' => 'Fuel and seed allocation', 'currency' => 'ZAR', 'lines' => [
            ['description' => 'Diesel', 'debit' => 100.0, 'credit' => 0.0, 'account' => $acc('9002'), 'tracking' => [['dimension' => 'farm', 'category_id' => '3', 'option_id' => '31']], 'labels' => ['farm' => 'Farm NORTH']],
            ['description' => 'Seed', 'debit' => 50.0, 'credit' => 0.0, 'account' => $acc('9001'), 'tracking' => [], 'labels' => []],
            ['description' => 'Paid', 'debit' => 0.0, 'credit' => 150.0, 'account' => $acc('9003'), 'tracking' => [], 'labels' => []],
        ]];

        $r = $this->asCompany($this->company, fn () => $connector->pushJournal($doc, 'k'));
        $this->assertSame('JournalEntry:4001,4002', $r['id']);
        $sent = array_map(fn ($x) => json_decode($x->body(), true), $this->seen['JournalEntry/Save']);
        $this->assertSame([[9002, 9003, 100], [9001, 9003, 50]], array_map(fn ($e) => [$e['AccountId'], $e['ContraAccountId'], (int) $e['Amount']], $sent));
        $this->assertSame(['JNL-000042/1', 'JNL-000042/2'], array_column($sent, 'Reference'));
        $this->assertSame(1, $sent[0]['Effect']);
        $this->assertSame(31, $sent[0]['AnalysisCategoryId1']);
        $this->assertStringContainsString('[Farm NORTH]', $sent[0]['Description']);

        // Sent again (an answer lost after Sage saved them): both are found by reference, none made.
        $again = $this->asCompany($this->company, fn () => $connector->pushJournal($doc, 'k'));
        $this->assertSame($r['id'], $again['id']);
        $this->assertCount(2, $this->seen['JournalEntry/Save']);
    }

    public function test_a_rate_limited_call_waits_for_retry_after_and_goes_through(): void
    {
        Sleep::fake();
        $this->signIn()->assertSessionHasNoErrors();
        $this->throttleOnce = 1;

        $this->actingAs($this->admin)->post(route('agri.accounting.pull', 'sage'))->assertSessionHas('success');

        Sleep::assertSlept(fn ($d) => (int) $d->totalSeconds === 7, 1);
        $this->assertCount(3, $this->seen['Account/Get'], 'One 429, then the two pages.');
        $this->assertCount(3, $this->connection()->catalog['accounts']);
    }
}
