<?php

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\WorkflowAuditEvent;
use App\Modules\Workflow\Models\WorkflowInstance;
use Laravel\Sanctum\Sanctum;

/** PRS §21: the versioned API, with the same permissions, controls and audit as the screens. */
class WorkflowApiTest extends WorkflowTestCase
{
    public function test_start_decide_and_follow_a_request_through_the_api(): void
    {
        $d = $this->published($this->simpleApprovalGraph(), ['name' => 'API flow', 'key' => 'api_flow']);

        Sanctum::actingAs($this->requester);
        $start = $this->postJson('/api/v1/workflow/instances', ['definition' => $d->key, 'values' => []], ['Idempotency-Key' => 'k-1'])->assertCreated();
        $uuid = $start->json('data.uuid');
        $this->postJson('/api/v1/workflow/instances', ['definition' => $d->key], ['Idempotency-Key' => 'k-1'])->assertOk()->assertJsonPath('data.uuid', $uuid);
        $this->assertSame(1, WorkflowInstance::where('definition_id', $d->id)->count(), 'The same Idempotency-Key starts nothing new.');
        $this->getJson("/api/v1/workflow/instances/{$uuid}")->assertOk()->assertJsonPath('data.status', 'running')->assertJsonCount(1, 'data.open_tasks');

        Sanctum::actingAs($this->manager);
        $task = $this->getJson('/api/v1/workflow/tasks')->assertOk()->json('data.0.uuid');
        $this->postJson("/api/v1/workflow/tasks/{$task}/decision", ['decision' => 'rejected'])->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('title', 'Refused by a workflow control');
        $this->postJson("/api/v1/workflow/tasks/{$task}/decision", ['decision' => 'approved', 'comment' => 'via API'])->assertOk()->assertJsonPath('data.decision', 'approved');

        Sanctum::actingAs($this->requester);
        $this->getJson("/api/v1/workflow/instances/{$uuid}")->assertJsonPath('data.status', 'completed');
        $this->getJson("/api/v1/workflow/instances/{$uuid}/audit")->assertOk()->assertJsonFragment(['action' => 'task.decided']);
    }

    public function test_the_api_refuses_what_the_screens_refuse(): void
    {
        $d = $this->published($this->simpleApprovalGraph(), ['name' => 'API flow 2', 'key' => 'api_flow_2']);
        Sanctum::actingAs($this->outsider);
        $this->postJson('/api/v1/workflow/instances', ['definition' => $d->key])->assertForbidden();
        $this->getJson('/api/v1/workflow/tasks')->assertForbidden();

        $i = $this->startAs($this->requester, $d);
        Sanctum::actingAs($this->finance);
        $this->getJson("/api/v1/workflow/instances/{$i->uuid}")->assertForbidden();
        $this->postJson("/api/v1/workflow/tasks/{$this->openTask($i)->uuid}/decision", ['decision' => 'approved'])->assertStatus(422);
        $this->postJson("/api/v1/workflow/instances/{$i->uuid}/cancel", ['reason' => 'not mine to cancel'])->assertForbidden();
        $this->postJson('/api/v1/workflow/action-runs/1/retry')->assertForbidden();
        $this->assertGreaterThan(0, WorkflowAuditEvent::where('action', 'like', 'api.%')->where('outcome', 'denied')->count());
        $this->assertContains($this->getJson('/api/v1/workflow/instances/not-a-uuid')->status(), [403, 404], 'A malformed id is never a server error.');
    }

    public function test_a_module_event_through_the_api_starts_the_record_s_workflow_once(): void
    {
        $this->settings(['publication_requires_review' => false]);
        $this->actingAs($this->admin);
        $d = app(\App\Modules\Workflow\Services\DefinitionService::class)->installTemplate('expense_claim');
        app(\App\Modules\Workflow\Services\DefinitionService::class)->submit($d, $this->admin, 'go');
        $category = \App\Modules\Finance\Claims\Models\ClaimCategory::create(['name' => 'API', 'code' => 'API-' . uniqid(), 'requires_receipt' => false, 'max_age_days' => 30, 'active' => true, 'is_travel' => false]);
        $claim = \App\Modules\Finance\Claims\Models\Claim::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'reference_number' => 'CLM-API-1', 'employee_id' => $this->requester->id,
            'category_id' => $category->id, 'title' => 'API claim', 'currency' => 'ZAR', 'total_amount' => 900, 'claim_date' => now()->toDateString(), 'status' => 'submitted', 'created_by' => $this->requester->id]);

        Sanctum::actingAs($this->requester);
        $this->postJson('/api/v1/workflow/events', ['provider' => 'finance.claims', 'record_id' => $claim->uuid, 'event_id' => 'evt-9'])->assertStatus(202)->assertJsonPath('data.0.subject_ref', 'CLM-API-1');
        $this->postJson('/api/v1/workflow/events', ['provider' => 'finance.claims', 'record_id' => $claim->uuid, 'event_id' => 'evt-9'])->assertStatus(202);
        $this->assertSame(1, WorkflowInstance::where('subject_id', $claim->uuid)->count(), 'Duplicate delivery of the same event starts nothing new.');
        $this->postJson('/api/v1/workflow/events', ['provider' => 'finance.claims', 'record_id' => 'nope', 'event_id' => 'x'])->assertNotFound();
    }
}
