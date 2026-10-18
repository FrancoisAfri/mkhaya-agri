<?php

namespace Database\Factories\HR;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        $periodStart = now()->subMonth()->startOfMonth();
        $periodEnd   = now()->subMonth()->endOfMonth();

        return [
            'uuid'             => (string) Str::uuid(),
            'ref_number'       => 'PR-' . $periodStart->format('Ym') . '-' . str_pad(fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'name'             => $periodStart->format('F Y') . ' Payroll',
            'created_by'       => User::factory(),
            'period_start'     => $periodStart->toDateString(),
            'period_end'       => $periodEnd->toDateString(),
            'status'           => PayrollRunStatus::Draft->value,
            'total_gross'      => 0.00,
            'total_deductions' => 0.00,
            'total_net'        => 0.00,
            'employee_count'   => 0,
            'notes'            => null,
        ];
    }

    public function released(): static
    {
        return $this->state([
            'status'         => PayrollRunStatus::Released->value,
            'total_gross'    => 120000.00,
            'total_deductions'=> 20000.00,
            'total_net'      => 100000.00,
            'employee_count' => 10,
        ]);
    }

    public function review(): static
    {
        return $this->state(['status' => PayrollRunStatus::Review->value]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => PayrollRunStatus::Cancelled->value]);
    }
}
