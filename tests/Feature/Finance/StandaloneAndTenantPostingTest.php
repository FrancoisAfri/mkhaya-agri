<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Raising an invoice has to work on both editions, and they fail differently.
 *
 * The bootstrap seeds the chart of accounts and the fiscal calendar with a null company — shared
 * libraries every tenant reads. Two separate pieces of code then hid them the moment a posting
 * named a company: the ledger matched the account's company strictly, and the fiscal period did the
 * same *twice over*, once in its own lookup and once through the company scope on the model.
 *
 * The result was an installation that seeded perfectly and then refused the first invoice anybody
 * raised — "Account [1200] not found for company 1" — with the chart sitting right there.
 *
 * Standalone is the case that makes this urgent. There is one company, everything is seeded shared,
 * and every posting names that company, so on a single-tenant install *nothing* could be invoiced.
 */
class StandaloneAndTenantPostingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::table('companies')->count() === 0) {
            $this->markTestSkipped('No company in the test database.');
        }
    }

    /** A company with nothing of its own — what a freshly provisioned tenant looks like. */
    private function bareCompany(): int
    {
        $suffix = Str::random(5);

        return (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Bare ' . $suffix,
            'slug' => 'bare-' . strtolower($suffix),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The shared chart and calendar the bootstrap installs, if this database has none. */
    private function seedSharedBootstrap(): void
    {
        foreach ([
            ['1200', 'Accounts Receivable', 'asset'],
            ['2200', 'VAT Output (Control)', 'liability'],
            ['4000', 'Sales', 'income'],
        ] as [$code, $name, $type]) {
            if (! FinAccount::query()->whereNull('company_id')->where('code', $code)->exists()) {
                FinAccount::create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => null,
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'is_postable' => true,
                    'is_active' => true,
                ]);
            }
        }

        $shared = FinFiscalPeriod::query()
            ->whereNull('company_id')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->exists();

        if (! $shared) {
            FinFiscalPeriod::create([
                'company_id' => null,
                'fiscal_year' => (int) now()->year,
                'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(),
                'end_date' => now()->endOfMonth(),
                'status' => 'open',
            ]);
        }
    }

    public function test_a_company_can_invoice_against_the_shared_bootstrap(): void
    {
        /*
         * The reported failure, reproduced: a company whose chart and calendar are the seeded
         * shared ones. This is every standalone install and every newly provisioned tenant.
         */
        $this->seedSharedBootstrap();
        $companyId = $this->bareCompany();

        app(CurrentCompany::class)->set($companyId);

        $party = FinParty::create([
            'company_id' => $companyId,
            'kind' => 'customer',
            'code' => 'C' . Str::random(6),
            'name' => 'Acme',
            'credit_terms_days' => 30,
        ]);

        $sales = FinAccount::query()->whereNull('company_id')->where('code', '4000')->value('id');

        $invoice = app(ArApService::class)->createInvoice([
            'party_id' => $party->id,
            'direction' => 'receivable',
            'invoice_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $sales, 'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000],
            ],
        ]);

        $this->assertNotNull($invoice, 'A company reading the shared bootstrap has to be able to invoice.');
        $this->assertSame($companyId, (int) $invoice->company_id);
    }

    public function test_the_shared_fiscal_calendar_is_visible_to_a_tenant(): void
    {
        /*
         * Hidden twice: by the model's company scope, and again by forDate's own filter. Either one
         * alone was enough to refuse the posting, so both had to give.
         */
        $this->seedSharedBootstrap();
        $companyId = $this->bareCompany();

        app(CurrentCompany::class)->set($companyId);

        $this->assertNotNull(
            FinFiscalPeriod::forDate(now(), $companyId),
            'A tenant with no calendar of its own posts into the shared one.',
        );
    }

    public function test_a_companys_own_period_wins_over_the_shared_one(): void
    {
        /*
         * The reason the fallback is a fallback. A tenant that has closed its own March must not go
         * on posting into a shared March that is still open.
         */
        $this->seedSharedBootstrap();
        $companyId = $this->bareCompany();

        $own = FinFiscalPeriod::create([
            'company_id' => $companyId,
            'fiscal_year' => (int) now()->year,
            'period_no' => (int) now()->month,
            'start_date' => now()->startOfMonth(),
            'end_date' => now()->endOfMonth(),
            'status' => 'closed',
        ]);

        $found = FinFiscalPeriod::forDate(now(), $companyId);

        $this->assertSame($own->id, $found->id);
        $this->assertSame('closed', $found->status, 'Its own closed period must win over the open shared one.');
    }

    public function test_a_tenant_creating_a_period_owns_it_rather_than_sharing_it(): void
    {
        /*
         * `sharesGlobalRecords()` governs reads and writes both, so it is worth proving it did not
         * quietly turn every period a tenant opens into one everybody else can post into.
         */
        $companyId = $this->bareCompany();
        app(CurrentCompany::class)->set($companyId);

        $period = FinFiscalPeriod::create([
            'fiscal_year' => 2099,
            'period_no' => 1,
            'start_date' => '2099-01-01',
            'end_date' => '2099-01-31',
            'status' => 'open',
        ]);

        $this->assertSame($companyId, (int) $period->company_id, 'A tenant owns the periods it opens.');
    }

    public function test_the_console_still_seeds_shared_periods(): void
    {
        // The other half of the same flag: with no company active, the bootstrap's rows stay shared
        // rather than being stamped onto whichever company happened to be first.
        app(CurrentCompany::class)->clear();

        $period = FinFiscalPeriod::create([
            'fiscal_year' => 2098,
            'period_no' => 1,
            'start_date' => '2098-01-01',
            'end_date' => '2098-01-31',
            'status' => 'open',
        ]);

        $this->assertNull($period->company_id, 'A seeded period belongs to everybody.');
    }

    public function test_one_tenants_chart_is_never_borrowed_by_another(): void
    {
        /*
         * The fallback widens to shared rows, not to everybody's. Without this the fix would have
         * traded a broken install for a ledger that posts one customer's invoices against another
         * customer's accounts.
         */
        $mine = $this->bareCompany();
        $theirs = $this->bareCompany();

        $code = '48' . random_int(10, 99);

        FinAccount::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $theirs,
            'code' => $code,
            'name' => 'Their sales',
            'type' => 'income',
            'is_postable' => true,
            'is_active' => true,
        ]);

        $method = new \ReflectionMethod(\App\Modules\Finance\Accounting\Services\Ledger::class, 'resolveAccount');
        $method->setAccessible(true);

        $this->expectException(\App\Modules\Finance\Accounting\Exceptions\PostingException::class);
        $method->invoke(app(\App\Modules\Finance\Accounting\Services\Ledger::class), $code, $mine);
    }
}
