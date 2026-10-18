<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\OutboxMessage;
use App\Modules\Operations\ProjectManagement\Models\Webhook;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\Hash;

/**
 * The health check (EPIC-080).
 *
 * Tested because a monitoring command is the one piece of code nobody notices is broken: it passes
 * quietly whether or not it is actually looking at anything. Each test here breaks one invariant on
 * purpose and insists the command says so.
 *
 * The first version of this command guessed partition names, found none, and reported a healthy
 * database as failing. A check that cries wolf gets muted, and a muted check is worse than none.
 */
class HealthCheckTest extends ProjectManagementTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Alpha Construction']);
    }

    private function health(): array
    {
        // Artisan::call rather than $this->artisan(): the latter is lazy and returns a pending
        // command, so its output is not there yet when you go looking for it.
        \Illuminate\Support\Facades\Artisan::call('pm:health', [
            '--company' => $this->company->id,
            '--json'    => true,
        ]);

        return json_decode(\Illuminate\Support\Facades\Artisan::output(), true) ?? [];
    }

    private function check(array $health, string $needle): ?array
    {
        foreach ($health['checks'] as $check) {
            if (str_contains($check['name'], $needle)) {
                return $check;
            }
        }

        return null;
    }

    public function test_a_healthy_module_reports_healthy(): void
    {
        $health = $this->health();

        $this->assertTrue($health['healthy'], json_encode($health['checks']));
    }

    /**
     * The partition check reads real range bounds rather than guessing names — this is the test
     * that would have caught the first version calling a healthy database broken.
     */
    public function test_the_partition_check_sees_the_partitions_that_exist(): void
    {
        $partitions = $this->check($this->health(), 'partitions');

        $this->assertNotNull($partitions);
        $this->assertTrue($partitions['ok'], $partitions['detail']);
        $this->assertStringContainsString('month(s) ahead', $partitions['detail']);
    }

    public function test_a_stalled_outbox_is_reported(): void
    {
        $this->asCompany($this->company, function () {
            // One message that gave up entirely: an integration has been missing events, and
            // nothing anywhere else in the system says so.
            OutboxMessage::create([
                'company_id'   => $this->company->id,
                'event_type'   => 'pm.task.created',
                'subject_type' => 'task',
                'subject_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'payload'      => [],
                'available_at' => now(),
                'attempts'     => 8,
                'dead_at'      => now(),
            ]);
        });

        $health = $this->health();
        $outbox = $this->check($health, 'outbox');

        $this->assertFalse($health['healthy']);
        $this->assertFalse($outbox['ok']);
        $this->assertStringContainsString('gave up', $outbox['detail']);
    }

    public function test_a_webhook_switched_off_after_failures_is_reported(): void
    {
        $this->asCompany($this->company, function () {
            Webhook::create([
                'company_id'  => $this->company->id,
                'name'        => 'Site system',
                'url'         => 'https://example.test/hook',
                'secret'      => 'whsec_test',
                'secret_hint' => 'test',
                'event_types' => [],
                'is_active'   => false,
                'consecutive_failures' => Webhook::FAILURE_LIMIT,
                'disabled_at' => now(),
                'disabled_reason' => 'Endpoint gone.',
            ]);
        });

        $health = $this->health();

        $this->assertFalse($health['healthy']);
        $this->assertStringContainsString('switched off', $this->check($health, 'webhooks')['detail']);
    }

    public function test_the_command_exits_non_zero_when_something_needs_attention(): void
    {
        $this->asCompany($this->company, function () {
            OutboxMessage::create([
                'company_id'   => $this->company->id,
                'event_type'   => 'pm.task.created',
                'subject_type' => 'task',
                'subject_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'payload'      => [],
                'available_at' => now(),
                'attempts'     => 8,
                'dead_at'      => now(),
            ]);
        });

        // The exit code is the whole interface to a monitor; a check that always exits 0 is
        // decoration.
        $this->artisan('pm:health', ['--company' => $this->company->id])->assertFailed();
    }

    public function test_a_healthy_module_exits_zero(): void
    {
        $this->artisan('pm:health', ['--company' => $this->company->id])->assertSuccessful();
    }
}
