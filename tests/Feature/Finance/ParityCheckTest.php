<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Services\FinanceReportService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `finance:parity` — the daily integrity check, and the reason a green one used to mean nothing.
 *
 * It summed the whole estate: one trial balance across every tenant. Debits and credits are signed,
 * so a tenant 20,000 short and a tenant 20,000 long cancel exactly, and the check reports OK while
 * both sets of books are wrong. That is the worst possible failure for a monitor — not a false
 * alarm, which someone investigates, but a false all-clear, which nobody does.
 *
 * The second failure is subtler and was introduced by the obvious fix. Rows posted before Finance
 * was tenanted carry no company_id, as does everything in a single-tenant self-hosted install.
 * Iterating the companies table walks straight past them, so on a database whose ledger is entirely
 * company-less the "fixed" check would examine nothing at all — and pass.
 */
class ParityCheckTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyA;

    private int $companyB;

    private float $unassignedBefore = 0.0;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Both tenants are created rather than found.
         *
         * Reaching for the seeded company is the obvious shortcut and it makes these tests depend on
         * whatever that company's ledger happens to hold — which is demo data that changes whenever
         * the database is reseeded. A test asserting "this company's books balance" then fails for
         * reasons that have nothing to do with the code under test. Two fresh companies start with
         * no ledger at all, so every number below is one this test put there.
         */
        $this->companyA = $this->makeCompany('Parity Fixture A');
        $this->companyB = $this->makeCompany('Parity Fixture Co');

        Cache::flush();
        app(CurrentCompany::class)->clear();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function makeCompany(string $name): int
    {
        return (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A one-sided journal for $companyId: $amount of debit with no matching credit.
     *
     * A pass a negative amount to make the books credit-heavy instead. Two calls with opposite
     * signs, against different companies, is the exact shape the old check could not see.
     */
    private function postImbalance(?int $companyId, float $amount): void
    {
        $accountId = DB::table('fin_accounts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'entity_id' => $this->businessFor($companyId),
            'code' => 'T'.random_int(10000, 99999),
            'name' => 'Parity fixture account',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $journalId = DB::table('fin_journals')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'entity_id' => $this->businessFor($companyId),
            'journal_no' => 'JF-'.Str::upper(Str::random(8)),
            'journal_date' => now()->toDateString(),
            'source_module' => 'test',
            'currency' => 'ZAR',
            'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('fin_journal_lines')->insert([
            'journal_id' => $journalId,
            'account_id' => $accountId,
            'company_id' => $companyId,
            // The same business as its journal — the composite key insists on it.
            'entity_id' => $this->businessFor($companyId),
            'debit' => $amount > 0 ? $amount : 0,
            'credit' => $amount < 0 ? abs($amount) : 0,
            'currency' => 'ZAR',
            'fx_rate' => 1,
            'base_debit' => $amount > 0 ? $amount : 0,
            'base_credit' => $amount < 0 ? abs($amount) : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_two_tenants_whose_imbalances_cancel_are_both_reported(): void
    {
        /*
         * The regression this suite exists for. Company A is 20,000 debit-heavy and company B is
         * 20,000 credit-heavy. Summed together the ledger balances perfectly; taken separately,
         * neither set of books does.
         */
        $this->postImbalance($this->companyA, 20000);
        $this->postImbalance($this->companyB, -20000);

        $reports = app(FinanceReportService::class);

        // First, prove the trap is really set: consolidated, these two books balance to the cent.
        // This is exactly what the old check measured, and exactly why it reported OK.
        $a = $reports->trialBalance($this->companyA);
        $b = $reports->trialBalance($this->companyB);

        $this->assertSame(
            round($a['total_debit'] + $b['total_debit'], 2),
            round($a['total_credit'] + $b['total_credit'], 2),
            'The fixture no longer cancels out, so this test would pass for the wrong reason.',
        );

        // Separately, neither one does.
        $this->assertFalse($a['balanced']);
        $this->assertFalse($b['balanced']);

        $this->artisan('finance:parity')->assertFailed();
    }

    public function test_the_failure_names_the_company_so_somebody_can_act_on_it(): void
    {
        // "Parity FAILED" with no company attached tells an operator that something, somewhere, has
        // drifted — which is not an alert, it is a rumour.
        $this->postImbalance($this->companyB, 5000);

        $this->artisan('finance:parity')
            ->expectsOutputToContain('Parity Fixture Co')
            ->assertFailed();
    }

    public function test_books_with_no_company_are_checked_rather_than_skipped(): void
    {
        /*
         * Everything posted before Finance was tenanted has a null company_id. Iterating the
         * companies table would skip it entirely — and on a database where the whole ledger is
         * company-less, that produces a check which examines nothing and passes.
         */
        $this->postImbalance(null, 12345);

        $this->artisan('finance:parity', ['--company' => FinanceReportService::UNASSIGNED])
            ->expectsOutputToContain('Unassigned books')
            ->assertFailed();
    }

    public function test_a_single_company_can_be_checked_on_its_own(): void
    {
        // How an operator re-checks one customer after a correction, without reading the estate.
        $this->postImbalance($this->companyB, 7500);

        $this->artisan('finance:parity', ['--company' => $this->companyA])->assertSuccessful();
        $this->artisan('finance:parity', ['--company' => $this->companyB])->assertFailed();
    }

    public function test_a_balanced_set_of_books_passes(): void
    {
        // The check has to be able to say yes, or a permanently red monitor gets muted.
        $this->postImbalance($this->companyB, 900);
        $this->postImbalance($this->companyB, -900);

        $this->artisan('finance:parity', ['--company' => $this->companyB])->assertSuccessful();
    }

    public function test_the_books_are_taken_from_the_ledger_not_from_the_company_list(): void
    {
        /*
         * A company that has been deactivated still has books that must balance, and a company with
         * no ledger has nothing to check. Driving the loop off `companies` gets both backwards.
         */
        $this->postImbalance($this->companyB, 1000);

        DB::table('companies')->where('id', $this->companyB)->update(['status' => 'inactive']);

        $this->artisan('finance:parity')
            ->expectsOutputToContain('Parity Fixture Co')
            ->assertFailed();
    }

    public function test_the_unassigned_scope_asks_for_company_less_rows_rather_than_all_rows(): void
    {
        /*
         * The distinction the whole fix rests on. `null` has always meant "every book" to these
         * services; UNASSIGNED has to mean the opposite, or the leftover rows get folded back into
         * every tenant's totals and the estate-wide bug returns by the back door.
         */
        $reports = app(FinanceReportService::class);
        $this->unassignedBefore = $reports->trialBalance(FinanceReportService::UNASSIGNED)['total_debit'];

        $this->postImbalance($this->companyB, 4321);
        $this->postImbalance(null, 8765);

        $unassigned = $reports->trialBalance(FinanceReportService::UNASSIGNED)['total_debit'];
        $tenant = $reports->trialBalance($this->companyB)['total_debit'];

        // The tenant sees exactly what it posted, and nothing company-less.
        $this->assertSame(4321.0, round($tenant, 2), "The tenant's books picked up company-less rows.");

        // The unassigned view sees the company-less row and not the tenant's. Asserted as a delta
        // against the same view taken before, because the database also holds seed data and a test
        // that assumes otherwise breaks the next time somebody reseeds it.
        $this->assertSame(
            round($this->unassignedBefore + 8765.0, 2),
            round($unassigned, 2),
            'The unassigned view moved by something other than the row this test added.',
        );
    }
}
