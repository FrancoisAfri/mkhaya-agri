<?php

namespace Database\Factories\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ClaimFactory extends Factory
{
    protected $model = Claim::class;

    public function definition(): array
    {
        return [
            'uuid'             => (string) Str::uuid(),
            'reference_number' => 'CLM-' . now()->year . '-' . str_pad(fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'employee_id'      => User::factory(),
            'category_id'      => ClaimCategoryFactory::new(),
            'title'            => fake()->sentence(4),
            'description'      => fake()->paragraph(),
            'currency'         => 'ZAR',
            'total_amount'     => fake()->randomFloat(2, 100, 5000),
            'claim_date'       => now()->toDateString(),
            'status'           => 'draft',
            'created_by'       => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state([
            'status'       => 'submitted',
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'status'       => 'approved',
            'submitted_at' => now()->subDays(3),
            'approved_at'  => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status'           => 'rejected',
            'submitted_at'     => now()->subDays(3),
            'rejected_at'      => now(),
            'rejection_reason' => fake()->sentence(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(function () {
            $paidBy = User::factory()->create(['status' => 1]);
            return [
                'status'       => 'paid',
                'submitted_at' => now()->subDays(7),
                'approved_at'  => now()->subDays(3),
                'paid_at'      => now(),
                'paid_by'      => $paidBy->id,
            ];
        });
    }
}
