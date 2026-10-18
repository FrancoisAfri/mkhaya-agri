<?php

namespace Database\Factories\Finance;

use App\Modules\Finance\StaffLoan\Models\StaffLoanType;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffLoanTypeFactory extends Factory
{
    protected $model = StaffLoanType::class;

    public function definition(): array
    {
        return [
            'name'                           => fake()->randomElement(['Personal Loan', 'Emergency Loan', 'Housing Loan', 'Study Loan', 'Vehicle Loan']),
            'description'                    => fake()->sentence(),
            'min_amount'                     => 1000.00,
            'max_amount'                     => 100000.00,
            'min_term_months'                => 6,
            'max_term_months'                => 60,
            'interest_rate'                  => 12.00,
            'interest_type'                  => 'reducing_balance',
            'requires_permanent_status'      => false,
            'requires_probation_completion'  => false,
            'executive_approval_threshold'   => null,
            'is_active'                      => true,
        ];
    }

    public function noInterest(): static
    {
        return $this->state([
            'interest_rate' => 0.00,
            'interest_type' => 'none',
        ]);
    }

    public function fixedInterest(): static
    {
        return $this->state(['interest_type' => 'fixed']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
