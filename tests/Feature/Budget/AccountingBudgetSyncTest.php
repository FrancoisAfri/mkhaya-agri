<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetPhasing;
use App\Modules\Budget\Models\FinancialYear;
use App\Modules\Budget\Services\AccountingBudgetSyncService;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinBudget;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Finance › Accounting's budgets, mirrored into the module that controls the money.
 *
 * The rules defended here: an accounting budget becomes one spendable budget per expense account,
 * income is not mirrored because nobody spends a revenue forecast, a draft plan is not spendable, a
 * re-sync updates rather than duplicates, and — the one that decides whether anybody trusts the
 * integration — a re-sync never touches the work that belongs to this module.
 */
class AccountingBudgetSyncTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);

        // The sync is a no-op unless the budget module is installed, which is correct behaviour —
        // but a test that skips because of it is a test that never runs. Install it here rather
        // than depending on what a `migrate:fresh` happened to leave behind.
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'budget_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Budget Management', 'active' => 1,
             'path' => '/budgets', 'category_id' => $this->categoryId(),
             'created_at' => now(), 'updated_at' => now()],
        );

        $this->assertTrue($this->sync()->enabled());
    }

    public function test_each_expense_account_becomes_its_own_spendable_budget(): void
    {
        $rent = $this->account('6100', 'Rent', 'expense');
        $power = $this->account('6200', 'Electricity', 'expense');

        $finBudget = $this->finBudget('approved', [
            [$rent, 0, 240_000],
            [$power, 0, 96_000],
        ]);

        $result = $this->sync()->sync($finBudget);

        $this->assertSame(2, $result['created']);
        $this->assertSame(240_000.0, (float) $this->mirror($finBudget, $rent)->allocated_amount);
        $this->assertSame(96_000.0, (float) $this->mirror($finBudget, $power)->allocated_amount);
    }

    public function test_income_accounts_are_not_mirrored(): void
    {
        $sales = $this->account('4000', 'Sales', 'income');
        $rent = $this->account('6100', 'Rent', 'expense');

        $result = $this->sync()->sync($this->finBudget('approved', [
            [$sales, 0, 900_000],
            [$rent, 0, 240_000],
        ]));

        // A revenue forecast is not money anybody commits against; a budget you can raise a
        // purchase order against for "Sales" is a nonsense.
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_a_draft_accounting_budget_is_mirrored_but_not_spendable(): void
    {
        $rent = $this->account('6100', 'Rent', 'expense');
        $finBudget = $this->finBudget('draft', [[$rent, 0, 240_000]]);

        $this->sync()->sync($finBudget);

        $this->assertSame('draft', $this->mirror($finBudget, $rent)->status);
    }

    public function test_approving_upstream_makes_the_mirror_spendable(): void
    {
        $rent = $this->account('6100', 'Rent', 'expense');
        $finBudget = $this->finBudget('draft', [[$rent, 0, 240_000]]);
        $this->sync()->sync($finBudget);

        $finBudget->update(['status' => 'approved']);
        $this->sync()->sync($finBudget);

        // Accounting's approval is the approval. Asking a company to sign the same budget twice,
        // once per module, is how an integration gets switched off.
        $this->assertSame('active', $this->mirror($finBudget, $rent)->status);
    }

    public function test_a_resync_updates_rather_than_duplicating_and_records_the_revision(): void
    {
        $rent = $this->account('6100', 'Rent', 'expense');
        $finBudget = $this->finBudget('approved', [[$rent, 0, 240_000]]);
        $this->sync()->sync($finBudget);

        $finBudget->lines()->update(['amount' => 300_000]);
        $result = $this->sync()->sync($finBudget->fresh('lines'));

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);

        $mirror = $this->mirror($finBudget, $rent);
        $this->assertSame(300_000.0, (float) $mirror->allocated_amount);
        $this->assertSame(1, Budget::withoutGlobalScopes()->where('source_id', $finBudget->id)->count());

        // The number moved and the trail has to say why, exactly as an adjustment would.
        $this->assertTrue($mirror->revisions()->where('reason_type', 'adjustment')->exists());
    }

    public function test_a_resync_never_touches_what_belongs_to_this_module(): void
    {
        $rent = $this->account('6100', 'Rent', 'expense');
        $finBudget = $this->finBudget('approved', [[$rent, 0, 240_000]]);
        $this->sync()->sync($finBudget);

        $mirror = $this->mirror($finBudget, $rent);

        // The things a person does here after the mirror lands: hold funds, name an owner.
        app(BudgetService::class)->reserveBudget($mirror, 50_000, 'manual');
        $mirror->owners()->create(['user_id' => 1, 'role' => 'owner']);

        $finBudget->lines()->update(['amount' => 300_000]);
        $this->sync()->sync($finBudget->fresh('lines'));

        $amounts = app(BudgetService::class)->amounts($mirror->fresh());

        $this->assertSame(50_000.0, $amounts['reserved'], 'A sync that wipes reservations is a sync nobody can leave switched on.');
        $this->assertSame(250_000.0, $amounts['available']);
        $this->assertSame(1, $mirror->owners()->count());
    }

    public function test_monthly_lines_become_phasing(): void
    {
        $year = FinancialYear::create([
            'name' => 'FY '.date('Y'), 'start_date' => date('Y').'-01-01', 'end_date' => date('Y').'-12-31',
        ]);

        foreach ([1, 2] as $month) {
            BudgetPeriod::create([
                'financial_year_id' => $year->id,
                'name'              => date('M', mktime(0, 0, 0, $month, 1)),
                'start_date'        => sprintf('%s-%02d-01', date('Y'), $month),
                'end_date'          => sprintf('%s-%02d-28', date('Y'), $month),
            ]);
        }

        $rent = $this->account('6100', 'Rent', 'expense');
        $finBudget = $this->finBudget('approved', [[$rent, 1, 20_000], [$rent, 2, 25_000]], (int) date('Y'));

        $this->sync()->sync($finBudget);

        $mirror = $this->mirror($finBudget, $rent);

        // Both months mirrored, and the allocation is their sum.
        $this->assertSame(45_000.0, (float) $mirror->allocated_amount);
        $this->assertSame(2, BudgetPhasing::where('budget_id', $mirror->id)->count());
        $this->assertSame(20_000.0, (float) BudgetPhasing::where('budget_id', $mirror->id)
            ->orderBy('budget_period_id')->first()->amount);
    }

    public function test_a_mirrored_budget_is_marked_as_managed_elsewhere(): void
    {
        $rent = $this->account('6100', 'Rent', 'expense');
        $finBudget = $this->finBudget('approved', [[$rent, 0, 240_000]]);
        $this->sync()->sync($finBudget);

        $mirror = $this->mirror($finBudget, $rent);

        // The page uses this to stop offering an edit the next sync would overwrite.
        $this->assertTrue($mirror->isManaged());
        $this->assertSame(AccountingBudgetSyncService::SOURCE, $mirror->source_module);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function sync(): AccountingBudgetSyncService
    {
        return app(AccountingBudgetSyncService::class);
    }

    /** Any nav category will do — the sync only reads `code_name` and `active`. */
    private function categoryId(): int
    {
        $existing = DB::table('security_categories')->min('id');

        return $existing !== null ? (int) $existing : (int) DB::table('security_categories')->insertGetId([
            'name' => 'Test Category', 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mirror(FinBudget $finBudget, FinAccount $account): Budget
    {
        return Budget::withoutGlobalScopes()
            ->where('source_id', $finBudget->id)
            ->where('source_ref', (string) $account->id)
            ->firstOrFail();
    }

    private function account(string $code, string $name, string $type): FinAccount
    {
        return FinAccount::create([
            'uuid' => (string) Str::uuid(),
            'code' => $code.Str::random(4), 'name' => $name, 'type' => $type,
            'normal_balance' => $type === 'income' ? 'credit' : 'debit',
            'is_postable' => true, 'is_active' => true,
        ]);
    }

    /** @param array<int, array{0: FinAccount, 1: int, 2: float}> $lines */
    private function finBudget(string $status, array $lines, ?int $fiscalYear = null): FinBudget
    {
        $budget = FinBudget::create([
            'name' => 'Ops plan '.Str::random(4),
            'fiscal_year' => $fiscalYear ?? (int) date('Y'),
            'status' => $status,
        ]);

        foreach ($lines as [$account, $periodNo, $amount]) {
            $budget->lines()->create([
                'account_id' => $account->id, 'period_no' => $periodNo, 'amount' => $amount,
            ]);
        }

        return $budget->fresh('lines');
    }
}
