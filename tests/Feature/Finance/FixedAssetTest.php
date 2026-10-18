<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinAsset;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Services\FixedAssetService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '1510')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        // Open a few months of periods so back-to-back depreciation posts.
        foreach (range(-2, 2) as $m) {
            $d = now()->addMonths($m);
            if (! FinFiscalPeriod::forDate($d, null)) {
                FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) $d->year, 'period_no' => (int) $d->month,
                    'start_date' => $d->copy()->startOfMonth(), 'end_date' => $d->copy()->endOfMonth(), 'status' => 'open']);
            }
        }
    }

    private function asset(array $attrs = []): FinAsset
    {
        return app(FixedAssetService::class)->create(array_merge([
            'name' => 'Laptop', 'acquisition_date' => now()->startOfMonth()->toDateString(),
            'cost' => 12000, 'residual_value' => 0, 'useful_life_months' => 12, 'method' => 'straight_line',
        ], $attrs));
    }

    public function test_straight_line_posts_expense_and_accumulates(): void
    {
        $asset = $this->asset(); // 12000 / 12 = 1000 per month

        $entry = app(FixedAssetService::class)->runFor($asset, now());
        $this->assertEquals(1000.0, (float) $entry->amount);

        $exp = FinAccount::where('code', '6500')->value('id');
        $accum = FinAccount::where('code', '1520')->value('id');
        $this->assertEquals(1000.0, (float) FinJournalLine::where('journal_id', $entry->journal_id)->where('account_id', $exp)->value('debit'));
        $this->assertEquals(1000.0, (float) FinJournalLine::where('journal_id', $entry->journal_id)->where('account_id', $accum)->value('credit'));
        $this->assertEquals(1000.0, (float) $asset->fresh()->accumulated_depreciation);
        $this->assertEquals(11000.0, $asset->fresh()->bookValue());
    }

    public function test_reducing_balance_charges_on_book_value(): void
    {
        $asset = $this->asset(['method' => 'reducing_balance', 'annual_rate' => 20, 'cost' => 10000, 'useful_life_months' => 60]);
        // 10000 * 20% / 12 = 166.67
        $entry = app(FixedAssetService::class)->runFor($asset, now());
        $this->assertEquals(166.67, (float) $entry->amount);
    }

    public function test_running_twice_for_a_month_is_idempotent(): void
    {
        $asset = $this->asset();
        app(FixedAssetService::class)->runFor($asset, now());
        $second = app(FixedAssetService::class)->runFor($asset->fresh(), now());
        $this->assertNull($second);
        $this->assertEquals(1000.0, (float) $asset->fresh()->accumulated_depreciation); // not doubled
    }

    public function test_disposal_with_gain_posts_gain_and_clears_asset(): void
    {
        $asset = $this->asset(['cost' => 12000]);
        $asset->update(['accumulated_depreciation' => 4000]); // NBV 8000

        app(FixedAssetService::class)->dispose($asset->fresh(), Carbon::parse(now()), 10000); // gain 2000

        $j = $asset->fresh()->disposal_journal_id;
        $disposal = FinAccount::where('code', '4900')->value('id');
        $assetAcc = FinAccount::where('code', '1510')->value('id');
        $this->assertEquals('disposed', $asset->fresh()->status);
        $this->assertEquals(2000.0, (float) FinJournalLine::where('journal_id', $j)->where('account_id', $disposal)->value('credit'));
        $this->assertEquals(12000.0, (float) FinJournalLine::where('journal_id', $j)->where('account_id', $assetAcc)->value('credit'));
    }

    public function test_disposal_with_loss_posts_loss(): void
    {
        $asset = $this->asset(['cost' => 12000]);
        $asset->update(['accumulated_depreciation' => 4000]); // NBV 8000

        app(FixedAssetService::class)->dispose($asset->fresh(), Carbon::parse(now()), 5000); // loss 3000

        $disposal = FinAccount::where('code', '4900')->value('id');
        $this->assertEquals(3000.0, (float) FinJournalLine::where('journal_id', $asset->fresh()->disposal_journal_id)->where('account_id', $disposal)->value('debit'));
    }
}
