<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Modules\Procurement\Governance\Console\ComputeWorkloadCommand;
use App\Modules\Procurement\Governance\Console\GenerateReportsCommand;
use App\Modules\Procurement\Governance\Console\SweepNotificationsCommand;
use App\Modules\Procurement\Governance\Console\SyncChainCommand;
use App\Modules\Procurement\Governance\Console\VerifyLedgerCommand;
use App\Modules\Procurement\Governance\Services\AuditLedger;
use App\Modules\Procurement\Governance\Services\NotificationService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The five scheduled commands, and the three ways a multi-tenant cron goes wrong without anyone
 * noticing for months.
 *
 * **It never runs.** A command with no schedule entry is indistinguishable from a working one until
 * someone asks why the reminders stopped. There is no error, because nothing ran.
 *
 * **It runs untenanted.** A console process has no session, so without TenantContext the row-level
 * security variable is unset and every query sees the whole estate. For this module that is not an
 * abstract leak: the notification sweep would mail one customer's overdue evaluations to another
 * customer's manager, addressed by name.
 *
 * **It stops at the first bad tenant.** This is the quiet one, and it is what these tests were
 * written for. `eachCompany()` walks a plain loop, so an exception on company four ends the pass —
 * companies five onward are simply never visited, and the run reports success because the tenants
 * that would have complained were never reached. A cron can fail this way for a year.
 */
class ScheduledCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** Every scheduled entry point this module owns. */
    private const COMMANDS = [
        'procgov:notify' => SweepNotificationsCommand::class,
        'procgov:workload' => ComputeWorkloadCommand::class,
        'procgov:sync-chain' => SyncChainCommand::class,
        'procgov:verify-ledger' => VerifyLedgerCommand::class,
        'procgov:reports' => GenerateReportsCommand::class,
    ];

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_every_command_is_registered_and_actually_scheduled(): void
    {
        // A command nobody calls is a feature that does not exist. Both halves are checked: it must
        // be a registered artisan command *and* it must appear in the scheduler.
        $registered = array_keys($this->app[Kernel::class]->all());

        $scheduled = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? $event->description ?? '')
            ->implode("\n");

        $missing = [];

        foreach (array_keys(self::COMMANDS) as $signature) {
            if (! in_array($signature, $registered, true)) {
                $missing[] = "{$signature} is not a registered command";
            }

            if (! str_contains($scheduled, $signature)) {
                $missing[] = "{$signature} is registered but never scheduled — it will never run";
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }

    public function test_every_command_can_be_restricted_to_a_single_tenant(): void
    {
        /*
         * `--company=` is what makes these commands operable: it is how support re-runs a failed
         * tenant without touching the others, and how TenantContext::dispatchEachCompany() fans the
         * work onto the queue. A command without it can only be run for the entire estate.
         */
        $without = [];

        foreach (self::COMMANDS as $signature => $class) {
            $definition = $this->app->make($class)->getDefinition();

            if (! $definition->hasOption('company')) {
                $without[] = $signature;
            }
        }

        $this->assertSame([], $without, 'These commands cannot be run for one tenant: '.implode(', ', $without));
    }

    public function test_a_command_leaves_no_tenant_applied_when_it_finishes(): void
    {
        /*
         * The scheduler runs commands back to back in one process. A command that exits with a
         * tenant still applied hands that tenant to whatever runs next, which then writes another
         * customer's rows under it — a leak with no failing query and no error to trace it by.
         */
        app(CurrentCompany::class)->clear();

        $this->artisan('procgov:verify-ledger')->assertSuccessful();

        $this->assertNull(app(CurrentCompany::class)->id(), 'The command finished with a tenant still applied.');
    }

    public function test_a_single_tenant_run_restores_whatever_was_active_before_it(): void
    {
        app(CurrentCompany::class)->set($this->companyId);

        $this->artisan('procgov:verify-ledger', ['--company' => $this->companyId])->assertSuccessful();

        $this->assertSame($this->companyId, app(CurrentCompany::class)->id());
    }

    public function test_one_failing_tenant_does_not_stop_the_others(): void
    {
        /*
         * The regression this suite exists for.
         *
         * Three tenants; the middle one throws. Before per-tenant isolation the walk ended there:
         * the third company was never swept, and the command still exited 0. The assertion that
         * matters is that the *third* company was reached — the exit code is the second-order
         * consequence.
         */
        $seen = [];
        $ids = [$this->companyId, $this->companyId + 1, $this->companyId + 2];

        $this->mock(NotificationService::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('sweep')->andReturnUsing(function (int $companyId) use (&$seen) {
                $seen[] = $companyId;

                if ($companyId === $this->companyId + 1) {
                    throw new RuntimeException('this tenant is broken');
                }

                return 0;
            });

            $mock->shouldReceive('escalate')->andReturn(0);
        });

        // A stand-in for the real company list, so the test does not depend on how many tenants the
        // database happens to hold.
        $this->mock(TenantContext::class, function ($mock) use ($ids) {
            $mock->shouldReceive('eachCompany')->andReturnUsing(function (callable $callback) use ($ids) {
                foreach ($ids as $id) {
                    $callback($id);
                }
            });

            $mock->shouldReceive('run')->andReturnUsing(fn ($id, callable $cb) => $cb());
            $mock->shouldReceive('apply')->andReturnNull();
        });

        $this->artisan('procgov:notify')->assertFailed();

        $this->assertSame($ids, $seen, 'The walk stopped at the failing tenant instead of carrying on.');
    }

    public function test_a_tenant_that_crashes_is_never_reported_as_a_clean_audit_chain(): void
    {
        /*
         * Specific to verify-ledger, because its success message is read as a decision not to
         * investigate. "No tampering detected" must never be printed off the back of a company
         * whose verification threw before it could look.
         */
        $ids = [$this->companyId, $this->companyId + 1];

        $this->mock(AuditLedger::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturnUsing(function (int $companyId) {
                if ($companyId !== $this->companyId) {
                    throw new RuntimeException('ledger unreadable');
                }

                return ['ok' => true, 'checked' => 3, 'broken_at' => null, 'reason' => null];
            });
        });

        $this->mock(TenantContext::class, function ($mock) use ($ids) {
            $mock->shouldReceive('eachCompany')->andReturnUsing(function (callable $callback) use ($ids) {
                foreach ($ids as $id) {
                    $callback($id);
                }
            });

            $mock->shouldReceive('run')->andReturnUsing(fn ($id, callable $cb) => $cb());
            $mock->shouldReceive('apply')->andReturnNull();
        });

        $this->artisan('procgov:verify-ledger')
            ->doesntExpectOutputToContain('No tampering detected')
            ->assertFailed();
    }

    public function test_every_command_completes_on_a_tenant_with_no_procurement_data(): void
    {
        /*
         * A tenant that has bought the module and not used it yet is the commonest tenant there is,
         * and it is the one the nightly run reaches first. Nothing here may divide by zero, assume a
         * financial year exists, or fail on an empty table.
         */
        foreach (array_keys(self::COMMANDS) as $signature) {
            $this->artisan($signature, ['--company' => $this->companyId])->assertSuccessful();
        }
    }

    public function test_the_chain_sync_skips_neighbouring_modules_that_are_not_installed(): void
    {
        /*
         * Standalone operation. This module links procurements to requisitions, orders, contracts
         * and invoices raised in other modules — none of which a customer who bought only this one
         * will have. The reconciliation must find nothing to link, not crash on a missing table.
         */
        $unguarded = [];

        foreach ($this->foreignTablesTouchedByTheLinker() as $table) {
            if (! str_contains($this->linkerSource(), "hasTable('{$table}')")) {
                $unguarded[] = $table;
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'ChainLinker queries these tables without checking they exist: '.implode(', ', $unguarded),
        );

        $this->artisan('procgov:sync-chain', ['--company' => $this->companyId])->assertSuccessful();
    }

    private function linkerSource(): string
    {
        return (string) file_get_contents(
            base_path('app/Modules/Procurement/Governance/Support/Integration/ChainLinker.php')
        );
    }

    /**
     * Tables the linker actually queries that belong to other modules.
     *
     * Read off the query-builder calls rather than off every quoted string in the file: a naive
     * scan picks up column names and audit labels like 'procurement_requests', and a test that
     * reports things which are not tables is a test people learn to ignore.
     *
     * @return list<string>
     */
    private function foreignTablesTouchedByTheLinker(): array
    {
        preg_match_all(
            "/(?:DB::table|->join|->leftJoin|->joinSub|->from)\(\s*'(\w+)/",
            $this->linkerSource(),
            $matches,
        );

        $foreign = array_filter(
            array_unique($matches[1]),
            fn (string $table) => ! str_starts_with($table, 'procgov_'),
        );

        // A guard on the guard: if the extraction ever stops matching, this test would pass over an
        // empty list and quietly stop checking anything.
        $this->assertNotEmpty($foreign, 'No cross-module tables were found in ChainLinker at all.');

        return array_values($foreign);
    }
}
