<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ClientProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\EmployeeProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ExpenseSink;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\PurchaseProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\TimeSink;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Providers\Integrated;
use App\Modules\Operations\ProjectManagement\Providers\Local;
use App\Modules\Operations\ProjectManagement\Services\ExternalRefResolver;
use App\Modules\Operations\ProjectManagement\Services\ModuleRegistry;
use App\Modules\Operations\ProjectManagement\Services\ProviderRegistry;
use Illuminate\Support\Facades\DB;
use Mockery;

/**
 * The two suites PM-1041 requires: the whole feature set with every module disabled, and again
 * with them enabled.
 *
 * The point is not that the providers work in isolation — it is that *feature code never changes*
 * between the two. Everything here asks for an interface and gets a working answer either way.
 */
class ProviderResolutionTest extends ProjectManagementTestCase
{
    // ── Every module disabled ────────────────────────────────────────────────

    public function test_every_seam_resolves_with_no_modules_installed(): void
    {
        $this->withCapabilities([]);

        foreach (ProviderRegistry::seams() as $seam) {
            $provider = app(ProviderRegistry::class)->for($seam);

            $this->assertInstanceOf($seam, $provider);
            $this->assertStringStartsWith(
                'App\Modules\Operations\ProjectManagement\Providers\Local\\',
                $provider::class,
                "{$seam} must fall back to a local implementation when nothing is installed."
            );
        }
    }

    public function test_people_come_from_pm_itself_when_hr_is_absent(): void
    {
        $this->withCapabilities([]);

        $project = $this->makeProject();
        ProjectMember::create([
            'project_id' => $project->id, 'role_key' => 'team_member',
            'display_name' => 'Sipho Ndlovu', 'email' => 'sipho@example.test',
        ]);

        $results = app(ProviderRegistry::class)->for(EmployeeProvider::class)->search('Sipho');

        $this->assertCount(1, $results);
        $this->assertSame('Sipho Ndlovu', $results[0]->label);
        $this->assertSame('local', $results[0]->source);
    }

    public function test_the_local_provider_does_not_pretend_to_know_about_leave(): void
    {
        $this->withCapabilities([]);

        $provider = app(ProviderRegistry::class)->for(EmployeeProvider::class);
        $result = $provider->isAvailable('anyone', '2026-08-01', '2026-08-07');

        // Available, but with nothing checked — so a screen can say "leave not checked" instead of
        // implying an assurance PM never made.
        $this->assertFalse($provider->supportsLeave());
        $this->assertTrue($result->available);
        $this->assertSame([], $result->checked);
    }

    public function test_expenses_and_time_stay_in_pm_without_a_ledger_or_payroll(): void
    {
        $this->withCapabilities([]);

        $registry = app(ProviderRegistry::class);
        $expenses = $registry->for(ExpenseSink::class);
        $time = $registry->for(TimeSink::class);

        $this->assertFalse($expenses->postsToLedger());
        $this->assertFalse($time->feedsPayroll());

        // Nothing is lost: the record is kept and an event is published, so activating Accounting
        // later has a complete history to work from.
        $reference = DB::transaction(fn () => $expenses->post(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'amount' => '150.00', 'currency' => 'ZAR']));

        $this->assertStringStartsWith('PM-EXP-', $reference);
        $this->assertDatabaseHas('pm_outbox', ['event_type' => 'pm.expense.recorded']);
    }

    public function test_a_seam_with_no_local_workflow_says_so_rather_than_showing_an_empty_tab(): void
    {
        $this->withCapabilities([]);

        $purchases = app(ProviderRegistry::class)->for(PurchaseProvider::class);

        $this->assertFalse($purchases->isAvailable());
        $this->assertSame([], $purchases->forProject('any'));
    }

    // ── Modules enabled ──────────────────────────────────────────────────────

    public function test_hr_and_crm_take_over_their_seams_when_licensed(): void
    {
        $this->withCapabilities(['hr.employees', 'crm.customers']);

        $registry = app(ProviderRegistry::class);

        $this->assertInstanceOf(Integrated\HrEmployeeProvider::class, $registry->for(EmployeeProvider::class));
        $this->assertInstanceOf(Integrated\CrmClientProvider::class, $registry->for(ClientProvider::class));

        // Seams with no integration yet stay local — truthfully, rather than pretending.
        $this->assertInstanceOf(Local\LocalExpenseSink::class, $registry->for(ExpenseSink::class));
    }

    public function test_one_capability_does_not_switch_another_seam(): void
    {
        $this->withCapabilities(['hr.employees']);

        $registry = app(ProviderRegistry::class);

        $this->assertInstanceOf(Integrated\HrEmployeeProvider::class, $registry->for(EmployeeProvider::class));
        $this->assertInstanceOf(Local\LocalClientProvider::class, $registry->for(ClientProvider::class));
    }

    public function test_the_status_map_reports_what_is_integrated(): void
    {
        $this->withCapabilities(['crm.customers']);

        $status = app(ProviderRegistry::class)->status();

        $this->assertTrue($status['ClientProvider']['integrated']);
        $this->assertFalse($status['EmployeeProvider']['integrated']);

        // A seam that names a capability is one that *can* upgrade — it is not integrated until
        // the tenant has the module. ExpenseSink names `finance.postings` and is not integrated
        // here, because only CRM is on.
        $this->assertSame('finance.postings', $status['ExpenseSink']['capability']);
        $this->assertFalse($status['ExpenseSink']['integrated']);

        // DocumentStore is the one seam with no integration written, and says so with a null
        // capability rather than pretending to an upgrade that does not exist.
        $this->assertNull($status['DocumentStore']['capability']);
    }

    // ── Degradation when a module goes away ──────────────────────────────────

    public function test_a_link_keeps_its_name_when_its_module_is_deactivated(): void
    {
        $ref = ExternalRef::create([
            'local_type' => 'project_client', 'source_module' => 'crm', 'source_entity' => 'customer',
            'source_id' => '4321', 'display_name' => 'Sandton Developments (Pty) Ltd', 'sync_status' => 'ok',
        ]);

        // CRM is switched off. The project must still show its client.
        $this->withCapabilities([]);

        $record = app(ExternalRefResolver::class)->resolve($ref->fresh());

        $this->assertSame('Sandton Developments (Pty) Ltd', $record->label);
        $this->assertFalse($record->isResolved);
        // Stale, not orphaned: the module may come back, and 'orphaned' invites a conversion that
        // would throw away a working link.
        $this->assertSame('stale', $ref->fresh()->sync_status);
    }

    public function test_a_record_deleted_in_its_module_is_flagged_orphaned_not_cascaded(): void
    {
        $ref = ExternalRef::create([
            'local_type' => 'project_client', 'source_module' => 'crm', 'source_entity' => 'customer',
            'source_id' => 'gone-9999', 'display_name' => 'Closed Account', 'sync_status' => 'ok',
        ]);

        // CRM is present and says it has no such record.
        $this->withCapabilities(['crm.customers']);

        $record = app(ExternalRefResolver::class)->resolve($ref->fresh());

        $this->assertSame('orphaned', $ref->fresh()->sync_status);
        $this->assertSame('Closed Account', $record->label, 'The last-known name must survive.');
        $this->assertTrue(app(ExternalRefResolver::class)->orphaned()->contains('id', $ref->id));
    }

    public function test_an_orphaned_link_converts_to_a_local_record_in_one_action(): void
    {
        $ref = ExternalRef::create([
            'local_type' => 'project_client', 'source_module' => 'crm', 'source_entity' => 'customer',
            'source_id' => 'gone-1', 'display_name' => 'Orphaned Client', 'sync_status' => 'orphaned',
        ]);

        $converted = app(ExternalRefResolver::class)->convertToLocal($ref);

        $this->assertTrue($converted->isLocal());
        $this->assertSame('ok', $converted->sync_status);
        $this->assertNull($converted->source_id);
        // Nothing retyped: the name the project always showed is now PM's own.
        $this->assertSame('Orphaned Client', $converted->display_name);
    }

    public function test_a_source_that_throws_degrades_instead_of_500ing(): void
    {
        $ref = ExternalRef::create([
            'local_type' => 'project_client', 'source_module' => 'crm', 'source_entity' => 'customer',
            'source_id' => '77', 'display_name' => 'Flaky Source Ltd', 'sync_status' => 'ok',
        ]);

        $this->withCapabilities(['crm.customers']);

        // The module is installed but its query blows up. "No crash, no blank screen, no 500
        // anywhere" is the acceptance criterion, so this must still render.
        $this->mock(ClientProvider::class, fn ($m) => $m->shouldReceive('get')->andThrow(new \RuntimeException('CRM exploded')));
        $this->app->bind(ProviderRegistry::class, fn () => new class(app(ModuleRegistry::class)) extends ProviderRegistry {
            public function for(string $seam): object
            {
                return app(ClientProvider::class);
            }

            public function isIntegrated(string $seam): bool
            {
                return true;
            }
        });

        $record = app(ExternalRefResolver::class)->resolve($ref->fresh());

        $this->assertSame('Flaky Source Ltd', $record->label);
        $this->assertFalse($record->isResolved);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Pin the tenant's capability set, so a test states the world it is testing. */
    private function withCapabilities(array $capabilities): void
    {
        $this->mock(ModuleRegistry::class, function ($mock) use ($capabilities) {
            $mock->shouldReceive('has')->andReturnUsing(fn (string $c) => in_array($c, $capabilities, true));
            $mock->shouldReceive('forResponse')->andReturn([]);
            $mock->shouldReceive('capabilities')->andReturn($capabilities);
            $mock->shouldReceive('isAvailable')->andReturn(false);
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
