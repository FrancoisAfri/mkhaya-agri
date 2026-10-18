<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmStage;
use App\Modules\CRM\Models\CrmWorkflow;
use App\Modules\CRM\Models\CrmWorkflowRun;
use App\Modules\CRM\Services\CrmWorkflowEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmWorkflowEngineTest extends TestCase
{
    use DatabaseTransactions;

    private function engine(): CrmWorkflowEngine
    {
        return app(CrmWorkflowEngine::class);
    }

    public function test_matching_workflow_creates_a_task_and_logs_a_run(): void
    {
        $wf = CrmWorkflow::create([
            'uuid' => Str::uuid(), 'name' => 'High-value lead → task', 'trigger' => 'lead.created',
            'match_type' => 'all',
            'conditions' => [['field' => 'source', 'operator' => 'contains', 'value' => 'demo']],
            'actions'    => [['type' => 'create_task', 'subject' => 'Call new demo lead', 'due_in_days' => 1, 'assign_to' => 'owner']],
            'is_active'  => true,
        ]);

        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'Pat', 'source' => 'demo request', 'status' => 'new']);

        $this->engine()->dispatch('lead.created', $lead);

        $this->assertDatabaseHas('crm_activities', [
            'related_type' => 'lead', 'related_id' => $lead->id, 'type' => 'task', 'subject' => 'Call new demo lead',
        ]);
        $this->assertDatabaseHas('crm_workflow_runs', ['workflow_id' => $wf->id, 'status' => 'success']);
        $this->assertSame(1, $wf->fresh()->run_count);
    }

    public function test_conditions_not_met_is_skipped(): void
    {
        $wf = CrmWorkflow::create([
            'uuid' => Str::uuid(), 'name' => 'Only big deals', 'trigger' => 'deal.created',
            'match_type' => 'all',
            'conditions' => [['field' => 'amount', 'operator' => '>', 'value' => '100000']],
            'actions'    => [['type' => 'add_note', 'body' => 'Big deal!']],
            'is_active'  => true,
        ]);

        $pl = CrmPipeline::create(['uuid' => Str::uuid(), 'name' => 'PL ' . Str::random(4), 'is_active' => true]);
        $stage = CrmStage::create(['pipeline_id' => $pl->id, 'name' => 'New', 'sort_order' => 1, 'probability' => 10]);

        $deal = CrmDeal::create([
            'uuid' => Str::uuid(), 'title' => 'Small deal', 'pipeline_id' => $pl->id, 'stage_id' => $stage->id,
            'amount' => 500, 'status' => 'open', 'stage_changed_at' => now(),
        ]);

        $this->engine()->dispatch('deal.created', $deal);

        $this->assertDatabaseHas('crm_workflow_runs', ['workflow_id' => $wf->id, 'status' => 'skipped']);
        $this->assertDatabaseMissing('crm_activities', ['related_type' => 'deal', 'related_id' => $deal->id]);
    }

    public function test_stage_trigger_config_scopes_to_destination_stage(): void
    {
        $pl = CrmPipeline::create(['uuid' => Str::uuid(), 'name' => 'PL ' . Str::random(4), 'is_active' => true]);
        $won = CrmStage::create(['pipeline_id' => $pl->id, 'name' => 'Won', 'sort_order' => 2, 'probability' => 100, 'is_won' => true]);
        $new = CrmStage::create(['pipeline_id' => $pl->id, 'name' => 'New', 'sort_order' => 1, 'probability' => 10]);

        $wf = CrmWorkflow::create([
            'uuid' => Str::uuid(), 'name' => 'On won → note', 'trigger' => 'deal.stage_changed',
            'trigger_config' => ['stage_id' => $won->id], 'match_type' => 'all',
            'actions' => [['type' => 'add_note', 'body' => 'Deal won — handoff to delivery']], 'is_active' => true,
        ]);

        // Deal currently in "New" — stage trigger config requires "Won", so skip.
        $deal = CrmDeal::create([
            'uuid' => Str::uuid(), 'title' => 'D', 'pipeline_id' => $pl->id, 'stage_id' => $new->id,
            'amount' => 1000, 'status' => 'open', 'stage_changed_at' => now(),
        ]);
        $this->engine()->dispatch('deal.stage_changed', $deal);
        $this->assertSame(0, CrmWorkflowRun::where('workflow_id', $wf->id)->count());

        // Move to "Won" — now it fires.
        $deal->update(['stage_id' => $won->id]);
        $this->engine()->dispatch('deal.stage_changed', $deal);
        $this->assertDatabaseHas('crm_activities', ['related_type' => 'deal', 'related_id' => $deal->id, 'type' => 'note']);
    }

    public function test_inactive_workflow_does_not_run(): void
    {
        CrmWorkflow::create([
            'uuid' => Str::uuid(), 'name' => 'Paused', 'trigger' => 'lead.created',
            'actions' => [['type' => 'add_note', 'body' => 'x']], 'is_active' => false,
        ]);

        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'Sam', 'status' => 'new']);
        $ran = $this->engine()->dispatch('lead.created', $lead);

        $this->assertEmpty($ran);
    }
}
