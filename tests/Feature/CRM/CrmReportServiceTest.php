<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmStage;
use App\Modules\CRM\Services\CrmReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmReportServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CrmPipeline $pipeline;
    private CrmStage $new;
    private CrmStage $won;

    private function seedDeals(): void
    {
        $this->pipeline = CrmPipeline::create(['uuid' => Str::uuid(), 'name' => 'RPT ' . Str::random(5), 'is_active' => true]);
        $this->new = CrmStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'New', 'sort_order' => 1, 'probability' => 20]);
        $this->won = CrmStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Won', 'sort_order' => 2, 'probability' => 100, 'is_won' => true]);

        // 2 open deals (R10k @20%, R20k@20%), 1 won (R50k, source=Referral), 1 lost.
        CrmDeal::create(['uuid' => Str::uuid(), 'title' => 'O1', 'pipeline_id' => $this->pipeline->id, 'stage_id' => $this->new->id, 'amount' => 10000, 'probability' => 20, 'status' => 'open', 'expected_close_date' => '2026-07-15']);
        CrmDeal::create(['uuid' => Str::uuid(), 'title' => 'O2', 'pipeline_id' => $this->pipeline->id, 'stage_id' => $this->new->id, 'amount' => 20000, 'probability' => 20, 'status' => 'open', 'expected_close_date' => '2026-07-20']);
        CrmDeal::create(['uuid' => Str::uuid(), 'title' => 'W1', 'pipeline_id' => $this->pipeline->id, 'stage_id' => $this->won->id, 'amount' => 50000, 'probability' => 100, 'status' => 'won', 'closed_at' => now(), 'source' => 'Referral']);
        CrmDeal::create(['uuid' => Str::uuid(), 'title' => 'L1', 'pipeline_id' => $this->pipeline->id, 'stage_id' => $this->new->id, 'amount' => 5000, 'status' => 'lost', 'closed_at' => now(), 'lost_reason' => 'Price']);
    }

    public function test_summary_kpis(): void
    {
        $this->seedDeals();
        $s = (new CrmReportService())->summary();

        $this->assertSame(2, $s['open_count']);
        $this->assertEqualsWithDelta(30000.0, $s['open_value'], 0.01);
        // weighted = 10000*.2 + 20000*.2 = 6000
        $this->assertEqualsWithDelta(6000.0, $s['weighted_pipeline'], 0.01);
        $this->assertSame(1, $s['won_count']);
        // win rate = 1 won / (1 won + 1 lost) = 50%
        $this->assertEqualsWithDelta(50.0, $s['win_rate'], 0.1);
    }

    public function test_forecast_groups_by_month_and_weights(): void
    {
        $this->seedDeals();
        $f = (new CrmReportService())->forecast();

        $july = collect($f)->firstWhere('month', '2026-07');
        $this->assertNotNull($july);
        $this->assertEqualsWithDelta(30000.0, $july['gross'], 0.01);
        $this->assertEqualsWithDelta(6000.0, $july['weighted'], 0.01);
        $this->assertSame(2, $july['count']);
    }

    public function test_attribution_by_source(): void
    {
        $this->seedDeals();
        $a = (new CrmReportService())->attribution();

        $referral = collect($a)->firstWhere('source', 'Referral');
        $this->assertNotNull($referral);
        $this->assertEqualsWithDelta(50000.0, $referral['value'], 0.01);
    }

    public function test_win_loss_and_dataset_export(): void
    {
        $this->seedDeals();
        $svc = new CrmReportService();

        $wl = $svc->winLoss();
        $this->assertSame(1, $wl['won']);
        $this->assertSame(1, $wl['lost']);
        $this->assertSame('Price', $wl['reasons'][0]['reason']);

        [$headers, $rows] = $svc->dataset('attribution');
        $this->assertSame(['source', 'value', 'count'], $headers);
        $this->assertNotEmpty($rows);
    }
}
