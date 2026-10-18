<?php

namespace App\Modules\HR\LMS\Services;

use App\Core\User\Models\User;
use Spatie\Permission\Models\Role;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsOnboardingTrainingAssignment;
use App\Modules\HR\LMS\Models\LmsOnboardingTrainingRule;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\HR\LMS\Notifications\OnboardingTrainingIncompleteNotification;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use App\Modules\HR\Onboarding\Enums\OnboardingTaskStatus;
use App\Modules\HR\Onboarding\Enums\OnboardingTaskType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LmsOnboardingTrainingService
{
    // ── assignOnboardingTraining ───────────────────────────────────────────────
    // Assigns induction + policy training rules (scope_type = all | policy)
    // and creates placeholder OnboardingTask entries of type Training.

    public function assignOnboardingTraining(OnboardingPlan $plan, User $user, int $actorId): array
    {
        $person     = $user->person;
        $startDate  = $plan->start_date ?? now();

        // Load applicable rules: induction (all) + policy
        $rules = LmsOnboardingTrainingRule::active()
            ->whereIn('rule_type', ['induction', 'policy'])
            ->where('scope_type', 'all')
            ->with('course')
            ->get();

        $created = $this->createAssignments($plan, $user, $rules, $startDate, $actorId);

        // Sync matching OnboardingTask of type Training
        $this->syncOnboardingTasks($plan, $rules);

        Log::info("[LmsOnboarding] Assigned {$created} induction/policy training(s) to user {$user->id}");

        return ['assigned' => $created, 'user_id' => $user->id, 'plan_id' => $plan->id];
    }

    // ── assignRoleBasedTraining ───────────────────────────────────────────────
    // Assigns department + role-based training rules matched to the employee's
    // position (position_id) and department (division_level_1).

    public function assignRoleBasedTraining(OnboardingPlan $plan, User $user, int $actorId): array
    {
        $person    = $user->person;
        $startDate = $plan->start_date ?? now();
        $created   = 0;

        // Role-based rules
        $roleRules = LmsOnboardingTrainingRule::active()
            ->where('rule_type', 'role_based')
            ->where('scope_type', 'position')
            ->where(function ($q) use ($person) {
                $q->whereNull('scope_id');
                if ($person?->position_id) {
                    $q->orWhere('scope_id', $person->position_id);
                }
            })
            ->with('course')
            ->get();

        // Department-based rules
        $deptRules = LmsOnboardingTrainingRule::active()
            ->where('rule_type', 'department')
            ->where('scope_type', 'department')
            ->where(function ($q) use ($person) {
                $q->whereNull('scope_id');
                if ($person?->division_level_1) {
                    $q->orWhere('scope_id', $person->division_level_1);
                }
            })
            ->with('course')
            ->get();

        $allRules = $roleRules->merge($deptRules);
        $created  = $this->createAssignments($plan, $user, $allRules, $startDate, $actorId);

        $this->syncOnboardingTasks($plan, $allRules);

        Log::info("[LmsOnboarding] Assigned {$created} role/department training(s) to user {$user->id}");

        return ['assigned' => $created, 'user_id' => $user->id, 'plan_id' => $plan->id];
    }

    // ── Full batch assignment ─────────────────────────────────────────────────
    // Convenience method called when a plan is activated: runs both assignment methods.

    public function assignAllOnboardingTraining(OnboardingPlan $plan, User $user, int $actorId): array
    {
        $induction = $this->assignOnboardingTraining($plan, $user, $actorId);
        $role      = $this->assignRoleBasedTraining($plan, $user, $actorId);

        return [
            'total_assigned' => $induction['assigned'] + $role['assigned'],
            'induction'      => $induction['assigned'],
            'role_based'     => $role['assigned'],
        ];
    }

    // ── trackOnboardingTrainingCompletion ─────────────────────────────────────
    // Syncs enrollment statuses back into the onboarding training assignments.

    public function trackOnboardingTrainingCompletion(OnboardingPlan $plan): int
    {
        $updated = 0;

        LmsOnboardingTrainingAssignment::where('onboarding_plan_id', $plan->id)
            ->whereNotIn('status', ['completed', 'failed'])
            ->with(['enrollment', 'rule'])
            ->each(function (LmsOnboardingTrainingAssignment $assignment) use (&$updated) {
                // If no enrollment yet, try to find one
                if (!$assignment->enrollment_id) {
                    $enrollment = LmsEnrollment::where('user_id', $assignment->user_id)
                        ->where('course_id', $assignment->course_id)
                        ->latest()
                        ->first();

                    if ($enrollment) {
                        $assignment->enrollment_id = $enrollment->id;
                    }
                }

                $enrollment = $assignment->enrollment ?? LmsEnrollment::find($assignment->enrollment_id);
                if (!$enrollment) {
                    // Mark overdue if past due date
                    if ($assignment->due_date && $assignment->due_date->isPast()) {
                        $assignment->update(['status' => 'overdue']);
                        $updated++;
                    }
                    return;
                }

                $newStatus = $assignment->status;

                if ($enrollment->status === 'completed') {
                    $passScore = $assignment->rule?->min_pass_score;
                    $passed    = !$passScore || ($enrollment->score !== null && $enrollment->score >= $passScore);
                    $newStatus = $passed ? 'completed' : 'failed';
                } elseif ($enrollment->status === 'in_progress') {
                    $newStatus = 'in_progress';
                } elseif ($assignment->due_date?->isPast()) {
                    $newStatus = 'overdue';
                }

                if ($newStatus !== $assignment->status) {
                    $assignment->update([
                        'status'        => $newStatus,
                        'enrollment_id' => $enrollment->id,
                        'pass_score'    => $enrollment->score,
                        'completed_at'  => $newStatus === 'completed' ? ($enrollment->completed_at ?? now()) : null,
                    ]);
                    $updated++;
                } else {
                    // Always keep enrollment_id in sync
                    $assignment->update(['enrollment_id' => $enrollment->id]);
                }
            });

        return $updated;
    }

    // ── trackOnboardingUnderstandingScore ─────────────────────────────────────
    // Pulls the latest understanding score for each assignment's course/user.

    public function trackOnboardingUnderstandingScore(OnboardingPlan $plan): int
    {
        $updated = 0;
        $userId  = null;

        // Get user_id from any assignment on this plan
        $firstAssignment = LmsOnboardingTrainingAssignment::where('onboarding_plan_id', $plan->id)->first();
        if (!$firstAssignment) return 0;
        $userId = $firstAssignment->user_id;

        LmsOnboardingTrainingAssignment::where('onboarding_plan_id', $plan->id)
            ->whereNull('understanding_score')
            ->orWhere('status', 'in_progress')
            ->each(function (LmsOnboardingTrainingAssignment $assignment) use (&$updated) {
                $score = LmsUnderstandingScore::where('user_id', $assignment->user_id)
                    ->whereHas('enrollment', fn ($q) => $q->where('course_id', $assignment->course_id))
                    ->orderByDesc('created_at')
                    ->value('total_score');

                if ($score !== null && $score != $assignment->understanding_score) {
                    $assignment->update(['understanding_score' => $score]);

                    // Check if below min_understanding_score and completed → mark failed
                    $minScore = $assignment->rule?->min_understanding_score;
                    if ($minScore && $score < $minScore && $assignment->status === 'completed') {
                        $assignment->update(['status' => 'failed']);
                    }

                    $updated++;
                }
            });

        return $updated;
    }

    // ── notifyIncompleteOnboardingTraining ────────────────────────────────────
    // Sends email + DB notification to employee for each incomplete assignment
    // that is within $daysBeforeDue days of due date (or already overdue).
    // Also alerts HR for blocking assignments that are overdue.

    public function notifyIncompleteOnboardingTraining(int $daysBeforeDue = 3): int
    {
        $notified = 0;

        // Group assignments by plan (one notification per employee)
        $groups = LmsOnboardingTrainingAssignment::whereNotIn('status', ['completed'])
            ->where(function ($q) use ($daysBeforeDue) {
                $q->where('status', 'overdue')
                  ->orWhere(fn ($sq) => $sq
                      ->whereIn('status', ['pending', 'in_progress'])
                      ->where('due_date', '<=', now()->addDays($daysBeforeDue)->toDateString())
                  );
            })
            ->with(['user.person', 'course', 'rule'])
            ->get()
            ->groupBy('onboarding_plan_id');

        foreach ($groups as $planId => $assignments) {
            $firstUser = $assignments->first()?->user;
            if (!$firstUser) continue;

            $personName = $firstUser->person
                ? trim("{$firstUser->person->first_name} {$firstUser->person->surname}")
                : $firstUser->email;

            // Notify employee
            $firstUser->notify(new OnboardingTrainingIncompleteNotification(
                collect($assignments),
                $personName,
                false
            ));

            // Mark reminder sent on each assignment (today deduplication)
            $assignments->each(function ($a) {
                if (!$a->reminder_sent_at || !$a->reminder_sent_at->isToday()) {
                    $a->update(['reminder_sent_at' => now()]);
                }
            });

            // Alert HR for blocking overdue assignments
            $blockingOverdue = collect($assignments)->filter(
                fn ($a) => $a->blocks_completion && in_array($a->status, ['overdue', 'failed'])
            );

            if ($blockingOverdue->isNotEmpty()) {
                // Resolved by name rather than passed straight to role(), which throws
                // RoleDoesNotExist on a miss — and the role is 'HR', not 'hr'. Nobody to notify
                // is a quiet outcome; an exception here takes the caller down with it.
                $hrRole  = Role::query()->whereRaw('lower(name) = ?', ['hr'])->value('name');
                $hrUsers = $hrRole ? User::role($hrRole)->limit(5)->get() : collect();
                foreach ($hrUsers as $hr) {
                    $hr->notify(new OnboardingTrainingIncompleteNotification(
                        $blockingOverdue,
                        $personName,
                        true
                    ));
                }

                $blockingOverdue->each(fn ($a) => $a->update(['notified_hr_at' => now()]));
            }

            $notified++;
        }

        return $notified;
    }

    // ── blockOnboardingCompletionIfTrainingFailed ─────────────────────────────
    // Returns a blocking status array. If blocked, also throws a ValidationException
    // when $throwOnBlock = true so callers (e.g. CompleteOnboardingTaskAction hook)
    // can stop the completion flow.

    public function blockOnboardingCompletionIfTrainingFailed(OnboardingPlan $plan, bool $throwOnBlock = false): array
    {
        $blocking = LmsOnboardingTrainingAssignment::where('onboarding_plan_id', $plan->id)
            ->where('blocks_completion', true)
            ->whereNotIn('status', ['completed'])
            ->with(['course', 'rule'])
            ->get();

        $isBlocked = $blocking->isNotEmpty();

        $details = $blocking->map(fn ($a) => [
            'assignment_id' => $a->id,
            'course_title'  => $a->course?->title,
            'rule_type'     => $a->rule?->ruleTypeLabel() ?? $a->rule_type,
            'status'        => $a->status,
            'due_date'      => $a->due_date?->toDateString(),
        ])->values()->all();

        if ($isBlocked && $throwOnBlock) {
            $courseList = collect($details)->pluck('course_title')->implode(', ');
            throw ValidationException::withMessages([
                'onboarding_completion' => [
                    "Onboarding cannot be completed: the following mandatory training is incomplete — {$courseList}.",
                ],
            ]);
        }

        return [
            'is_blocked'      => $isBlocked,
            'blocking_count'  => $blocking->count(),
            'blocking_courses'=> $details,
        ];
    }

    // ── Status dashboard ──────────────────────────────────────────────────────

    public function getOnboardingTrainingStatus(OnboardingPlan $plan): array
    {
        $assignments = LmsOnboardingTrainingAssignment::where('onboarding_plan_id', $plan->id)
            ->with(['course', 'rule', 'enrollment'])
            ->get();

        $total     = $assignments->count();
        $completed = $assignments->where('status', 'completed')->count();
        $failed    = $assignments->whereIn('status', ['failed', 'overdue'])->count();
        $pending   = $assignments->whereIn('status', ['pending', 'in_progress'])->count();
        $blocking  = $assignments->where('blocks_completion', true)->whereNotIn('status', ['completed'])->count();

        return [
            'total'          => $total,
            'completed'      => $completed,
            'failed'         => $failed,
            'pending'        => $pending,
            'completion_pct' => $total > 0 ? round($completed / $total * 100) : 0,
            'is_blocked'     => $blocking > 0,
            'blocking_count' => $blocking,
            'assignments'    => $assignments->map(fn ($a) => [
                'id'                  => $a->id,
                'course_title'        => $a->course?->title,
                'rule_type'           => $a->rule?->ruleTypeLabel() ?? $a->rule_type,
                'status'              => $a->status,
                'status_color'        => $a->statusColor(),
                'due_date'            => $a->due_date?->toDateString(),
                'completed_at'        => $a->completed_at?->toDateTimeString(),
                'pass_score'          => $a->pass_score,
                'understanding_score' => $a->understanding_score,
                'blocks_completion'   => $a->blocks_completion,
            ])->values()->all(),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createAssignments(
        OnboardingPlan $plan,
        User           $user,
        Collection     $rules,
        Carbon         $startDate,
        int            $actorId
    ): int {
        $created = 0;

        foreach ($rules as $rule) {
            // Find or create an LMS enrollment for this course
            $enrollment = LmsEnrollment::firstOrCreate(
                ['user_id' => $user->id, 'course_id' => $rule->course_id],
                [
                    'status'      => 'enrolled',
                    'enrolled_at' => now(),
                ]
            );

            $wasCreated = LmsOnboardingTrainingAssignment::updateOrCreate(
                [
                    'onboarding_plan_id' => $plan->id,
                    'user_id'            => $user->id,
                    'rule_id'            => $rule->id,
                ],
                [
                    'course_id'         => $rule->course_id,
                    'enrollment_id'     => $enrollment->id,
                    'rule_type'         => $rule->rule_type,
                    'status'            => 'pending',
                    'due_date'          => $startDate->copy()->addDays($rule->due_within_days)->toDateString(),
                    'assigned_at'       => now(),
                    'blocks_completion' => $rule->blocks_onboarding_completion,
                ]
            );

            if ($wasCreated->wasRecentlyCreated) $created++;
        }

        return $created;
    }

    private function syncOnboardingTasks(OnboardingPlan $plan, Collection $rules): void
    {
        foreach ($rules as $rule) {
            $exists = OnboardingTask::where('onboarding_plan_id', $plan->id)
                ->where('type', OnboardingTaskType::Training->value)
                ->where('title', 'Training: ' . $rule->course?->title)
                ->exists();

            if (!$exists) {
                OnboardingTask::create([
                    'onboarding_plan_id' => $plan->id,
                    'type'               => OnboardingTaskType::Training->value,
                    'title'              => 'Training: ' . ($rule->course?->title ?? 'Course'),
                    'description'        => $rule->ruleTypeLabel() . ' — due within ' . $rule->due_within_days . ' days',
                    'due_date'           => $plan->start_date?->copy()->addDays($rule->due_within_days),
                    'sort_order'         => 99,
                ]);
            }
        }
    }
}
