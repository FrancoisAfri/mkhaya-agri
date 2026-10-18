<?php

namespace Tests\Feature\ProjectManagement;

/**
 * The field shell (EPIC-060).
 *
 * Almost nothing to test server-side, and that is the design: the page renders from IndexedDB so it
 * looks the same with no signal as with. What the server still owes is the door — someone with no
 * task grants at all has no business being handed the shell.
 */
class FieldShellTest extends ProjectManagementTestCase
{
    public function test_the_shell_renders_without_any_data(): void
    {
        // No projects, no tasks: a phone that has never synced must still get a page rather than an
        // error, because that is exactly the state of a new device in a yard.
        $this->actingAs($this->manager)
            ->get(route('project-management.field'))
            ->assertOk();
    }

    public function test_someone_with_no_task_grants_is_refused(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('project-management.field'))
            ->assertForbidden();
    }
}
