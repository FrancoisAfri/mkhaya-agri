<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\TaskImportService;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Creating a project's tasks from a spreadsheet.
 *
 * The plan usually exists already, in a file somebody built before the project started. Retyping it
 * a row at a time is how most of it never reaches the board at all.
 *
 * What these hold down is the behaviour around the happy path: that one bad row does not cost the
 * other hundred and ninety-nine, that the reason comes back attached to the line number the user
 * can see in their own file, and that an imported task arrives as work not yet started.
 */
class TaskImportTest extends TestCase
{
    use DatabaseTransactions;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = (int) (DB::table('companies')->min('id')
            ?? Company::create(['name' => 'Import Test Co'])->id);

        /*
         * Both halves of the tenant context, not just the database one.
         *
         * PM's AuditLogger stamps the audit row's company_id from CurrentCompany, so setting only
         * the Postgres session variable produced a null company_id that row-level security then
         * refused — the task saved and its audit entry did not.
         */
        app(CurrentCompany::class)->set($companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $calendarId = DB::table('pm_calendars')->value('id') ?? DB::table('pm_calendars')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
            'name' => 'Standard', 'timezone' => 'Africa/Johannesburg',
            'work_week' => json_encode([1, 2, 3, 4, 5]), 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('pm_projects')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
            'code' => 'IMP' . random_int(100, 999), 'name' => 'Import target',
            'lens_code' => 'generic', 'status' => 'active', 'stage' => 'delivery',
            'priority' => 'medium', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
            'is_billable' => true, 'timesheet_approval_required' => false,
            'calendar_id' => $calendarId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->project = Project::findOrFail($id);
    }

    /** A CSV on disk, the way an upload arrives. */
    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tasks') . '.csv';
        file_put_contents($path, "name,description,start_date,deadline,priority,estimated_hours,is_milestone\n" . $body);

        return new UploadedFile($path, 'tasks.csv', 'text/csv', null, true);
    }

    private function importer(): TaskImportService
    {
        return app(TaskImportService::class);
    }

    private function tasks()
    {
        return Task::where('project_id', $this->project->id);
    }

    // ── The point of the feature ─────────────────────────────────────────────

    public function test_each_row_becomes_a_not_started_task_with_its_deadline(): void
    {
        $file = $this->csv(
            "Pour foundation,,2026-10-01,2026-10-15,high,24,no\n" .
            "Erect frame,Steel delivered on site,2026-10-16,2026-10-30,medium,40,no\n"
        );

        $summary = $this->importer()->import($file, $this->project);

        $this->assertSame(2, $summary['imported']);
        $this->assertSame([], $summary['skipped']);

        $task = $this->tasks()->where('name', 'Pour foundation')->firstOrFail();
        $this->assertSame('not_started', $task->status);
        $this->assertSame('high', $task->priority);
        $this->assertSame('2026-10-15', $task->planned_end->toDateString());
        $this->assertSame('2026-10-01', $task->planned_start->toDateString());
        $this->assertEqualsWithDelta(24.0, (float) $task->estimated_hours, 0.01);
    }

    public function test_only_the_name_is_required(): void
    {
        // A list of task names with no dates at all is a perfectly ordinary starting point, and
        // refusing it would push the user back to typing them in one at a time.
        $summary = $this->importer()->import($this->csv("Just a name,,,,,,\n"), $this->project);

        $this->assertSame(1, $summary['imported']);
        $this->assertNull($this->tasks()->where('name', 'Just a name')->firstOrFail()->planned_end);
    }

    public function test_every_task_gets_its_own_code(): void
    {
        // Codes come from TaskService rather than the file, which is why the import goes through it.
        $this->importer()->import($this->csv("One,,,,,,\nTwo,,,,,,\nThree,,,,,,\n"), $this->project);

        $codes = $this->tasks()->pluck('code');

        $this->assertCount(3, $codes);
        $this->assertCount(3, $codes->unique(), 'Task codes must be unique within a project.');
    }

    // ── One bad row does not cost the rest ───────────────────────────────────

    public function test_a_bad_row_is_reported_and_the_good_rows_still_land(): void
    {
        $summary = $this->importer()->import($this->csv(
            "Good one,,,2026-10-15,,,\n" .
            ",,,2026-10-16,,,\n" .                       // no name
            "Good two,,,2026-10-17,,,\n"
        ), $this->project);

        $this->assertSame(2, $summary['imported']);
        $this->assertCount(1, $summary['skipped']);
        $this->assertSame(3, $summary['skipped'][0]['row'], 'The line number must match the spreadsheet.');
        $this->assertStringContainsString('name', strtolower($summary['skipped'][0]['reason']));
    }

    public function test_an_unreadable_date_names_the_row_rather_than_failing_the_file(): void
    {
        $summary = $this->importer()->import($this->csv(
            "Fine,,,2026-10-15,,,\n" .
            "Bad date,,,not-a-date,,,\n"
        ), $this->project);

        $this->assertSame(1, $summary['imported']);
        $this->assertSame('Bad date', $summary['skipped'][0]['name']);
        $this->assertStringContainsString('date', strtolower($summary['skipped'][0]['reason']));
    }

    public function test_a_deadline_before_the_start_is_refused_for_that_row_only(): void
    {
        $summary = $this->importer()->import($this->csv(
            "Backwards,,2026-10-20,2026-10-01,,,\n" .
            "Forwards,,2026-10-01,2026-10-20,,,\n"
        ), $this->project);

        $this->assertSame(1, $summary['imported']);
        $this->assertCount(1, $summary['skipped']);
        $this->assertSame('Backwards', $summary['skipped'][0]['name']);
    }

    public function test_an_unknown_priority_is_refused_rather_than_quietly_downgraded(): void
    {
        // Silently substituting 'medium' would mean a file full of 'Urgent' imports as ordinary
        // work, and nothing on screen would say the priorities had been thrown away.
        $summary = $this->importer()->import($this->csv("Task,,,,urgent,,\n"), $this->project);

        $this->assertSame(0, $summary['imported']);
        $this->assertStringContainsString('urgent', $summary['skipped'][0]['reason']);
    }

    public function test_priority_defaults_to_medium_when_the_column_is_left_empty(): void
    {
        $this->importer()->import($this->csv("No priority,,,,,,\n"), $this->project);

        $this->assertSame('medium', $this->tasks()->where('name', 'No priority')->firstOrFail()->priority);
    }

    public function test_trailing_blank_rows_are_not_reported_as_errors(): void
    {
        // Every spreadsheet ends in empty rows. Reporting them makes a clean import look broken.
        $summary = $this->importer()->import($this->csv("Only task,,,,,,\n,,,,,,\n,,,,,,\n"), $this->project);

        $this->assertSame(1, $summary['imported']);
        $this->assertSame([], $summary['skipped']);
    }

    // ── The template ─────────────────────────────────────────────────────────

    public function test_the_template_carries_the_headers_the_importer_reads(): void
    {
        // If these drift apart, every column in a downloaded-and-filled template is ignored and the
        // user gets a file full of nameless rows with no idea why.
        $csv = $this->importer()->templateCsv();
        $header = str_getcsv(strtok($csv, "\n"));

        $this->assertSame(TaskImportService::TEMPLATE_HEADERS, $header);
    }

    public function test_a_filled_in_template_imports_cleanly_once_the_example_row_is_removed(): void
    {
        // The round trip the user actually performs, checked end to end.
        $header = implode(',', TaskImportService::TEMPLATE_HEADERS);
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.csv';
        file_put_contents($path, $header . "\nReal task,,2026-11-01,2026-11-30,low,4,yes\n");

        $summary = $this->importer()->import(
            new UploadedFile($path, 'filled.csv', 'text/csv', null, true),
            $this->project,
        );

        $this->assertSame(1, $summary['imported']);
        $task = $this->tasks()->where('name', 'Real task')->firstOrFail();
        $this->assertTrue($task->is_milestone);
        $this->assertSame('low', $task->priority);
    }

    public function test_a_file_beyond_the_row_cap_imports_what_it_can_and_says_so(): void
    {
        $rows = str_repeat("Task,,,,,,\n", TaskImportService::MAX_ROWS + 5);

        $summary = $this->importer()->import($this->csv($rows), $this->project);

        $this->assertTrue($summary['truncated']);
        $this->assertSame(TaskImportService::MAX_ROWS, $summary['imported']);
    }
}
