<?php

namespace Database\Factories;

use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeaveBalanceTransactionFactory extends Factory
{
    protected $model = LeaveBalanceTransaction::class;

    public function definition(): array
    {
        return [
            'employee_id'      => 1,
            'leave_type_id'    => LeaveType::factory(),
            'leave_cycle_id'   => null,
            'transaction_type' => LeaveTransactionType::Allocation->value,
            'hours'            => $this->faker->randomFloat(2, 0, 120),
            'balance_before'   => 0,
            'balance_after'    => 0,
            'description'      => 'Test transaction',
            'performed_by'     => null,
            'created_at'       => now(),
        ];
    }
}
