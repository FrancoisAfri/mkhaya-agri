<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use App\Modules\Tenancy\Models\Company;

/**
 * The factories do what a fixture needs, including the parts that are easy to get wrong.
 *
 * Worth testing rather than trusting: a factory that silently produces a project with no
 * company_id, or one that creates a calendar per call, is the kind of thing that only shows up as
 * a confusing failure in some unrelated suite six weeks later.
 */
class FactoryTest extends ProjectManagementTestCase
{
    public function test_a_project_is_created_inside_the_active_tenant(): void
    {
        $company = Company::create(['name' => 'Factory Co']);

        $project = $this->asCompany($company, fn () => Project::factory()->create());

        $this->assertSame($company->id, $project->company_id);
        $this->assertNotNull($project->uuid);
        $this->assertNotNull($project->calendar_id);
    }

    /** Money is opt-in: a default budget would let a rounding assertion pass for the wrong reason. */
    public function test_a_project_has_no_budget_unless_asked_for_one(): void
    {
        $company = Company::create(['name' => 'Budget Co']);

        [$plain, $funded] = $this->asCompany($company, fn () => [
            Project::factory()->create(),
            Project::factory()->withBudget('250000.00')->create(),
        ]);

        $this->assertNull($plain->budget_amount);
        $this->assertSame('250000.00', $funded->budget_amount);
        $this->assertTrue($funded->is_billable);
    }

    public function test_many_projects_share_one_calendar(): void
    {
        $company = Company::create(['name' => 'Calendar Co']);

        $this->asCompany($company, function () {
            Project::factory()->count(3)->create();

            $this->assertSame(
                1,
                WorkCalendar::count(),
                'A calendar per project makes large fixtures slow for no extra coverage.',
            );
        });
    }

    public function test_a_member_has_no_login_by_default(): void
    {
        $company = Company::create(['name' => 'Member Co']);

        $member = $this->asCompany($company, fn () => ProjectMember::factory()->contractor()->create());

        $this->assertNull($member->user_id, 'A member is a person on the job, not an account.');
        $this->assertSame('contractor', $member->role_key);
        $this->assertSame($company->id, $member->company_id);
    }
}
