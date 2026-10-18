<?php

namespace Database\Factories\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\StaffLoan\Models\SalaryAdvance;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SalaryAdvanceFactory extends Factory
{
    protected $model = SalaryAdvance::class;

    public function definition(): array
    {
        return [
            'uuid'                => (string) Str::uuid(),
            'reference_number'    => 'SA-' . strtoupper(Str::random(8)),
            'employee_id'         => User::factory(),
            'amount_requested'    => 5000.00,
            'amount_approved'     => null,
            'monthly_net_pay'     => 25000.00,
            'percentage_of_net'   => 20.00,
            'repayment_month'     => now()->addMonth()->startOfMonth()->toDateString(),
            'reason'              => fake()->sentence(6),
            'employment_status'   => 'permanent',
            'is_permanent'        => true,
            'probation_completed' => true,
            'is_suspended'        => false,
            'status'              => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(function () {
            $approver = User::factory()->create(['status' => 1]);
            return [
                'status'          => 'approved',
                'amount_approved' => 5000.00,
                'approved_by'     => $approver->id,
                'approved_at'     => now(),
            ];
        });
    }

    public function disbursed(): static
    {
        return $this->state(function () {
            $approver = User::factory()->create(['status' => 1]);
            return [
                'status'          => 'disbursed',
                'amount_approved' => 5000.00,
                'approved_by'     => $approver->id,
                'approved_at'     => now()->subDays(2),
                'disbursed_at'    => now(),
            ];
        });
    }

    public function settled(): static
    {
        return $this->state(function () {
            $approver = User::factory()->create(['status' => 1]);
            return [
                'status'          => 'settled',
                'amount_approved' => 5000.00,
                'approved_by'     => $approver->id,
                'approved_at'     => now()->subDays(5),
                'disbursed_at'    => now()->subDays(3),
                'settled_at'      => now(),
            ];
        });
    }

    public function rejected(): static
    {
        return $this->state([
            'status'           => 'rejected',
            'rejection_reason' => fake()->sentence(),
        ]);
    }
}
