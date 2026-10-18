<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Modules\PerformanceAppraisal\Models\PaCheckin;
use App\Modules\PerformanceAppraisal\Models\PaGoal;
use App\Modules\PerformanceAppraisal\Models\PaNudge;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use App\Modules\PerformanceAppraisal\Models\PaWellnessScore;
use Illuminate\Support\Facades\DB;

/**
 * Generates proactive, actionable nudges for managers & employees by scanning
 * real performance state. Idempotent per (user,type,about) within a window, so
 * running it daily won't spam duplicates.
 */
class PaNudgeService
{
    public function generateAll(): int
    {
        $created = 0;
        $created += $this->checkinGaps();
        $created += $this->reviewsReady();
        $created += $this->goalsAtRisk();
        $created += $this->wellnessRisks();

        return $created;
    }

    /** Managers with a direct report who hasn't had a check-in in 35+ days. */
    private function checkinGaps(): int
    {
        $created = 0;
        $pairs = DB::table('hr_people')
            ->join('hr_people as mgr', 'hr_people.manager_id', '=', 'mgr.id')
            ->whereNotNull('hr_people.user_id')
            ->whereNotNull('mgr.user_id')
            ->get(['hr_people.user_id as emp_user', 'mgr.user_id as mgr_user',
                    'hr_people.first_name', 'hr_people.surname']);

        foreach ($pairs as $p) {
            $last = PaCheckin::where('employee_id', $p->emp_user)
                ->where('manager_id', $p->mgr_user)
                ->whereNotNull('completed_at')
                ->max('completed_at');

            $stale = ! $last || \Carbon\Carbon::parse($last)->lt(now()->subDays(35));
            if (! $stale) {
                continue;
            }

            $created += $this->push($p->mgr_user, 'checkin_overdue', [
                'about_employee_id' => $p->emp_user,
                'priority' => 'high',
                'title'    => 'Time for a 1:1 with ' . trim("{$p->first_name} {$p->surname}"),
                'body'     => $last
                    ? 'Last check-in was ' . \Carbon\Carbon::parse($last)->diffForHumans() . '. Schedule the next one.'
                    : 'No completed check-in on record yet. Schedule the first 1:1.',
                'action_route' => 'performance.checkins.index',
            ]);
        }

        return $created;
    }

    /** Snapshots submitted by the employee and waiting for manager action. */
    private function reviewsReady(): int
    {
        $created = 0;
        $pending = PaPerformanceSnapshot::where('appraisal_status', 'employee_submitted')->get();

        foreach ($pending as $snap) {
            $mgrUser = DB::table('hr_people as emp')
                ->join('hr_people as mgr', 'emp.manager_id', '=', 'mgr.id')
                ->where('emp.user_id', $snap->employee_id)
                ->value('mgr.user_id');
            if (! $mgrUser) {
                continue;
            }

            $name = $this->name($snap->employee_id);
            $created += $this->push($mgrUser, 'review_ready', [
                'about_employee_id' => $snap->employee_id,
                'cycle_id' => $snap->cycle_id,
                'priority' => 'high',
                'title'    => "{$name}'s self-review is ready",
                'body'     => 'Review and score the appraisal. AI can draft a summary to get you started.',
                'action_route' => 'performance.appraisal-review.index',
            ]);
        }

        return $created;
    }

    /** Active goals past 70% of their timeline but under 60% progress. */
    private function goalsAtRisk(): int
    {
        $created = 0;
        $goals = PaGoal::where('status', 'active')
            ->whereNotNull('end_date')->whereNotNull('start_date')
            ->whereNotNull('owner_id')->get();

        foreach ($goals as $g) {
            $total = $g->start_date->diffInDays($g->end_date) ?: 1;
            $elapsed = $g->start_date->diffInDays(now());
            $timePct = min(100, ($elapsed / $total) * 100);

            if ($timePct >= 70 && $g->progressPct() < 60) {
                $created += $this->push($g->owner_id, 'goal_at_risk', [
                    'cycle_id' => $g->cycle_id,
                    'priority' => 'normal',
                    'title'    => 'Goal at risk: ' . \Illuminate\Support\Str::limit($g->title, 60),
                    'body'     => round($timePct) . '% of the time has elapsed but progress is at ' . round($g->progressPct()) . '%.',
                    'action_route' => 'performance.goals.index',
                    'data'     => ['goal_id' => $g->id],
                ]);
            }
        }

        return $created;
    }

    /** Employees flagged high/critical burnout — nudge their manager. */
    private function wellnessRisks(): int
    {
        $created = 0;
        $risky = PaWellnessScore::whereIn('burnout_risk', ['high', 'critical'])->get();

        foreach ($risky as $w) {
            $mgrUser = DB::table('hr_people as emp')
                ->join('hr_people as mgr', 'emp.manager_id', '=', 'mgr.id')
                ->where('emp.user_id', $w->employee_id)
                ->value('mgr.user_id');
            if (! $mgrUser) {
                continue;
            }

            $created += $this->push($mgrUser, 'wellness_risk', [
                'about_employee_id' => $w->employee_id,
                'cycle_id' => $w->cycle_id,
                'priority' => 'high',
                'title'    => 'Wellness alert: ' . $this->name($w->employee_id),
                'body'     => 'Burnout risk is ' . $w->burnout_risk . '. Consider a supportive check-in.',
                'action_route' => 'performance.wellness.index',
            ]);
        }

        return $created;
    }

    /** Insert a nudge unless an undismissed one of the same kind exists in the last 14 days. */
    private function push(int $userId, string $type, array $attrs): int
    {
        $exists = PaNudge::where('user_id', $userId)
            ->where('type', $type)
            ->when($attrs['about_employee_id'] ?? null, fn ($q, $v) => $q->where('about_employee_id', $v))
            ->whereNull('dismissed_at')
            ->where('created_at', '>=', now()->subDays(14))
            ->exists();

        if ($exists) {
            return 0;
        }

        PaNudge::create([
            'user_id'           => $userId,
            'about_employee_id' => $attrs['about_employee_id'] ?? null,
            'cycle_id'          => $attrs['cycle_id'] ?? null,
            'type'              => $type,
            'priority'          => $attrs['priority'] ?? 'normal',
            'title'             => $attrs['title'],
            'body'              => $attrs['body'] ?? null,
            'action_route'      => $attrs['action_route'] ?? null,
            'data'              => $attrs['data'] ?? null,
        ]);

        return 1;
    }

    private function name(int $userId): string
    {
        $p = DB::table('hr_people')->where('user_id', $userId)->first(['first_name', 'surname']);
        return $p ? trim("{$p->first_name} {$p->surname}") : ('User #' . $userId);
    }
}
