<?php

namespace Database\Factories\HR;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Enums\PayslipStatus;
use App\Modules\HR\Payroll\Models\Payslip;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PayslipFactory extends Factory
{
    protected $model = Payslip::class;

    public function definition(): array
    {
        return [
            'uuid'             => (string) Str::uuid(),
            'payroll_run_id'   => PayrollRunFactory::new(),
            'employee_id'      => User::factory(),
            // period_start/period_end are NOT NULL on payslips (mirror the run's period).
            'period_start'     => now()->startOfMonth()->toDateString(),
            'period_end'       => now()->endOfMonth()->toDateString(),
            'basic_salary'     => 20000.00,
            'gross_pay'        => 22000.00,
            'total_deductions' => 5000.00,
            'net_pay'          => 17000.00,
            'paye'             => 3500.00,
            'uif'              => 177.12,
            'pension'          => 0.00,
            'status'           => PayslipStatus::Draft->value,
        ];
    }

    public function finalised(): static
    {
        return $this->state(['status' => PayslipStatus::Finalised->value]);
    }

    public function viewed(): static
    {
        return $this->state(['status' => PayslipStatus::Viewed->value]);
    }
}
