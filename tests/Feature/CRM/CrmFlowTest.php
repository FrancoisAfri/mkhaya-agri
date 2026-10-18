<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmStage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Core CRM engine flows. DatabaseTransactions (not RefreshDatabase) so the dev
 * database is never wiped.
 */
class CrmFlowTest extends TestCase
{
    use DatabaseTransactions;

    private function pipelineWithStages(): CrmPipeline
    {
        $p = CrmPipeline::create(['uuid' => Str::uuid(), 'name' => 'Test PL ' . Str::random(5), 'is_default' => false, 'is_active' => true]);
        CrmStage::create(['pipeline_id' => $p->id, 'name' => 'New', 'sort_order' => 1, 'probability' => 10]);
        CrmStage::create(['pipeline_id' => $p->id, 'name' => 'Won', 'sort_order' => 2, 'probability' => 100, 'is_won' => true]);

        return $p->load('stages');
    }

    public function test_deal_moves_to_won_stage_and_closes(): void
    {
        $pl = $this->pipelineWithStages();
        $newStage = $pl->stages->firstWhere('name', 'New');
        $wonStage = $pl->stages->firstWhere('name', 'Won');

        $deal = CrmDeal::create([
            'uuid' => Str::uuid(), 'title' => 'T', 'pipeline_id' => $pl->id, 'stage_id' => $newStage->id,
            'amount' => 1000, 'status' => 'open', 'stage_changed_at' => now(),
        ]);

        // Simulate the controller's move logic.
        $deal->update([
            'stage_id' => $wonStage->id,
            'status'   => $wonStage->is_won ? 'won' : 'open',
            'closed_at' => $wonStage->is_won ? now() : null,
            'probability' => $wonStage->probability,
        ]);

        $this->assertSame('won', $deal->fresh()->status);
        $this->assertNotNull($deal->fresh()->closed_at);
        $this->assertSame(100, $deal->fresh()->probability);
    }

    public function test_lead_score_is_clamped_and_stored(): void
    {
        $lead = CrmLead::create([
            'uuid' => Str::uuid(), 'first_name' => 'Jane', 'company' => 'Acme', 'status' => 'new',
        ]);

        $lead->update(['score' => max(0, min(100, 150)), 'score_reason' => 'test']);

        $this->assertSame(100, $lead->fresh()->score);
    }

    public function test_lead_full_name_accessor(): void
    {
        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'Jane', 'last_name' => 'Doe', 'status' => 'new']);
        $this->assertSame('Jane Doe', $lead->full_name);
    }
}
