<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinPosting;
use App\Modules\Operations\ProjectManagement\Providers\Integrated\AccountingExpenseSink;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\FinAccountMappingSeeder;
use Database\Seeders\FinanceGlSeeder;

/**
 * An approved project cost reaching the general ledger.
 *
 * It never did. `AccountingExpenseSink` insisted on 5100 material, 5200 plant, 5300 subcontractor,
 * 5400 expense and 5410 travel; the platform's own chart of accounts has none of them and never
 * has — its cost-of-sales account is 5000. So every approved cost took the "chart of accounts lacks
 * the expected codes" branch, logged a line, and stopped. The guard was right, and it was guarding
 * a door that opened onto nothing.
 *
 * Nobody noticed because the failure is invisible from every screen: the cost is approved, the
 * approval stands, the project's actual spend is correct, and only the books are missing it.
 */
class ProjectCostReachesTheLedgerTest extends ProjectManagementTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '1200')->exists()) {
            (new FinanceGlSeeder())->run();
        }

        (new FinAccountMappingSeeder())->run();

        $this->company = Company::create(['name' => 'Aseda Ledger']);
        $this->entitleCompany($this->company);
    }

    private function sink(): AccountingExpenseSink
    {
        return app(AccountingExpenseSink::class);
    }

    /** @return array<string, mixed> */
    private function expense(array $overrides = []): array
    {
        return array_merge([
            'uuid'        => (string) \Illuminate\Support\Str::uuid(),
            'project'     => 'MP23001',
            'description' => 'Ready-mix concrete',
            'amount'      => 12500.00,
            'currency'    => 'ZAR',
            'category'    => 'material',
            'incurred_on' => now()->toDateString(),
        ], $overrides);
    }

    private function postingFor(string $uuid): ?FinPosting
    {
        return FinPosting::where('idempotency_key', 'pm_cost:' . $uuid)->first();
    }

    // ── The reported behaviour ───────────────────────────────────────────────

    public function test_an_approved_cost_now_posts_on_the_standard_chart(): void
    {
        $expense = $this->expense();

        $this->asCompany($this->company, fn () => $this->sink()->post($expense));

        $posting = $this->postingFor($expense['uuid']);

        $this->assertNotNull($posting, 'A project cost still does not reach the ledger.');
    }

    /** Debit cost of sales, credit payables — and it has to balance or the ledger rejects it. */
    public function test_the_journal_is_the_right_shape_and_balances(): void
    {
        $expense = $this->expense();

        $this->asCompany($this->company, fn () => $this->sink()->post($expense));

        $payload = $this->postingFor($expense['uuid'])->payload;

        $this->assertSame('5000', $payload['debits'][0]['account']);
        $this->assertSame('2100', $payload['credits'][0]['account']);
        $this->assertEqualsWithDelta(12500.00, $payload['debits'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(
            array_sum(array_column($payload['debits'], 'amount')),
            array_sum(array_column($payload['credits'], 'amount')),
            0.001,
            'The journal does not balance.',
        );
    }

    /** The project travels as a dimension, which is what lets the ledger answer "what has this job cost?". */
    public function test_the_project_travels_with_the_journal(): void
    {
        $expense = $this->expense();

        $this->asCompany($this->company, fn () => $this->sink()->post($expense));

        $payload = $this->postingFor($expense['uuid'])->payload;

        $this->assertSame('MP23001', $payload['debits'][0]['dimensions']['project']);
        $this->assertSame('material', $payload['metadata']['category']);
    }

    /** Approving the same cost twice must post one journal, not two. */
    public function test_posting_the_same_cost_twice_posts_once(): void
    {
        $expense = $this->expense();

        $this->asCompany($this->company, function () use ($expense) {
            $this->sink()->post($expense);
            $this->sink()->post($expense);
        });

        $this->assertSame(1, FinPosting::where('idempotency_key', 'pm_cost:' . $expense['uuid'])->count());
    }

    /** Every category has somewhere to go, not just the one that happened to be tested. */
    public function test_every_cost_category_posts(): void
    {
        foreach (['material', 'plant', 'subcontractor', 'expense', 'travel', 'other'] as $category) {
            $expense = $this->expense(['category' => $category]);

            $this->asCompany($this->company, fn () => $this->sink()->post($expense));

            $this->assertNotNull($this->postingFor($expense['uuid']),
                "A '{$category}' cost did not reach the ledger.");
        }
    }

    // ── Still refusing to guess ──────────────────────────────────────────────

    /**
     * The fail-safe must survive the fix. Mapping a category at a code that is not in the chart
     * still posts nothing — a journal against an account that does not exist is worse than none.
     */
    public function test_a_mapping_to_a_missing_account_still_posts_nothing(): void
    {
        $this->asCompany($this->company, function () {
            app(\App\Modules\Finance\Accounting\Services\AccountMap::class)
                ->set('project_management', 'cost.material', '9999');

            $expense = $this->expense();
            $this->sink()->post($expense);

            $this->assertNull($this->postingFor($expense['uuid']),
                'A cost was posted against an account that is not in the chart.');
        });
    }

    /** A screen must never imply the books moved when they did not. */
    public function test_it_reports_honestly_whether_it_posts(): void
    {
        $this->assertTrue($this->asCompany($this->company, fn () => $this->sink()->postsToLedger()));
    }
}
