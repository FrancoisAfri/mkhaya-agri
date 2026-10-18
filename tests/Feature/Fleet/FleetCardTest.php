<?php

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Models\FleetCardTransaction;
use Database\Factories\Fleet\FleetCardFactory;
use Database\Factories\Fleet\FleetVehicleFactory;

class FleetCardTest extends FleetTestCase
{
    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function test_manager_can_create_a_fleet_card(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.cards.store'), [
                'card_number_last4' => '4567',
                'card_token'        => 'tok_' . str_repeat('x', 28),
                'card_type'         => 'fuel',
                'provider'          => 'Shell',
                'vehicle_id'        => $vehicle->id,
                'expiry_month'      => 12,
                'expiry_year'       => 2027,
                'daily_limit'       => 2000,
                'status'            => 'active',
                'monthly_limit'     => 20000,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_cards', [
            'card_number_last4' => '4567',
            'provider'          => 'Shell',
            'vehicle_id'        => $vehicle->id,
        ]);
    }

    public function test_manager_can_delete_card(): void
    {
        $card = FleetCardFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->delete(route('fleet.cards.destroy', $card))
            ->assertRedirect();

        // Cancelling a card soft-deletes it and records why — the row stays for the audit
        // trail of every transaction already charged to it.
        $this->assertSoftDeleted('fleet_cards', ['id' => $card->id, 'status' => 'cancelled']);
    }

    // ── Transactions ──────────────────────────────────────────────────────────

    public function test_manager_can_log_transaction_against_card(): void
    {
        $card = FleetCardFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.cards.transactions.store', $card), [
                'transaction_type' => 'fuel',
                'transaction_at'   => now()->toDateTimeString(),
                'merchant_name'    => 'Shell Rosebank',
                'amount'           => 850.00,
                'fuel_type'        => 'petrol',
                'litres'           => 35.5,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_card_transactions', [
            'fleet_card_id'    => $card->id,
            'amount'           => 850.00,
            'transaction_type' => 'fuel',
        ]);
    }

    public function test_manager_can_flag_suspicious_transaction(): void
    {
        $card        = FleetCardFactory::new()->create();
        $transaction = FleetCardTransaction::create([
            'fleet_card_id'    => $card->id,
            'transaction_type' => 'fuel',
            'transaction_at'   => now(),
            'amount'           => 5000.00,
            'is_flagged'       => false,
        ]);

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.cards.transactions.flag', $transaction), [
                'flag_reason' => 'Amount exceeds daily limit',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_card_transactions', [
            'id'        => $transaction->id,
            'is_flagged'=> true,
        ]);
    }

    public function test_manager_can_reconcile_transaction(): void
    {
        $card        = FleetCardFactory::new()->create();
        $transaction = FleetCardTransaction::create([
            'fleet_card_id'    => $card->id,
            'transaction_type' => 'fuel',
            'transaction_at'   => now(),
            'amount'           => 300.00,
            'reconciled'       => false,
        ]);

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.cards.transactions.reconcile', $transaction))
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_card_transactions', [
            'id'         => $transaction->id,
            'reconciled' => true,
        ]);
    }
}
