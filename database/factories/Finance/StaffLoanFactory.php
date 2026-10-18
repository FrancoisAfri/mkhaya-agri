<?php

namespace Database\Factories\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\StaffLoan\Models\StaffLoan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StaffLoanFactory extends Factory
{
    protected $model = StaffLoan::class;

    public function definition(): array
    {
        return [
            'uuid'                => (string) Str::uuid(),
            'reference_number'    => 'SL-' . strtoupper(Str::random(8)),
            'employee_id'         => User::factory(),
            'loan_type_id'        => StaffLoanTypeFactory::new(),
            'amount_requested'    => 10000.00,
            /*
             * Omitted, not nulled.
             *
             * These columns are NOT NULL with a column default, so passing an explicit null is the
             * one thing that fails — the default only applies when the column is absent from the
             * insert. `StaffLoanService::apply()` leaves them out, which is what a pending
             * application means: nothing is approved and nothing is owed until it is.
             */
            'monthly_installment' => null,
            'term_months'         => 12,
            'purpose'             => fake()->sentence(6),
            'employment_status'   => 'permanent',
            'is_permanent'        => true,
            'probation_completed' => true,
            'is_suspended'        => false,
            'monthly_net_pay'     => 25000.00,
            'status'              => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(function () {
            $approver = User::factory()->create(['status' => 1]);
            return [
                'status'      => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ];
        });
    }

    public function active(): static
    {
        return $this->state(function () {
            $approver = User::factory()->create(['status' => 1]);
            return [
                'status'              => 'active',
                'amount_approved'     => 10000.00,
                'outstanding_balance' => 10000.00,
                'monthly_installment' => 888.49,
                'approved_by'         => $approver->id,
                'approved_at'         => now()->subDays(5),
                'disbursed_at'        => now()->subDays(3),
            ];
        });
    }

    public function rejected(): static
    {
        return $this->state(function () {
            $rejector = User::factory()->create(['status' => 1]);
            return [
                'status'           => 'rejected',
                'rejected_by'      => $rejector->id,
                'rejected_at'      => now(),
                'rejection_reason' => fake()->sentence(),
            ];
        });
    }

    public function closed(): static
    {
        return $this->state(function () {
            $approver = User::factory()->create(['status' => 1]);
            return [
                'status'              => 'closed',
                'amount_approved'     => 10000.00,
                'outstanding_balance' => 0.00,
                'monthly_installment' => 888.49,
                'approved_by'         => $approver->id,
                'approved_at'         => now()->subMonths(13),
                'disbursed_at'        => now()->subMonths(13),
                'closed_at'           => now(),
            ];
        });
    }
}
