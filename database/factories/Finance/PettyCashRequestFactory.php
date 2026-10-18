<?php

namespace Database\Factories\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PettyCashRequestFactory extends Factory
{
    protected $model = PettyCashRequest::class;

    public function definition(): array
    {
        return [
            'uuid'             => (string) Str::uuid(),
            'reference_number' => 'PCR-' . now()->year . '-' . str_pad(fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'float_id'         => PettyCashFloatFactory::new(),
            'requestor_id'     => User::factory(),
            // Required: a request is raised against a float, and every float has a custodian who
            // holds the cash. There is no such thing as a petty-cash request with nobody to pay it.
            'custodian_id'     => User::factory(),
            'approver_id'      => null,
            'purpose'          => fake()->sentence(5),
            'category'         => fake()->randomElement(['stationery', 'transport', 'refreshments', 'maintenance', 'miscellaneous']),
            'amount_requested' => 500.00,
            'amount_approved'  => null,
            'amount_issued'    => null,
            'amount_spent'     => null,
            'amount_returned'  => null,
            'status'           => 'pending',
            'requested_at'     => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(function () {
            $approver = User::factory()->create(['status' => 1]);
            return [
                'status'          => 'approved',
                'approver_id'     => $approver->id,
                'amount_approved' => 500.00,
                'approved_at'     => now(),
            ];
        });
    }

    public function rejected(): static
    {
        return $this->state(function () {
            $approver = User::factory()->create(['status' => 1]);
            return [
                'status'           => 'rejected',
                'approver_id'      => $approver->id,
                'rejection_reason' => fake()->sentence(),
                'rejected_at'      => now(),
            ];
        });
    }

    public function issued(): static
    {
        return $this->state(function () {
            $approver = User::factory()->create(['status' => 1]);
            return [
                'status'          => 'issued',
                'approver_id'     => $approver->id,
                'amount_approved' => 500.00,
                'amount_issued'   => 500.00,
                'approved_at'     => now()->subHours(2),
                'issued_at'       => now(),
            ];
        });
    }

    public function settled(): static
    {
        return $this->state(function () {
            $approver = User::factory()->create(['status' => 1]);
            return [
                'status'            => 'settled',
                'approver_id'       => $approver->id,
                'amount_approved'   => 500.00,
                'amount_issued'     => 500.00,
                'amount_spent'      => 480.00,
                'amount_returned'   => 20.00,
                'receipt_submitted' => true,
                'approved_at'       => now()->subDays(2),
                'issued_at'         => now()->subDay(),
                'settled_at'        => now(),
            ];
        });
    }
}
