<?php

namespace Tests\Feature\HR\Payroll;

use Database\Factories\HR\PayrollRunFactory;

class PayrollRunTest extends PayrollTestCase
{
    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_payroll(): void
    {
        $this->get(route('payroll.dashboard'))
            ->assertRedirect(route('login'));
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function test_hr_manager_can_view_payroll_dashboard(): void
    {
        $this->actingAs($this->hrManager)
            ->get(route('payroll.dashboard'))
            ->assertStatus(200);
    }

    // ── Payroll Runs ──────────────────────────────────────────────────────────

    public function test_hr_manager_can_view_payroll_runs_index(): void
    {
        $this->actingAs($this->hrManager)
            ->get(route('payroll.runs.index'))
            ->assertStatus(200);
    }

    public function test_hr_manager_can_create_a_payroll_run(): void
    {
        $this->actingAs($this->hrManager)
            ->post(route('payroll.runs.store'), [
                'name'         => 'May 2026 Payroll',
                'period_start' => '2026-05-01',
                'period_end'   => '2026-05-31',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('payroll_runs', [
            'name'   => 'May 2026 Payroll',
            'status' => 'draft',
        ]);
    }

    public function test_payroll_run_requires_name_period_start_and_end(): void
    {
        $this->actingAs($this->hrManager)
            ->post(route('payroll.runs.store'), [
                // Missing name, period_start, period_end
            ])
            ->assertSessionHasErrors(['name', 'period_start', 'period_end']);
    }

    public function test_period_end_must_be_after_period_start(): void
    {
        $this->actingAs($this->hrManager)
            ->post(route('payroll.runs.store'), [
                'name'         => 'Bad Period Run',
                'period_start' => '2026-05-31',
                'period_end'   => '2026-05-01',
            ])
            ->assertSessionHasErrors('period_end');
    }

    public function test_hr_manager_can_view_a_payroll_run(): void
    {
        $run = PayrollRunFactory::new()->create(['created_by' => $this->hrManager->id]);

        $this->actingAs($this->hrManager)
            ->get(route('payroll.runs.show', $run->uuid))
            ->assertStatus(200);
    }

    // ── Lock and Submit ───────────────────────────────────────────────────────

    public function test_hr_manager_can_lock_a_payroll_run(): void
    {
        $run = PayrollRunFactory::new()->create(['created_by' => $this->hrManager->id]);

        $this->actingAs($this->hrManager)
            ->post(route('payroll.runs.lock', $run->uuid))
            ->assertRedirect();

        $this->assertDatabaseHas('payroll_runs', [
            'id'     => $run->id,
            'status' => 'review',
        ]);
    }

    public function test_hr_manager_can_submit_run_for_approval(): void
    {
        // Submit requires the run to not be Released or Cancelled,
        // and no existing pending approval.
        $run = PayrollRunFactory::new()->create(['created_by' => $this->hrManager->id]);

        $this->actingAs($this->hrManager)
            ->post(route('payroll.runs.submit', $run->uuid))
            ->assertRedirect();

        $this->assertDatabaseHas('payroll_approvals', [
            'run_id'       => $run->id,
            'submitted_by' => $this->hrManager->id,
            'status'       => 'pending',
        ]);
    }
}
