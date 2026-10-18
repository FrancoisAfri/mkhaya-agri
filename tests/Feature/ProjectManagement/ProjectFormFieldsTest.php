<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;

/**
 * The fields the forms send must actually be accepted and stored.
 *
 * These exist because a form audit found several keys being posted with no control to fill them
 * (location, billable, priority on edit, evidence confidence) and one prop passed to a page and
 * never used (calendars). A payload key with nothing behind it is invisible until someone wonders
 * why a value never saves.
 */
class ProjectFormFieldsTest extends ProjectManagementTestCase
{
    public function test_the_create_form_options_are_all_provided(): void
    {
        $props = $this->actingAs($this->manager)
            ->get(route('project-management.projects.index'))
            ->assertOk()
            ->viewData('page')['props'];

        // Each of these backs a picker in the dialog; a missing one leaves a field unusable.
        $this->assertNotEmpty($props['currencies']);
        $this->assertNotEmpty($props['timezones']);
        $this->assertArrayHasKey('calendars', $props);
        $this->assertSame('Africa/Johannesburg (+02:00)',
            collect($props['timezones'])->firstWhere('value', 'Africa/Johannesburg')['title']);
    }

    public function test_location_and_billable_are_stored(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'Site works', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
                'location_name' => 'Erf 1234, Sandton', 'is_billable' => false,
            ])
            ->assertRedirect();

        $project = Project::firstOrFail();

        $this->assertSame('Erf 1234, Sandton', $project->location_name);
        $this->assertFalse($project->is_billable);
    }

    public function test_a_calendar_is_chosen_by_its_public_id(): void
    {
        $calendar = WorkCalendar::create([
            'name' => 'Night shift', 'timezone' => 'Africa/Johannesburg',
            'work_week' => WorkCalendar::STANDARD_WEEK,
        ]);

        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'Nights', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
                // The form never learns an internal id (PM-ADR-003), so it sends the uuid.
                'calendar_uuid' => $calendar->uuid,
            ])
            ->assertRedirect();

        $this->assertSame($calendar->id, Project::firstOrFail()->calendar_id);
    }

    public function test_an_unknown_calendar_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'Bad calendar', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
                'calendar_uuid' => '019f0000-0000-7000-8000-0000000000ff',
            ])
            ->assertSessionHasErrors('calendar_uuid');
    }

    public function test_priority_can_be_changed_after_creation(): void
    {
        $project = $this->makeProject(['priority' => 'medium']);

        // The edit dialog offered no priority control at all until the audit; the payload was
        // accepted but nothing could send it.
        $this->actingAs($this->manager)
            ->put(route('project-management.projects.update', $project->uuid), [
                'name' => $project->name, 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
                'priority' => 'critical',
            ])
            ->assertRedirect();

        $this->assertSame('critical', $project->fresh()->priority);
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'Bad zone', 'currency' => 'ZAR', 'timezone' => 'Mars/Olympus',
            ])
            ->assertSessionHasErrors('timezone');
    }

    // ── Calendars ────────────────────────────────────────────────────────────

    public function test_a_calendar_can_be_created_with_a_working_week(): void
    {
        $week = [
            'mon' => [['07:00', '16:00']], 'tue' => [['07:00', '16:00']], 'wed' => [['07:00', '16:00']],
            'thu' => [['07:00', '16:00']], 'fri' => [['07:00', '13:00']], 'sat' => [], 'sun' => [],
        ];

        $this->actingAs($this->admin())
            ->post(route('project-management.setup.calendars.store'), [
                'name' => 'Site hours', 'timezone' => 'Africa/Johannesburg', 'work_week' => $week,
            ])
            ->assertRedirect();

        $calendar = WorkCalendar::where('name', 'Site hours')->firstOrFail();

        $this->assertSame([['07:00', '13:00']], $calendar->work_week['fri']);
        $this->assertSame([], $calendar->work_week['sat']);
    }

    public function test_a_calendars_hours_can_be_corrected(): void
    {
        $calendar = WorkCalendar::create([
            'name' => 'Site hours', 'timezone' => 'Africa/Johannesburg',
            'work_week' => WorkCalendar::STANDARD_WEEK,
        ]);

        // Every date the scheduler computes depends on these hours, so being unable to correct them
        // would mean abandoning the calendar and repointing every project at a new one.
        $this->actingAs($this->admin())
            ->patch(route('project-management.setup.calendars.update', $calendar->uuid), [
                'name' => 'Site hours', 'timezone' => 'Africa/Johannesburg',
                'work_week' => ['mon' => [['06:00', '15:00']], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []],
            ])
            ->assertRedirect();

        $this->assertSame([['06:00', '15:00']], $calendar->fresh()->work_week['mon']);
    }

    public function test_a_malformed_time_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('project-management.setup.calendars.store'), [
                'name' => 'Bad hours', 'timezone' => 'Africa/Johannesburg',
                'work_week' => ['mon' => [['25:00', '16:00']]],
            ])
            ->assertSessionHasErrors();
    }

    /** Calendars are an ADMIN-tier settings area, so the test actor needs the settings grant. */
    private function admin(): \App\Core\User\Models\User
    {
        $role = \App\Core\Role\Models\Role::firstOrCreate(['name' => 'pm test admin', 'guard_name' => 'web']);
        $role->syncPermissions([\App\Modules\Operations\ProjectManagement\Support\RoleCatalog::MANAGE_SETTINGS]);

        $user = $this->makeUser('pm-settings@example.test', $role);
        $this->grantModuleTier($user);

        return $user;
    }
}
