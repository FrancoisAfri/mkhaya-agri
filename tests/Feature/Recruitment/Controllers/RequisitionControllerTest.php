<?php

namespace Tests\Feature\Recruitment\Controllers;

use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Enums\VacancyType;
use App\Modules\HR\Recruitment\Models\Requisition;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Recruitment\RecruitmentTestCase;

/**
 * Requisitions are standalone in the current app (no longer job-scoped): CRUD lives
 * under recruitment.requisitions.* and approvals under recruitment.approvals.requisitions.*.
 */
class RequisitionControllerTest extends RecruitmentTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_requisitions(): void
    {
        Requisition::factory()->count(3)->create();

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.requisitions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Requisitions/Index')
                ->has('requisitions.data', 3));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_requisition(): void
    {
        $this->actingAs($this->recruiter)
            ->post(route('recruitment.requisitions.store'), [
                'title'             => 'Backend Engineer',
                'department'        => 'Engineering',
                'job_type'          => VacancyType::Permanent->value,
                'headcount'         => 2,
                'reason'            => 'Team expansion.',
                'target_start_date' => now()->addMonths(3)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_requisitions', [
            'title'        => 'Backend Engineer',
            'requested_by' => $this->recruiter->id,
            'headcount'    => 2,
        ]);
    }

    public function test_store_defaults_to_draft_status(): void
    {
        $this->actingAs($this->recruiter)
            ->post(route('recruitment.requisitions.store'), [
                'title'             => 'Replacement Hire',
                'job_type'          => VacancyType::Permanent->value,
                'headcount'         => 1,
                'reason'            => 'Replacement hire.',
                'target_start_date' => now()->addMonths(2)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_requisitions', [
            'title'  => 'Replacement Hire',
            'status' => RequisitionStatus::Draft->value,
        ]);
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function test_submit_moves_requisition_to_pending_approval(): void
    {
        $requisition = Requisition::factory()->create([
            'requested_by' => $this->recruiter->id,
        ]);

        $this->actingAs($this->recruiter)
            ->post(route('recruitment.requisitions.submit', $requisition))
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_requisitions', [
            'id'     => $requisition->id,
            'status' => RequisitionStatus::PendingApproval->value,
        ]);
    }

    // ── approve ───────────────────────────────────────────────────────────────

    public function test_approve_approves_pending_requisition(): void
    {
        $requisition = Requisition::factory()
            ->pending()
            ->create(['requested_by' => $this->recruiter->id]);

        $this->actingAs($this->hrManager)
            ->post(route('recruitment.approvals.requisitions.approve', $requisition))
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_requisitions', [
            'id'     => $requisition->id,
            'status' => RequisitionStatus::Approved->value,
        ]);
    }

    /**
     * The approval action guards against approving a requisition that is not
     * pending. (NOTE: unlike the shortlist flow, the standalone requisition
     * approval action does NOT enforce a self-approval / four-eyes rule.)
     */
    public function test_approve_rejects_non_pending_requisition(): void
    {
        $requisition = Requisition::factory()
            ->create(['requested_by' => $this->recruiter->id]); // draft, not pending

        $this->actingAs($this->hrManager)
            ->from(route('recruitment.requisitions.show', $requisition))
            ->post(route('recruitment.approvals.requisitions.approve', $requisition))
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('recruitment_requisitions', [
            'id'     => $requisition->id,
            'status' => RequisitionStatus::Draft->value,
        ]);
    }

    // ── reject ────────────────────────────────────────────────────────────────

    public function test_reject_requires_reason(): void
    {
        $requisition = Requisition::factory()
            ->pending()
            ->create(['requested_by' => $this->recruiter->id]);

        $this->actingAs($this->hrManager)
            ->from(route('recruitment.requisitions.show', $requisition))
            ->post(route('recruitment.approvals.requisitions.reject', $requisition), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['reason']);
    }

    // ── route-model binding ─────────────────────────────────────────────────────

    public function test_returns_404_for_unknown_requisition(): void
    {
        $this->actingAs($this->hrManager)
            ->post(route('recruitment.approvals.requisitions.approve', '00000000-0000-0000-0000-000000000000'))
            ->assertNotFound();
    }
}
