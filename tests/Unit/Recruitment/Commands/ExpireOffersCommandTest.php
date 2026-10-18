<?php

namespace Tests\Unit\Recruitment\Commands;

use App\Modules\HR\Offers\Models\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireOffersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_sent_offers_past_their_expiry_date(): void
    {
        $expired = Offer::factory()->sent()->create([
            'expires_at' => now()->subHour(),
        ]);
        $notExpired = Offer::factory()->sent()->create([
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('recruitment:expire-offers')
            ->assertExitCode(0);

        $this->assertEquals('expired', $expired->fresh()->status->value);
        $this->assertEquals('sent',    $notExpired->fresh()->status->value);
    }

    public function test_ignores_accepted_offers(): void
    {
        $accepted = Offer::factory()->accepted()->create([
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('recruitment:expire-offers');

        $this->assertEquals('accepted', $accepted->fresh()->status->value);
    }

    public function test_dry_run_does_not_mutate(): void
    {
        $offer = Offer::factory()->sent()->create([
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('recruitment:expire-offers --dry-run')
            ->assertExitCode(0);

        $this->assertEquals('sent', $offer->fresh()->status->value);
    }

    public function test_dry_run_outputs_count(): void
    {
        Offer::factory()->sent()->create(['expires_at' => now()->subHour()]);
        Offer::factory()->sent()->create(['expires_at' => now()->subHour()]);

        $this->artisan('recruitment:expire-offers --dry-run')
            ->expectsOutputToContain('2 offer(s) would be expired')
            ->assertExitCode(0);
    }

    public function test_outputs_expired_count(): void
    {
        Offer::factory()->sent()->create(['expires_at' => now()->subHour()]);

        $this->artisan('recruitment:expire-offers')
            ->expectsOutputToContain('Expired 1 offer(s)')
            ->assertExitCode(0);
    }
}
