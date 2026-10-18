<?php

namespace Tests\Unit\Recruitment\Commands;

use App\Modules\HR\Contracts\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireContractsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_unsigned_contracts_past_expiry(): void
    {
        $expired = Contract::factory()->sentForSigning()->create([
            'expires_at' => now()->subHour(),
        ]);
        $valid = Contract::factory()->sentForSigning()->create([
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('recruitment:expire-contracts')
            ->assertExitCode(0);

        $this->assertEquals('expired',          $expired->fresh()->status->value);
        $this->assertEquals('sent_for_signing', $valid->fresh()->status->value);
    }

    public function test_does_not_expire_fully_signed_contracts(): void
    {
        $signed = Contract::factory()->signed()->create([
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('recruitment:expire-contracts');

        // signed contracts have status='signed' not 'sent', so they are excluded by whereIn filter
        $this->assertNotEquals('expired', $signed->fresh()->status->value);
    }

    public function test_dry_run_does_not_mutate(): void
    {
        $contract = Contract::factory()->sentForSigning()->create([
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('recruitment:expire-contracts --dry-run')
            ->assertExitCode(0);

        $this->assertEquals('sent_for_signing', $contract->fresh()->status->value);
    }

    public function test_outputs_expired_count(): void
    {
        Contract::factory()->sentForSigning()->create(['expires_at' => now()->subHour()]);
        Contract::factory()->sentForSigning()->create(['expires_at' => now()->subHour()]);

        $this->artisan('recruitment:expire-contracts')
            ->expectsOutputToContain('Expired 2 contract(s)')
            ->assertExitCode(0);
    }
}
