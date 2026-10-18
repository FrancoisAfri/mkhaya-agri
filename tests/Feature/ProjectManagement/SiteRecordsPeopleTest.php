<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;

/**
 * The people offered on the site-records forms.
 *
 * Every name on that page — who issued the instruction, who inspected, who signed for the plant, who
 * certified completion — is stored as free text, because these are routinely signed by people with
 * no login here: the engineer, the client's representative, the lab technician. That is why the
 * fields accept a typed name, and why they must also offer the team: typing every name by hand is
 * how one foreman ends up in the register under four spellings and the register stops being
 * filterable by who did the work.
 *
 * This covers the half that can break silently. If the payload stops arriving the pickers are simply
 * empty, the page looks fine, and everyone goes back to typing.
 */
class SiteRecordsPeopleTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
    }

    private function props(): array
    {
        return $this->get(route('project-management.site.records', $this->project->uuid))
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_the_page_offers_the_project_team_to_name(): void
    {
        ProjectMember::create([
            'project_id'   => $this->project->id,
            'display_name' => 'Sipho Ndlovu',
            'role_key'     => 'site_agent',
            'is_active'    => true,
        ]);

        $people = $this->actingAs($this->manager)->props()['people'];

        $this->assertCount(1, $people);
        $this->assertSame('Sipho Ndlovu', $people[0]['name']);
        // The role is what tells two similar names apart on a busy project.
        $this->assertSame('site_agent', $people[0]['role']);
    }

    /**
     * A member with no login still appears.
     *
     * `ProjectMember.user_id` is nullable precisely so a subcontractor's foreman can be on the team,
     * and those are the people who sign for plant and witness tests. Building the list from users
     * instead of members would have left exactly the wrong half out.
     */
    public function test_a_team_member_without_a_login_is_still_offered(): void
    {
        ProjectMember::create([
            'project_id'   => $this->project->id,
            'display_name' => 'Themba Mokoena',
            'role_key'     => 'foreman',
            'user_id'      => null,
            'is_active'    => true,
        ]);

        $names = array_column($this->actingAs($this->manager)->props()['people'], 'name');

        $this->assertContains('Themba Mokoena', $names);
    }

    public function test_people_who_have_left_the_project_are_not_offered(): void
    {
        ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Current', 'role_key' => 'engineer', 'is_active' => true,
        ]);
        ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Departed', 'role_key' => 'engineer', 'is_active' => false,
        ]);

        $names = array_column($this->actingAs($this->manager)->props()['people'], 'name');

        $this->assertContains('Current', $names);
        $this->assertNotContains('Departed', $names);
    }

    /** Another project's team is not this project's team. */
    public function test_the_list_does_not_leak_across_projects(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);

        ProjectMember::create([
            'project_id' => $other->id, 'display_name' => 'Someone Else', 'role_key' => 'engineer', 'is_active' => true,
        ]);

        $names = array_column($this->actingAs($this->manager)->props()['people'], 'name');

        $this->assertNotContains('Someone Else', $names);
    }

    /**
     * A name that is not on the team is still accepted.
     *
     * The whole reason these fields are comboboxes rather than selects. A site agent standing in
     * front of a signed instruction from the engineer must be able to record who signed it.
     */
    public function test_an_instruction_can_be_issued_by_somebody_outside_the_team(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.instructions.store', $this->project->uuid), [
                'issued_on'   => '2026-08-11',
                'issued_by'   => 'R. Naidoo (Consulting Engineer)',
                'title'       => 'Additional rebar to pile caps',
                'instruction' => 'Increase reinforcement as per revised drawing.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('pm_site_instructions', [
            'project_id' => $this->project->id,
            'issued_by'  => 'R. Naidoo (Consulting Engineer)',
        ]);
    }
}
