<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Notifications\TasksDueNotification;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Tell people what they owe today, and what they already owed.
 *
 * `pm:alerts` warns whoever runs a project that deadlines are approaching. It says nothing to the
 * person actually holding the task — so a due date was a thing you found out about by opening the
 * board and looking, which is precisely what somebody who has forgotten a task does not do.
 *
 * One message per person per day, covering every task they hold. Somebody who has let a dozen
 * things slip is exactly the person who would otherwise receive a dozen emails, and a dozen emails
 * is how a mail rule gets written — after which the thirteenth, the one that mattered, is filed
 * unread with the rest.
 */
class SendTaskRemindersCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:task-reminders
        {--company= : One company. Omit to sweep every tenant.}
        {--dry : Report what would be sent without sending it.}';

    protected $description = 'Email and notify people about tasks due today and tasks already overdue.';

    public function handle(TenantContext $tenants): int
    {
        $dry = (bool) $this->option('dry');
        $sent = 0;

        $this->eachTenant($tenants, function () use ($dry, &$sent) {
            $today = now()->startOfDay();

            /*
             * Live work with a date on it. Archived is excluded along with done and cancelled: it
             * is off the board deliberately, and chasing somebody about a task that was shelved on
             * purpose is how a reminder loses its authority.
             */
            $tasks = Task::query()
                ->real()
                ->whereNotIn('status', ['done', 'cancelled', Task::STATUS_ARCHIVED])
                ->whereNotNull('planned_end')
                ->whereDate('planned_end', '<=', $today)
                ->with('project:id,name')
                ->get();

            if ($tasks->isEmpty()) {
                return;
            }

            // Who holds each task, in one query rather than one per task.
            $holders = TaskAssignment::whereIn('task_id', $tasks->pluck('id'))
                ->with('member:id,user_id')
                ->get()
                ->groupBy('task_id');

            /** @var array<int, array{due: list<array<string,mixed>>, overdue: list<array<string,mixed>>}> $forUser */
            $forUser = [];

            foreach ($tasks as $task) {
                $due = $task->planned_end->startOfDay();
                $isOverdue = $due->lt($today);

                $entry = [
                    'code'    => $task->code,
                    'name'    => $task->name,
                    'project' => $task->project?->name,
                    'due'     => $due->toDateString(),
                    'days'    => (int) $due->diffInDays($today),
                ];

                foreach ($this->recipientsFor($task, $holders) as $userId) {
                    $forUser[$userId][$isOverdue ? 'overdue' : 'due'][] = $entry;
                }
            }

            foreach ($forUser as $userId => $buckets) {
                $user = User::query()->find($userId);

                if (! $user) {
                    continue;
                }

                $due = $buckets['due'] ?? [];
                $overdue = $buckets['overdue'] ?? [];

                $this->line(sprintf(
                    '  %s — %d due today, %d overdue',
                    $user->email, count($due), count($overdue),
                ));

                if (! $dry) {
                    Notification::send($user, new TasksDueNotification($due, $overdue));
                }

                $sent++;
            }
        });

        $this->info($dry
            ? "Would notify {$sent} person(s)."
            : "Notified {$sent} person(s).");

        return self::SUCCESS;
    }

    /**
     * Who hears about a task.
     *
     * Whoever is on it; and where nobody is, whoever raised it. An unassigned task with a date that
     * has passed is still somebody's problem — usually the person who wrote it down — and saying
     * nothing about it is how it stays unassigned.
     *
     * @return list<int>
     */
    private function recipientsFor(Task $task, $holders): array
    {
        $users = collect($holders->get($task->id, collect()))
            ->map(fn (TaskAssignment $a) => $a->member?->user_id)
            ->filter()
            ->map(fn ($id) => (int) $id);

        if ($users->isEmpty() && $task->created_by) {
            $users = collect([(int) $task->created_by]);
        }

        return $users->unique()->values()->all();
    }
}
