<?php

namespace Tests\Feature\HR\Payroll;

use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use Database\Factories\HR\PayrollRunFactory;

class PayrollAnomalyTest extends PayrollTestCase
{
    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_hr_manager_can_view_anomalies_index(): void
    {
        $this->actingAs($this->hrManager)
            ->get(route('payroll.anomalies.index'))
            ->assertStatus(200);
    }

    // ── Resolve ───────────────────────────────────────────────────────────────

    public function test_hr_manager_can_resolve_an_anomaly(): void
    {
        $run     = PayrollRunFactory::new()->create(['created_by' => $this->hrManager->id]);
        $anomaly = PayrollAnomaly::create([
            'run_id'       => $run->id,
            'employee_id'  => $this->employee->id,
            'anomaly_type' => 'overpayment',
            'description'  => 'Employee gross pay exceeded ceiling.',
            'severity'     => 'high',
            'status'       => 'open',
            'detected_at'  => now(),
        ]);

        $this->actingAs($this->hrManager)
            ->post(route('payroll.anomalies.resolve', $anomaly->uuid), [
                'resolution_notes' => 'Corrected the gross pay figure and recalculated net.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('payroll_anomalies', [
            'id'     => $anomaly->id,
            'status' => 'resolved',
        ]);
    }

    public function test_resolve_requires_resolution_notes(): void
    {
        $run     = PayrollRunFactory::new()->create(['created_by' => $this->hrManager->id]);
        $anomaly = PayrollAnomaly::create([
            'run_id'       => $run->id,
            'employee_id'  => $this->employee->id,
            'anomaly_type' => 'underpayment',
            'description'  => 'Net pay below minimum wage.',
            'severity'     => 'critical',
            'status'       => 'open',
            'detected_at'  => now(),
        ]);

        $this->actingAs($this->hrManager)
            ->post(route('payroll.anomalies.resolve', $anomaly->uuid), [
                // resolution_notes is required (min:10)
            ])
            ->assertSessionHasErrors('resolution_notes');
    }

    // ── Dismiss ───────────────────────────────────────────────────────────────

    public function test_hr_manager_can_dismiss_an_anomaly(): void
    {
        $run     = PayrollRunFactory::new()->create(['created_by' => $this->hrManager->id]);
        $anomaly = PayrollAnomaly::create([
            'run_id'       => $run->id,
            'employee_id'  => $this->employee->id,
            'anomaly_type' => 'late_submission',
            'description'  => 'Payslip submitted outside window.',
            'severity'     => 'low',
            'status'       => 'open',
            'detected_at'  => now(),
        ]);

        $this->actingAs($this->hrManager)
            ->post(route('payroll.anomalies.dismiss', $anomaly->uuid))
            ->assertRedirect();

        $this->assertDatabaseHas('payroll_anomalies', [
            'id'     => $anomaly->id,
            'status' => 'dismissed',
        ]);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function test_anomaly_counts_are_shown_in_stats(): void
    {
        $run = PayrollRunFactory::new()->create(['created_by' => $this->hrManager->id]);

        PayrollAnomaly::create([
            'run_id' => $run->id, 'employee_id' => $this->employee->id,
            'anomaly_type' => 'overpayment', 'description' => 'First open anomaly.',
            'severity' => 'medium', 'status' => 'open', 'detected_at' => now(),
        ]);

        PayrollAnomaly::create([
            'run_id' => $run->id, 'employee_id' => $this->employee->id,
            'anomaly_type' => 'underpayment', 'description' => 'Second open anomaly.',
            'severity' => 'high', 'status' => 'open', 'detected_at' => now(),
        ]);

        $response = $this->actingAs($this->hrManager)
            ->get(route('payroll.anomalies.index'));

        $response->assertStatus(200);

        // The controller passes a 'stats' prop; verify the Inertia response data.
        $response->assertInertia(fn ($page) =>
            $page->where('stats.total_open', fn ($value) => $value >= 2)
        );
    }
}
