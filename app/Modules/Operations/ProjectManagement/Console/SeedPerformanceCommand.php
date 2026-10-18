<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds a tenant big enough to be honest about (PM-1800).
 *
 * The performance budgets in the brief — 20,000 tasks scheduled in five seconds, a 500-record
 * evidence batch in two — mean nothing measured against the twelve rows a feature test creates.
 * This produces a tenant at production scale so those numbers can be taken rather than assumed.
 *
 * Deliberately a command rather than a seeder: it is slow, it is destructive of nothing, and it
 * should never be something `migrate --seed` runs by accident on a developer's laptop.
 */
class SeedPerformanceCommand extends Command
{
    protected $signature = 'pm:seed-performance
                            {--company= : The tenant to fill (required)}
                            {--projects=20 : How many projects}
                            {--tasks=500 : Tasks per project}
                            {--evidence=10 : Evidence events per task}';

    protected $description = 'Fill a tenant with synthetic project data for performance measurement';

    public function handle(TenantContext $tenants): int
    {
        $companyId = $this->option('company');

        if (! $companyId) {
            $this->error('Pass --company. This writes a great deal of data and should never guess which tenant.');

            return self::FAILURE;
        }

        $projects = (int) $this->option('projects');
        $tasksEach = (int) $this->option('tasks');
        $evidenceEach = (int) $this->option('evidence');

        $this->info(sprintf(
            'Seeding %s projects × %s tasks × %s evidence = %s tasks, %s events.',
            number_format($projects), number_format($tasksEach), number_format($evidenceEach),
            number_format($projects * $tasksEach), number_format($projects * $tasksEach * $evidenceEach),
        ));

        $started = microtime(true);

        $tenants->run((int) $companyId, function () use ($projects, $tasksEach, $evidenceEach, $companyId) {
            $calendar = WorkCalendar::firstOrCreate(
                ['name' => 'Performance calendar'],
                ['timezone' => 'Africa/Johannesburg', 'work_week' => WorkCalendar::STANDARD_WEEK]
            );

            $bar = $this->output->createProgressBar($projects);

            for ($p = 1; $p <= $projects; $p++) {
                $project = Project::create([
                    'code'          => 'PERF-' . str_pad((string) $p, 5, '0', STR_PAD_LEFT),
                    'name'          => "Performance project {$p}",
                    'currency'      => 'ZAR',
                    'timezone'      => 'Africa/Johannesburg',
                    'calendar_id'   => $calendar->id,
                    'status'        => 'active',
                    'stage'         => 'execution',
                    'planned_start' => now()->subMonths(3),
                    'budget_amount' => 5_000_000,
                ]);

                $this->seedTasks($project, $tasksEach, $evidenceEach, (int) $companyId);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        });

        $this->info(sprintf('Done in %.1fs.', microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * Raw inserts, in chunks.
     *
     * Eloquent would fire model events, audit rows and outbox messages for every row — correct for
     * real work, and completely wrong here: it would make the seeder measure Eloquent rather than
     * fill a database, and take hours doing it.
     */
    private function seedTasks(Project $project, int $count, int $evidenceEach, int $companyId): void
    {
        $now = now();
        $tasks = [];

        for ($i = 1; $i <= $count; $i++) {
            $start = $now->copy()->subMonths(3)->addDays(intdiv($i, 5));

            $tasks[] = [
                'uuid'             => (string) Str::uuid(),
                'company_id'       => $companyId,
                'project_id'       => $project->id,
                'code'             => 'T-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'name'             => "Task {$i}",
                'status'           => ['not_started', 'in_progress', 'done'][$i % 3],
                'priority'         => 'medium',
                'schedule_mode'    => 'auto',
                'duration_minutes' => 480,
                'planned_start'    => $start,
                'planned_end'      => $start->copy()->addDays(2),
                'progress_pct'     => 0,
                'progress_source'  => 'derived',
                'planned_quantity' => 100,
                'currency'         => 'ZAR',
                'is_milestone'     => false,
                'is_recurrence_template' => false,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            if (count($tasks) >= 500) {
                DB::table('pm_tasks')->insert($tasks);
                $tasks = [];
            }
        }

        if ($tasks !== []) {
            DB::table('pm_tasks')->insert($tasks);
        }

        if ($evidenceEach > 0) {
            $this->seedEvidence($project, $evidenceEach, $companyId);
        }
    }

    /**
     * Evidence without the hash chain.
     *
     * The chain is verified by its own tests and costs a lock and a hash per row; taking it here
     * would make seeding a million events take a day. What this fills is volume — the thing the
     * read paths and the partition strategy have to survive — and the rows are marked so nobody
     * mistakes them for real evidence.
     */
    private function seedEvidence(Project $project, int $perTask, int $companyId): void
    {
        $taskIds = DB::table('pm_tasks')->where('project_id', $project->id)->pluck('id', 'uuid');
        $now = now();
        $rows = [];

        foreach ($taskIds as $uuid => $id) {
            for ($e = 0; $e < $perTask; $e++) {
                $rows[] = [
                    'uuid'          => (string) Str::uuid(),
                    'company_id'    => $companyId,
                    'project_id'    => $project->id,
                    'subject_type'  => 'task',
                    'subject_id'    => $id,
                    'subject_uuid'  => $uuid,
                    'event_type'    => 'quantity.installed',
                    'source_module' => 'performance-seed',
                    'occurred_at'   => $now->copy()->subDays($e),
                    'occurred_tz'   => 'Africa/Johannesburg',
                    'recorded_at'   => $now,
                    'actor_type'    => 'system',
                    'quantity'      => 5,
                    'unit'          => 'm²',
                    'confidence'    => 1.0,
                    'payload'       => json_encode(['quantity' => 5, 'seeded' => true]),
                    'attachments'   => json_encode([]),
                    'hash'          => str_repeat('0', 64),
                    'hash_version'  => 'seed',
                ];

                if (count($rows) >= 1000) {
                    DB::table('pm_evidence_events')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('pm_evidence_events')->insert($rows);
        }
    }
}
