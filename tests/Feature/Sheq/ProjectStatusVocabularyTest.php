<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Http\Controllers\ContractorController;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SHEQ filters project statuses it does not own.
 *
 * `ContractorController::FINISHED_PROJECT_STATUSES` decides which projects a contractor's worker
 * can be placed on. The values are Project Management's, and SHEQ cannot reference that model
 * directly — Project Management is an entitlement and its classes are absent on an instance that
 * never licensed it, so an import would make the contractor screen fail to load there.
 *
 * That leaves a literal list in one module naming values owned by another. A rename on the Project
 * Management side would not break anything loudly: the filter would simply stop matching, finished
 * projects would reappear in the picker, and cards would go out naming work that ended last year.
 * This test is the only thing that would say so.
 */
class ProjectStatusVocabularyTest extends TestCase
{
    public function test_every_filtered_status_is_still_a_project_management_status(): void
    {
        $model = \App\Modules\Operations\ProjectManagement\Models\Project::class;

        if (! class_exists($model)) {
            $this->markTestSkipped('Project Management is not present in this installation.');
        }

        $known = $model::STATUSES;

        foreach ($this->filtered() as $status) {
            $this->assertContains($status, $known,
                "SHEQ filters project status '{$status}', which Project Management no longer has. "
                .'Finished projects are now being offered when placing a contractor worker. '
                .'Known statuses: '.implode(', ', $known));
        }
    }

    public function test_the_filter_does_not_hide_a_project_that_is_still_running(): void
    {
        /*
         * The other direction, and the one that would be noticed as a missing project rather than
         * as a wrong one. Crews mobilise before a project is formally active and stay through a
         * hold, so draft, active and on_hold all have to stay on the picker.
         */
        foreach (['draft', 'active', 'on_hold'] as $live) {
            $this->assertNotContains($live, $this->filtered(),
                "SHEQ treats '{$live}' as finished, so workers cannot be placed on projects that "
                .'are still running.');
        }
    }

    public function test_nothing_else_in_sheq_filters_project_status_on_its_own_literals(): void
    {
        /*
         * One list, or it is not a list. A second reader with its own hard-coded statuses is how
         * two screens come to disagree about which projects exist — and the one that is wrong is
         * whichever nobody has a test for.
         */
        $offenders = [];

        foreach (File::allFiles(app_path('Modules/Operations/Sheq')) as $file) {
            // The one file that is allowed to name them, because it is the one this test guards.
            if ($file->getFilename() === 'ContractorController.php') {
                continue;
            }

            $source = $file->getContents();

            if (! str_contains($source, 'pm_projects')) {
                continue;
            }

            foreach (['completed', 'cancelled', 'archived', 'on_hold'] as $status) {
                if (str_contains($source, "'{$status}'")) {
                    $offenders[] = $file->getRelativePathname().' names '.$status;
                }
            }
        }

        $this->assertSame([], $offenders,
            'These files read pm_projects and carry their own status literals. The statuses belong '
            ."in ContractorController::FINISHED_PROJECT_STATUSES, which this test guards:\n"
            .implode("\n", $offenders));
    }

    /** @return list<string> */
    private function filtered(): array
    {
        $reflection = new \ReflectionClass(ContractorController::class);

        return $reflection->getConstant('FINISHED_PROJECT_STATUSES');
    }
}
