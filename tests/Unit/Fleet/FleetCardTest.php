<?php

namespace Tests\Unit\Fleet;

use App\Modules\Fleet\Models\FleetCard;
use App\Modules\Fleet\Models\FleetCardTransaction;
use Database\Factories\Fleet\FleetCardFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetCardTest extends TestCase
{
    use RefreshDatabase;

    // ── isOverDailyLimit ──────────────────────────────────────────────────────

    public function test_amount_under_daily_limit_is_not_over_limit(): void
    {
        $card = FleetCardFactory::new()->create(['daily_limit' => 500.00]);

        $this->assertFalse($card->isOverDailyLimit(499.99));
    }

    public function test_amount_equal_to_daily_limit_is_not_over_limit(): void
    {
        $card = FleetCardFactory::new()->create(['daily_limit' => 500.00]);

        $this->assertFalse($card->isOverDailyLimit(500.00));
    }

    public function test_amount_exceeding_daily_limit_is_over_limit(): void
    {
        $card = FleetCardFactory::new()->create(['daily_limit' => 500.00]);

        $this->assertTrue($card->isOverDailyLimit(500.01));
    }

    public function test_no_daily_limit_set_is_never_over_limit(): void
    {
        $card = FleetCardFactory::new()->create(['daily_limit' => null]);

        $this->assertFalse($card->isOverDailyLimit(99999.00));
    }

    // ── maskedNumber accessor ─────────────────────────────────────────────────

    public function test_masked_number_uses_last4_digits(): void
    {
        $card = FleetCardFactory::new()->create(['card_number_last4' => '1234']);

        $this->assertSame('**** **** **** 1234', $card->masked_number);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function test_active_scope_returns_only_active_cards(): void
    {
        FleetCardFactory::new()->active()->count(2)->create();
        FleetCardFactory::new()->blocked()->count(3)->create();

        $this->assertCount(2, FleetCard::query()->active()->get());
    }

    // ── Transaction scopes ────────────────────────────────────────────────────

    public function test_flagged_scope_returns_only_flagged_transactions(): void
    {
        $card = FleetCardFactory::new()->create();

        FleetCardTransaction::create([
            'fleet_card_id'    => $card->id,
            'transaction_type' => 'fuel',
            'transaction_at'   => now(),
            'amount'           => 100.00,
            'is_flagged'       => true,
        ]);

        FleetCardTransaction::create([
            'fleet_card_id'    => $card->id,
            'transaction_type' => 'fuel',
            'transaction_at'   => now(),
            'amount'           => 200.00,
            'is_flagged'       => false,
        ]);

        $this->assertCount(1, FleetCardTransaction::query()->flagged()->get());
    }

    public function test_unreconciled_scope_returns_unreconciled_transactions(): void
    {
        $card = FleetCardFactory::new()->create();

        FleetCardTransaction::create([
            'fleet_card_id'    => $card->id,
            'transaction_type' => 'fuel',
            'transaction_at'   => now(),
            'amount'           => 100.00,
            'reconciled'       => false,
        ]);

        FleetCardTransaction::create([
            'fleet_card_id'    => $card->id,
            'transaction_type' => 'fuel',
            'transaction_at'   => now(),
            'amount'           => 50.00,
            'reconciled'       => true,
        ]);

        $this->assertCount(1, FleetCardTransaction::query()->unreconciled()->get());
    }
}
