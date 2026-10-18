<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Services\InternalEmitter;
use App\Modules\Operations\ProjectManagement\Services\ModuleRegistry;
use App\Modules\Operations\ProjectManagement\Services\ProjectService;
use App\Modules\Operations\ProjectManagement\Support\Evidence\Adapters\ProcurementAdapter;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;

/**
 * Evidence emitted by PM itself (PM-1004) and by module adapters (PM-1005).
 *
 * The ledger is only worth something if it is complete: a state change that reaches the database
 * without reaching the ledger leaves every derived figure computed from a partial record.
 */
class EvidenceEmissionTest extends ProjectManagementTestCase
{
    // ── Internal emission ────────────────────────────────────────────────────

    public function test_a_status_change_produces_exactly_one_event(): void
    {
        $project = $this->makeProject(['status' => 'draft']);

        app(ProjectService::class)->update($project, ['status' => 'active']);

        $events = EvidenceEvent::where('project_id', $project->id)->where('event_type', 'status.changed')->get();

        $this->assertCount(1, $events, 'One change, one event — not zero, and not two.');
        $this->assertSame('draft', $events[0]->payload['from']);
        $this->assertSame('active', $events[0]->payload['to']);
        $this->assertSame('pm', $events[0]->source_module);
    }

    public function test_the_same_change_saved_twice_records_the_fact_once(): void
    {
        $project = $this->makeProject(['status' => 'draft']);
        $service = app(ProjectService::class);

        $service->update($project, ['status' => 'active']);
        $service->update($project->fresh(), ['status' => 'draft']);
        $service->update($project->fresh(), ['status' => 'active']);

        // Back to 'active' is the same fact as the first time by natural key, so it is not
        // double-counted. The intermediate change to draft is its own fact.
        $this->assertSame(2, EvidenceEvent::where('project_id', $project->id)->where('event_type', 'status.changed')->count());
    }

    public function test_evidence_is_written_in_the_same_transaction_as_the_change(): void
    {
        $project = $this->makeProject(['status' => 'draft']);

        // If the state change rolls back, its evidence must roll back with it — otherwise the
        // ledger claims something that never happened.
        try {
            DB::transaction(function () use ($project) {
                app(ProjectService::class)->update($project, ['status' => 'active']);

                throw new \RuntimeException('something later in the request failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('draft', $project->fresh()->status);
        $this->assertSame(0, EvidenceEvent::where('event_type', 'status.changed')->count());
    }

    public function test_emitting_outside_a_transaction_is_refused(): void
    {
        $project = $this->makeProject();

        // RefreshDatabase wraps every test in a transaction, so the guard would never fire here on
        // its own. The transaction level is faked to assert the check itself — the behaviour only
        // observable in production, where an emission outside a transaction could survive a
        // rollback of the change it claims to record.
        DB::shouldReceive('transactionLevel')->andReturn(0);

        try {
            app(InternalEmitter::class)->emit($project, 'status.changed');
            $this->fail('Emitting outside a transaction should throw.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('same transaction', $e->getMessage());
        } finally {
            // Restore the real connection before teardown rolls the test back.
            DB::clearResolvedInstances();
        }
    }

    // ── Module adapters ──────────────────────────────────────────────────────

    public function test_an_adapter_is_inert_when_its_module_is_not_licensed(): void
    {
        $this->withCapabilities([]);
        $project = $this->makeProject();

        $result = app(ProcurementAdapter::class)->handle('procurement.grn.received', [
            'project_uuid' => $project->uuid, 'reference' => 'GRN-1', 'amount' => '100.00', 'currency' => 'ZAR',
        ]);

        // Silence, not an error: most tenants do not have Procurement, and nothing is wrong.
        $this->assertNull($result);
        $this->assertSame(0, EvidenceEvent::count());
    }

    public function test_an_adapter_translates_its_modules_event_into_evidence(): void
    {
        $this->withCapabilities(['procurement.orders']);
        $project = $this->makeProject();

        $result = app(ProcurementAdapter::class)->handle('procurement.grn.received', [
            'project_uuid' => $project->uuid, 'reference' => 'GRN-4471',
            'amount' => '18400.00', 'currency' => 'ZAR', 'supplier' => 'Afri Steel',
        ]);

        $this->assertNotNull($result);
        $event = EvidenceEvent::firstOrFail();

        $this->assertSame('grn.received', $event->event_type);
        $this->assertSame('procurement', $event->source_module);
        $this->assertSame('18400.00', $event->amount);
        $this->assertSame('Afri Steel', $event->payload['supplier']);
    }

    public function test_replaying_a_modules_event_stream_does_not_double_count(): void
    {
        $this->withCapabilities(['procurement.orders']);
        $project = $this->makeProject();

        $payload = ['project_uuid' => $project->uuid, 'reference' => 'GRN-4471', 'amount' => '100.00', 'currency' => 'ZAR'];

        app(ProcurementAdapter::class)->handle('procurement.grn.received', $payload);
        app(ProcurementAdapter::class)->handle('procurement.grn.received', $payload);

        // Adapters re-read event streams by design; the natural key makes that safe.
        $this->assertSame(1, EvidenceEvent::count());
    }

    public function test_an_event_with_no_project_is_skipped_rather_than_guessed_at(): void
    {
        $this->withCapabilities(['procurement.orders']);

        $result = app(ProcurementAdapter::class)->handle('procurement.grn.received', [
            'reference' => 'GRN-NO-PROJECT', 'amount' => '100.00', 'currency' => 'ZAR',
        ]);

        $this->assertNull($result);
        $this->assertSame(0, EvidenceEvent::count());
    }

    public function test_an_unmapped_event_is_ignored(): void
    {
        $this->withCapabilities(['procurement.orders']);
        $project = $this->makeProject();

        // Plenty of a module's events are internal bookkeeping and are not evidence of anything.
        $result = app(ProcurementAdapter::class)->handle('procurement.supplier.email_bounced', [
            'project_uuid' => $project->uuid, 'reference' => 'X',
        ]);

        $this->assertNull($result);
        $this->assertSame(0, EvidenceEvent::count());
    }

    public function test_the_mapping_table_is_declared_for_every_event_it_accepts(): void
    {
        $adapter = app(ProcurementAdapter::class);

        foreach ($adapter->mapping() as $external => $evidenceType) {
            $this->assertStringStartsWith('procurement.', $external);
            $this->assertTrue(
                \App\Modules\Operations\ProjectManagement\Support\Evidence\EvidenceTaxonomy::knows($evidenceType),
                "{$external} maps to {$evidenceType}, which is not in the taxonomy."
            );
        }
    }

    private function withCapabilities(array $capabilities): void
    {
        $this->mock(ModuleRegistry::class, function ($mock) use ($capabilities) {
            $mock->shouldReceive('has')->andReturnUsing(fn (string $c) => in_array($c, $capabilities, true));
            $mock->shouldReceive('forResponse')->andReturn([]);
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
