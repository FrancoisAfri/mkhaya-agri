<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinContract;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\AgeingService;
use App\Modules\Finance\Accounting\Services\ContractService;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        /*
         * Every account this test posts to, not just one of them.
         *
         * The guard used to check 1250 alone, so a chart holding 1250 but not 1200 skipped the
         * seeder — and the progress claim then failed on "Account [1200] not found" from inside
         * the ledger, which reads as a posting bug rather than an unseeded book.
         */
        $required = ['1200', '1250', '4100'];

        if (FinAccount::whereIn('code', $required)->count() < count($required)) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    private function contract(): FinContract
    {
        $party = FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'K' . uniqid(), 'name' => 'BuildCo']);

        return app(ContractService::class)->save([
            'party_id' => $party->id, 'income_account_id' => FinAccount::where('code', '4100')->value('id'),
            'name' => 'Site A', 'contract_value' => 100000, 'retention_percent' => 10,
        ]);
    }

    private function gl(string $code): float
    {
        $row = app(FinanceReportService::class)->accountBalances(null, now()->toDateString())->firstWhere('code', $code);
        return round((float) ($row['balance'] ?? 0), 2);
    }

    public function test_progress_claim_holds_retention_and_raises_net_receivable(): void
    {
        $c = $this->contract();
        $arBefore = $this->gl('1200');

        // Claim 30 000 net + 15% VAT, 10% retention.
        $inv = app(ContractService::class)->progressClaim($c, 30000, 15, 'Stage 1');

        // AR now = 30000 - 3000 + 4500 = 31500; retention receivable = 3000.
        $this->assertEquals(31500.0, (float) $inv->total);
        $this->assertEquals($arBefore + 31500, $this->gl('1200'));
        $this->assertEquals(3000.0, $this->gl('1250'));           // retention held
        $this->assertEquals(30000.0, $c->fresh()->billed_net);
        $this->assertEquals(3000.0, (float) $c->fresh()->retention_held);

        // AR ageing still ties (retention sits in its own account, not AR).
        $this->assertTrue(app(AgeingService::class)->ageing('receivable')['ties']);
    }

    public function test_release_retention_moves_it_to_ar(): void
    {
        $c = $this->contract();
        $svc = app(ContractService::class);
        $svc->progressClaim($c, 30000, 15, 'Stage 1');
        $retBalance = $this->gl('1250');
        $this->assertEquals(3000.0, $retBalance);

        $rel = $svc->releaseRetention($c->fresh());

        $this->assertEquals(3000.0, (float) $rel->total);
        $this->assertEquals(0.0, $this->gl('1250'));               // retention cleared
        $this->assertEquals(0.0, (float) $c->fresh()->retention_held);
        $this->assertTrue(app(AgeingService::class)->ageing('receivable')['ties']);
    }
}
