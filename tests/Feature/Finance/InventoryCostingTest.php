<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\InventoryService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InventoryCostingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '1300')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    private function item(string $method): FinStockItem
    {
        return app(InventoryService::class)->createItem(['code' => 'SKU' . uniqid(), 'name' => 'Widget', 'cost_method' => $method]);
    }

    public function test_fifo_issues_oldest_layers_first(): void
    {
        $svc = app(InventoryService::class);
        $item = $this->item('fifo');
        $svc->receive($item->fresh(), 10, 100);  // layer 1 @100
        $svc->receive($item->fresh(), 10, 120);  // layer 2 @120

        // Issue 15 → 10@100 + 5@120 = 1600.
        $mv = $svc->issue($item->fresh(), 15);
        $this->assertEquals(1600.0, (float) $mv->value);

        $cogs = FinAccount::where('code', '5000')->value('id');
        $inv = FinAccount::where('code', '1300')->value('id');
        $this->assertEquals(1600.0, (float) FinJournalLine::where('journal_id', $mv->journal_id)->where('account_id', $cogs)->value('debit'));
        $this->assertEquals(1600.0, (float) FinJournalLine::where('journal_id', $mv->journal_id)->where('account_id', $inv)->value('credit'));
        $this->assertEquals(5.0, (float) $item->fresh()->qty_on_hand);
    }

    public function test_weighted_average_costs_the_blended_rate(): void
    {
        $svc = app(InventoryService::class);
        $item = $this->item('weighted_avg');
        $svc->receive($item->fresh(), 10, 100);  // avg 100
        $svc->receive($item->fresh(), 10, 120);  // avg (1000+1200)/20 = 110

        $this->assertEquals(110.0, (float) $item->fresh()->avg_cost);
        $mv = $svc->issue($item->fresh(), 5);    // 5 * 110 = 550
        $this->assertEquals(550.0, (float) $mv->value);
    }

    public function test_cannot_issue_more_than_on_hand(): void
    {
        $svc = app(InventoryService::class);
        $item = $this->item('weighted_avg');
        $svc->receive($item->fresh(), 3, 100);
        $this->expectException(\App\Modules\Finance\Accounting\Exceptions\PostingException::class);
        $svc->issue($item->fresh(), 5);
    }
}
