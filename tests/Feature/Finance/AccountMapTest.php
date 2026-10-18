<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinAccountMapping;
use App\Modules\Finance\Accounting\Services\AccountMap;
use App\Modules\Operations\ProjectManagement\Providers\Integrated\AccountingExpenseSink;
use App\Modules\Tenancy\Services\CurrentCompany;
use Database\Seeders\FinAccountMappingSeeder;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Which ledger account a module's posting lands in.
 *
 * Every module that posts to the general ledger carried its account codes as constants, and a
 * tenant whose chart of accounts did not happen to use those codes got no postings at all —
 * correctly, since a journal against a guessed account is worse than no journal, but invisibly.
 * The cost was approved, the screen said so, and the books never moved, with nowhere to say which
 * accounts to use instead.
 *
 * The load-bearing property is that shipping this changed nothing: the defaults *are* the constants
 * they replaced, so every tenant resolves to the account it resolved to the day before.
 */
class AccountMapTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '1200')->exists()) {
            (new FinanceGlSeeder())->run();
        }

        (new FinAccountMappingSeeder())->run();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function map(): AccountMap
    {
        // A fresh instance each time: the resolver caches within a request, and a test that changes
        // a mapping and asks again is asking a different question.
        return new AccountMap();
    }

    // ── Nothing changed on the day it shipped ────────────────────────────────

    /**
     * Payroll's codes are all in the standard chart, and must keep resolving to exactly them.
     *
     * This is the "nothing changed" guarantee for the module that was already posting correctly.
     */
    public function test_payroll_still_resolves_to_the_accounts_it_always_used(): void
    {
        foreach (['salaries' => '6100', 'paye' => '2300', 'uif' => '2310',
                  'pension' => '2330', 'other' => '2350', 'net' => '2400'] as $key => $code) {
            $this->assertSame($code, $this->map()->codeFor('payroll', $key),
                "Payroll key {$key} no longer resolves to the account it used to.");
        }
    }

    /**
     * A tenant who really does have 5100 keeps posting to 5100.
     *
     * The platform defaults still carry the historic codes precisely so this does not change under
     * anybody who was already working.
     */
    public function test_a_chart_that_has_the_historic_codes_still_uses_them(): void
    {
        $this->postableAccount('5100', 'Materials');

        $this->assertSame('5100', $this->map()->codeFor('project_management', 'cost.material',
            AccountingExpenseSink::standardChartExpense()));
    }

    /**
     * The bug this uncovered: on the standard chart, project costs posted nowhere at all.
     *
     * `FinanceGlSeeder` has no 5100–5410 and never did — its cost-of-sales account is 5000 — so
     * every approved project cost took the "chart lacks the expected codes" branch, logged once,
     * and never reached the books. The fail-safe was correct and was guarding a door onto nothing.
     */
    public function test_on_the_standard_chart_a_project_cost_now_has_somewhere_to_post(): void
    {
        // Exactly what the seeded chart offers, and what the sink now falls back to.
        $this->assertFalse(FinAccount::where('code', '5100')->exists(),
            'The standard chart has gained a 5100; this test is no longer testing the reported case.');

        $this->assertSame('5000', $this->map()->codeFor(
            'project_management', 'cost.material', AccountingExpenseSink::standardChartExpense(),
        ));
    }

    /** Payables is in every chart and is the credit side of every project cost. */
    public function test_payables_resolves(): void
    {
        $this->assertSame('2100', $this->map()->codeFor('project_management', 'payables'));
    }

    // ── Overriding ───────────────────────────────────────────────────────────

    /** The whole point: a tenant on a different chart can say so. */
    public function test_a_tenant_mapping_beats_the_platform_default(): void
    {
        $this->postableAccount('5199', 'Materials, our numbering');

        $this->map()->set('project_management', 'cost.material', '5199');

        $this->assertSame('5199', $this->map()->codeFor('project_management', 'cost.material'));
    }

    /** Copy-on-write: one tenant's chart must not become everybody's. */
    public function test_overriding_leaves_the_platform_default_alone(): void
    {
        $this->postableAccount('5199');
        $this->map()->set('project_management', 'cost.material', '5199');

        $default = FinAccountMapping::whereNull('company_id')
            ->where('source_module', 'project_management')->where('key', 'cost.material')->first();

        $this->assertSame('5100', $default->account_code);
    }

    // ── Refusing to guess ────────────────────────────────────────────────────

    /**
     * A mapping to `5100` does not conjure account 5100.
     *
     * Returning the code anyway would push the failure down to posting time, where it appears in a
     * log nobody reads rather than on the screen where somebody could fix it.
     */
    public function test_a_code_that_is_not_in_the_chart_resolves_to_nothing(): void
    {
        $this->map()->set('project_management', 'cost.material', '9999');

        $this->assertNull($this->map()->codeFor('project_management', 'cost.material'));
    }

    /** A heading account carries no journal lines, so mapping to one is mapping to nothing. */
    public function test_a_heading_account_is_not_postable(): void
    {
        $this->postableAccount('5198', postable: false);
        $this->map()->set('project_management', 'cost.material', '5198');

        $this->assertNull($this->map()->codeFor('project_management', 'cost.material'));
    }

    /** A deactivated account is not somewhere to post either. */
    public function test_a_deactivated_account_is_not_postable(): void
    {
        $this->postableAccount('5197', active: false);
        $this->map()->set('project_management', 'cost.material', '5197');

        $this->assertNull($this->map()->codeFor('project_management', 'cost.material'));
    }

    /** An unknown key falls through to whatever the calling module offered. */
    public function test_an_unmapped_key_uses_the_modules_own_fallback(): void
    {
        $this->assertSame('2100', $this->map()->codeFor('project_management', 'never.seen', '2100'));
        $this->assertNull($this->map()->codeFor('project_management', 'never.seen', '9999'));
        $this->assertNull($this->map()->codeFor('project_management', 'never.seen'));
    }

    // ── What the settings screen needs ───────────────────────────────────────

    /** An unmapped key is the one thing a tenant needs to see, because it is costing them postings. */
    public function test_it_reports_every_key_and_whether_it_currently_posts(): void
    {
        $this->map()->set('project_management', 'cost.material', '9999');

        $rows = collect($this->map()->forModule('project_management'))->keyBy('key');

        $this->assertFalse($rows['cost.material']['postable'], 'A broken mapping was reported as working.');
        $this->assertSame('tenant', $rows['cost.material']['source']);

        $this->assertTrue($rows['payables']['postable']);
        $this->assertSame('default', $rows['payables']['source']);
    }

    public function test_it_reports_only_the_module_asked_for(): void
    {
        $keys = array_column($this->map()->forModule('payroll'), 'key');

        $this->assertContains('paye', $keys);
        $this->assertNotContains('cost.material', $keys);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function postableAccount(string $code, string $name = 'Test account', bool $postable = true, bool $active = true): FinAccount
    {
        return FinAccount::create([
            'code' => $code, 'name' => $name, 'type' => 'expense',
            'is_postable' => $postable, 'is_active' => $active,
        ]);
    }
}
