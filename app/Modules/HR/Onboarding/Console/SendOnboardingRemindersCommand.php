<?php

namespace App\Modules\HR\Onboarding\Console;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use App\Modules\HR\Onboarding\Models\ProbationPeriod;
use App\Modules\HR\Onboarding\Notifications\OnboardingReminderNotification;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * The nudges that make onboarding actually happen.
 *
 * The module had no scheduled work at all: plans were created with due dates and then nothing ever
 * looked at them again. Onboarding is deadline-driven and mostly performed by people for whom it is
 * not the main job — an IT admin owes a laptop, a manager owes a one-to-one — so a plan with no
 * chaser quietly rots and is discovered at the 90-day review.
 *
 * Three things are chased, because they fail differently:
 *   • overdue tasks           — somebody was asked and has not done it
 *   • a start date approaching — the work has to happen *before* day one to be worth anything
 *   • probation reviews due   — the one with legal consequences for missing it
 */
class SendOnboardingRemindersCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'boarding:reminders
        {--company= : One company id, instead of every tenant}
        {--days=7 : How far ahead to look for starts and probation reviews}';

    protected $description = 'Chase overdue onboarding tasks, upcoming start dates and probation reviews due';

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;

        // Per tenant: the recipients, the plans and the notification rows all belong to one company,
        // and with no company on the session the RLS policy matches every row on the platform.
        $this->eachTenant($tenants, function () use (&$sent) {
            $sent += $this->chaseOverdueTasks();
            $sent += $this->chaseUpcomingStarts();
            $sent += $this->chaseProbationReviews();
        });

        $this->info("Onboarding reminders sent: {$sent}");

        return self::SUCCESS;
    }

    /**
     * Tasks past their due date that nobody has closed.
     *
     * Grouped by assignee and sent as one message. A person who owes eleven things should get one
     * reminder listing eleven, not eleven reminders — the second kind trains people to filter you.
     */
    private function chaseOverdueTasks(): int
    {
        $overdue = OnboardingTask::query()
            ->whereNotNull('assigned_to')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereHas('plan', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->groupBy('assigned_to');

        $sent = 0;

        foreach ($overdue as $userId => $tasks) {
            $user = User::find($userId);

            if (! $user) {
                continue;
            }

            Notification::send($user, new OnboardingReminderNotification(
                kind: 'overdue_tasks',
                count: $tasks->count(),
                items: $tasks->take(10)->map(fn (OnboardingTask $t) => [
                    'title' => $t->title,
                    'due' => $t->due_date?->toDateString(),
                ])->all(),
            ));

            $sent++;
        }

        return $sent;
    }

    /**
     * Plans whose start date is close.
     *
     * Sent to whoever created the plan: before day one there is often no assignee on the individual
     * tasks yet, and a reminder addressed to nobody is not a reminder.
     */
    private function chaseUpcomingStarts(): int
    {
        $days = (int) $this->option('days');

        $starting = OnboardingPlan::query()
            ->where('status', 'active')
            ->whereBetween('start_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->with('hrPerson')
            ->get()
            ->groupBy('created_by');

        $sent = 0;

        foreach ($starting as $userId => $plans) {
            $user = User::find($userId);

            if (! $user) {
                continue;
            }

            Notification::send($user, new OnboardingReminderNotification(
                kind: 'upcoming_starts',
                count: $plans->count(),
                items: $plans->map(fn (OnboardingPlan $p) => [
                    'title' => $p->hrPerson?->fullName() ?: 'New hire',
                    'due' => $p->start_date?->toDateString(),
                ])->all(),
            ));

            $sent++;
        }

        return $sent;
    }

    /**
     * Probation reviews falling due.
     *
     * The one with consequences: an unreviewed probation period that simply lapses generally means
     * the employee is confirmed by default, which is not a decision anybody intended to make.
     */
    private function chaseProbationReviews(): int
    {
        $days = (int) $this->option('days');

        if (! \Illuminate\Support\Facades\Schema::hasTable('probation_periods')) {
            return 0;
        }

        $due = ProbationPeriod::query()
            ->where('status', 'active')
            ->whereNotNull('next_review_date')
            ->whereBetween('next_review_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->with('hrPerson')
            ->get()
            ->groupBy('created_by');

        $sent = 0;

        foreach ($due as $userId => $periods) {
            $user = User::find($userId);

            if (! $user) {
                continue;
            }

            Notification::send($user, new OnboardingReminderNotification(
                kind: 'probation_reviews',
                count: $periods->count(),
                items: $periods->map(fn (ProbationPeriod $p) => [
                    'title' => $p->hrPerson?->fullName() ?: 'Employee',
                    'due' => $p->next_review_date?->toDateString(),
                ])->all(),
            ));

            $sent++;
        }

        return $sent;
    }
}
