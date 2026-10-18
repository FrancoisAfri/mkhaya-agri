<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinGroup;
use App\Modules\Finance\Accounting\Services\ConsolidationService;
use App\Modules\Finance\Accounting\Services\Ledger;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsolidationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '1100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        foreach ([1, 2] as $co) {
            $this->ensureCompanyExists($co);

            if (! FinFiscalPeriod::forDate(now(), $co)) {
                FinFiscalPeriod::create(['company_id' => $co, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                    'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
            }
            // Multi-company posting needs a per-company chart of accounts.
            foreach ([['1100', 'Bank', 'asset'], ['3100', 'Equity', 'equity'], ['1270', 'Intercompany', 'asset'], ['4100', 'Sales', 'income']] as [$code, $name, $type]) {
                FinAccount::firstOrCreate(['code' => $code, 'company_id' => $co], ['name' => $name, 'type' => $type, 'is_postable' => true, 'is_active' => true]);
            }
        }
    }

    /**
     * Consolidating two companies needs two companies.
     *
     * The ids were assumed rather than created, which held for as long as nothing downstream
     * looked them up. A finance row now resolves the trading business its company defaults to, and
     * `fin_entities.company_id` is a real foreign key — so a journal posted for a company that
     * does not exist cannot be given a business, and says so.
     */
    private function ensureCompanyExists(int $id): void
    {
        if (DB::table('companies')->where('id', $id)->exists()) {
            return;
        }

        DB::table('companies')->insert([
            'id'                => $id,
            'uuid'              => (string) Str::uuid(),
            'name'              => "Consolidation Fixture {$id}",
            'slug'              => "consolidation-fixture-{$id}",
            'status'            => 'active',
            'monthly_total'     => 0,
            'founding_customer' => false,
            'white_label'       => false,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function test_consolidates_two_companies_and_eliminates_intercompany(): void
    {
        // Company 1: bank 10 000 vs equity. Company 2: bank 5 000 vs equity.
        app(Ledger::class)->post(new FinancialEvent('t', 't.c1', [['account' => '1100', 'amount' => 10000]], [['account' => '3100', 'amount' => 10000]], now(), companyId: 1, idempotencyKey: 'con_c1'));
        app(Ledger::class)->post(new FinancialEvent('t', 't.c2', [['account' => '1100', 'amount' => 5000]], [['account' => '3100', 'amount' => 5000]], now(), companyId: 2, idempotencyKey: 'con_c2'));
        // An intercompany balance on 1270 in company 1 that should be eliminated.
        app(Ledger::class)->post(new FinancialEvent('t', 't.ic', [['account' => '1270', 'amount' => 2000]], [['account' => '4100', 'amount' => 2000]], now(), companyId: 1, idempotencyKey: 'con_ic'));

        $group = FinGroup::create(['name' => 'Test Group', 'base_currency' => 'ZAR', 'intercompany_codes' => ['1270']]);
        $group->members()->create(['company_id' => 1, 'name' => 'Parent', 'ownership_pct' => 100, 'fx_rate' => 1, 'is_parent' => true]);
        $group->members()->create(['company_id' => 2, 'name' => 'Sub', 'ownership_pct' => 100, 'fx_rate' => 1]);

        $r = app(ConsolidationService::class)->consolidate($group->fresh('members'));

        $bank = collect($r['rows'])->firstWhere('code', '1100');
        $this->assertEquals(15000.0, (float) $bank['balance']);              // 10k + 5k summed
        $this->assertNotEmpty($r['eliminations']);                           // 1270 eliminated
        $this->assertEquals('1270', $r['eliminations'][0]['code']);
        $this->assertNull(collect($r['rows'])->firstWhere('code', '1270'));  // gone from the consolidated set
    }

    public function test_fx_translation_applies_the_member_rate(): void
    {
        app(Ledger::class)->post(new FinancialEvent('t', 't.fx', [['account' => '1100', 'amount' => 1000]], [['account' => '3100', 'amount' => 1000]], now(), companyId: 2, idempotencyKey: 'con_fx'));

        $group = FinGroup::create(['name' => 'FX Group', 'base_currency' => 'ZAR']);
        $group->members()->create(['company_id' => 2, 'name' => 'USD Sub', 'ownership_pct' => 100, 'fx_rate' => 18]); // 1000 * 18

        $r = app(ConsolidationService::class)->consolidate($group->fresh('members'));
        $this->assertEquals(18000.0, (float) collect($r['rows'])->firstWhere('code', '1100')['balance']);
    }
}
