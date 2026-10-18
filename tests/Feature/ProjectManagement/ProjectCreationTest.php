<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\ProjectService;

/**
 * The create form's contract: the project code is offered up front and stays editable, and the
 * currency is one of the ISO-4217 codes rather than any three characters.
 */
class ProjectCreationTest extends ProjectManagementTestCase
{
    public function test_the_form_is_given_the_next_code_to_show(): void
    {
        $this->makeProject(['code' => 'PRJ-0001']);

        $response = $this->actingAs($this->manager)->get(route('project-management.projects.index'));

        $response->assertOk();
        // Advisory, and it must not collide with what is already there.
        $response->assertInertia(fn ($page) => $page->where('nextCode', 'PRJ-0002'));
    }

    public function test_a_blank_code_is_generated_server_side(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'No code supplied', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
            ])
            ->assertRedirect();

        $this->assertSame('PRJ-0001', Project::firstOrFail()->code);
    }

    public function test_a_user_supplied_code_is_kept_verbatim(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'Own code', 'code' => 'SITE-42/A', 'currency' => 'ZAR',
                'timezone' => 'Africa/Johannesburg',
            ])
            ->assertRedirect();

        $this->assertSame('SITE-42/A', Project::firstOrFail()->code);
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        $this->makeProject(['code' => 'PRJ-0001']);

        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'Clash', 'code' => 'PRJ-0001', 'currency' => 'ZAR',
                'timezone' => 'Africa/Johannesburg',
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_a_code_freed_by_deletion_can_be_reused(): void
    {
        $project = $this->makeProject(['code' => 'PRJ-0001']);
        $project->delete();

        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'Reuse', 'code' => 'PRJ-0001', 'currency' => 'ZAR',
                'timezone' => 'Africa/Johannesburg',
            ])
            ->assertRedirect();

        $this->assertSame(1, Project::where('code', 'PRJ-0001')->count());
    }

    public function test_the_next_code_skips_past_deleted_projects(): void
    {
        // Soft-deleted rows still hold their number in sequence, so a restore cannot collide.
        $this->makeProject(['code' => 'PRJ-0007'])->delete();

        $this->assertSame('PRJ-0008', app(ProjectService::class)->nextCode());
    }

    public function test_an_unknown_currency_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'Bad money', 'currency' => 'XYZ', 'timezone' => 'Africa/Johannesburg',
            ])
            ->assertSessionHasErrors('currency');

        $this->assertSame(0, Project::count());
    }

    public function test_any_iso_currency_is_accepted(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'Kenyan job', 'currency' => 'KES', 'timezone' => 'Africa/Nairobi',
            ])
            ->assertRedirect();

        $this->assertSame('KES', Project::firstOrFail()->currency);
    }

    public function test_the_form_receives_the_full_currency_list(): void
    {
        $response = $this->actingAs($this->manager)->get(route('project-management.projects.index'));

        $currencies = $response->viewData('page')['props']['currencies'];

        // ZAR leads because it is the home currency, and the long tail is all there.
        $this->assertSame('ZAR', $currencies[0]['code']);
        $this->assertGreaterThan(100, count($currencies));
        $this->assertContains('KES', array_column($currencies, 'code'));
    }
}
