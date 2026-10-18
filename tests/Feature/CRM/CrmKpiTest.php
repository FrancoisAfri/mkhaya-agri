<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmKpi;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmStage;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Services\CrmKpiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmKpiTest extends TestCase
{
    use DatabaseTransactions;

    private function kpi(array $attr): CrmKpi
    {
        return CrmKpi::make(array_merge([
            'uuid' => Str::uuid(), 'name' => 'K', 'entity' => 'deal', 'metric' => 'count',
            'format' => 'number', 'color' => 'primary',
        ], $attr));
    }

    private function seedDeals(): void
    {
        $p = CrmPipeline::create(['uuid' => Str::uuid(), 'name' => 'KPI ' . Str::random(4), 'is_active' => true]);
        $s = CrmStage::create(['pipeline_id' => $p->id, 'name' => 'New', 'sort_order' => 1, 'probability' => 10]);
        // 2 open (10k, 20k), 1 won (50k)
        foreach ([[10000, 'open'], [20000, 'open'], [50000, 'won']] as [$amt, $status]) {
            CrmDeal::create(['uuid' => Str::uuid(), 'title' => 'D', 'pipeline_id' => $p->id, 'stage_id' => $s->id, 'amount' => $amt, 'status' => $status, 'stage_changed_at' => now()]);
        }
    }

    public function test_count_metric(): void
    {
        $this->seedDeals();
        $svc = app(CrmKpiService::class);
        $this->assertSame(3, $svc->compute($this->kpi(['metric' => 'count'])));
    }

    public function test_count_with_filter(): void
    {
        $this->seedDeals();
        $kpi = $this->kpi(['metric' => 'count', 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'open']]]);
        $this->assertSame(2, app(CrmKpiService::class)->compute($kpi));
    }

    public function test_sum_metric(): void
    {
        $this->seedDeals();
        $kpi = $this->kpi(['metric' => 'sum', 'field' => 'amount']);
        $this->assertEqualsWithDelta(80000.0, app(CrmKpiService::class)->compute($kpi), 0.01);
    }

    public function test_sum_with_filter(): void
    {
        $this->seedDeals();
        $kpi = $this->kpi(['metric' => 'sum', 'field' => 'amount', 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'won']]]);
        $this->assertEqualsWithDelta(50000.0, app(CrmKpiService::class)->compute($kpi), 0.01);
    }

    public function test_non_whitelisted_field_returns_zero(): void
    {
        $this->seedDeals();
        // 'notes' is not a numeric whitelisted column for aggregation.
        $kpi = $this->kpi(['metric' => 'sum', 'field' => 'notes']);
        $this->assertSame(0, app(CrmKpiService::class)->compute($kpi));
    }

    public function test_non_whitelisted_filter_field_is_ignored(): void
    {
        $this->seedDeals();
        // 'secret_field' is not whitelisted -> filter ignored -> counts all 3.
        $kpi = $this->kpi(['metric' => 'count', 'filters' => [['field' => 'secret_field', 'operator' => '=', 'value' => 'x']]]);
        $this->assertSame(3, app(CrmKpiService::class)->compute($kpi));
    }

    public function test_lead_count(): void
    {
        CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'A', 'status' => 'new']);
        CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'B', 'status' => 'qualified']);
        $kpi = $this->kpi(['entity' => 'lead', 'metric' => 'count', 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'qualified']]]);
        $this->assertSame(1, app(CrmKpiService::class)->compute($kpi));
    }
}
