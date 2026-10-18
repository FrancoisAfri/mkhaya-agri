<?php

namespace App\Modules\HR\Onboarding\Services;

use App\Modules\HR\Onboarding\Models\OnboardingDocument;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingProvisioningTask;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What onboarding is actually doing, as numbers.
 *
 * Offboarding has had analytics and reports since it was built; onboarding had neither, so nobody
 * could answer "are new people actually being onboarded?" without opening plans one at a time.
 *
 * The measures are chosen to be actionable rather than flattering. A completion percentage on its
 * own always looks respectable — the useful questions are which department is behind, what is
 * overdue right now, and whether IT is delivering laptops before people arrive rather than after.
 */
class OnboardingAnalyticsService
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'kpis' => $this->kpis(),
            'by_status' => $this->planCountsByStatus(),
            'by_department' => $this->completionByDepartment(),
            'monthly_volume' => $this->monthlyVolume(),
            'task_ageing' => $this->overdueTaskAgeing(),
            'documents' => $this->documentStatus(),
            'provisioning' => $this->provisioningReadiness(),
            'probation' => $this->probationOutcomes(),
        ];
    }

    /** @return array<string, int|float|null> */
    private function kpis(): array
    {
        $active = OnboardingPlan::where('status', 'active')->count();
        $completed = OnboardingPlan::where('status', 'completed')->count();

        return [
            'active_plans' => $active,
            'completed_plans' => $completed,
            'overdue_tasks' => OnboardingTask::whereIn('status', ['pending', 'in_progress'])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->count(),
            'starting_this_month' => OnboardingPlan::whereBetween('start_date', [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ])->count(),
            'average_days_to_complete' => $this->averageDaysToComplete(),
        ];
    }

    /**
     * How long a completed plan actually took, start date to completion.
     *
     * Measured against the start date rather than when the plan was created, because a plan built
     * six weeks ahead of someone's first day would otherwise look like a six-week onboarding.
     */
    private function averageDaysToComplete(): ?float
    {
        $days = OnboardingPlan::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereNotNull('start_date')
            ->get(['start_date', 'completed_at'])
            ->map(fn (OnboardingPlan $p) => Carbon::parse($p->start_date)->diffInDays($p->completed_at, false))
            // A plan completed before the start date is a data artefact, not a fast onboarding.
            ->filter(fn (int $d) => $d >= 0);

        return $days->isEmpty() ? null : round($days->avg(), 1);
    }

    /** @return array<int, array{label: string, count: int}> */
    private function planCountsByStatus(): array
    {
        return OnboardingPlan::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count, $status) => ['label' => $status, 'count' => (int) $count])
            ->values()
            ->all();
    }

    /**
     * Completion by department — the measure that says where to intervene.
     *
     * A single portfolio-wide percentage hides the department that never finishes anything behind
     * the four that always do.
     */
    private function completionByDepartment(): array
    {
        return OnboardingPlan::query()
            ->join('hr_persons', 'hr_persons.id', '=', 'onboarding_plans.hr_person_id')
            ->select('hr_persons.department', DB::raw('count(*) as total'),
                DB::raw("sum(case when onboarding_plans.status = 'completed' then 1 else 0 end) as completed"))
            ->whereNotNull('hr_persons.department')
            ->groupBy('hr_persons.department')
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'label' => $row->department,
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'pct' => $row->total > 0 ? (int) round($row->completed / $row->total * 100) : 0,
            ])->all();
    }

    /** @return array<int, array{month: string, count: int}> */
    private function monthlyVolume(): array
    {
        return OnboardingPlan::query()
            ->where('start_date', '>=', now()->subMonths(11)->startOfMonth()->toDateString())
            ->select(DB::raw("to_char(start_date, 'YYYY-MM') as month"), DB::raw('count(*) as total'))
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => ['month' => $row->month, 'count' => (int) $row->total])
            ->all();
    }

    /**
     * Overdue work bucketed by age.
     *
     * Bucketed rather than averaged: one task stuck for a month is the thing worth seeing, and a
     * mean lateness buries it under thirty that slipped a day.
     */
    private function overdueTaskAgeing(): array
    {
        $overdue = OnboardingTask::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->pluck('due_date');

        $buckets = ['1_7' => 0, '8_30' => 0, 'over_30' => 0];

        foreach ($overdue as $due) {
            $days = Carbon::parse($due)->diffInDays(now());

            match (true) {
                $days <= 7 => $buckets['1_7']++,
                $days <= 30 => $buckets['8_30']++,
                default => $buckets['over_30']++,
            };
        }

        return $buckets;
    }

    private function documentStatus(): array
    {
        return OnboardingDocument::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Whether provisioning lands before the person does.
     *
     * A laptop delivered on day three is not a completed task in any sense the new hire recognises,
     * so "outstanding for someone who has already started" is counted separately.
     */
    private function provisioningReadiness(): array
    {
        $outstanding = OnboardingProvisioningTask::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        $lateForStarted = OnboardingProvisioningTask::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereHas('hrPerson', fn ($q) => $q->whereNotNull('started_on')
                ->whereDate('started_on', '<=', now()->toDateString()))
            ->count();

        return [
            'outstanding' => $outstanding,
            'late_for_started' => $lateForStarted,
        ];
    }

    private function probationOutcomes(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('probation_periods')) {
            return [];
        }

        return DB::table('probation_periods')
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Rows for the CSV export.
     *
     * One row per plan with the figures a person would otherwise assemble by hand from twelve
     * screens — which is the actual reason exports exist.
     *
     * @return array{0: list<string>, 1: list<list<string|int>>}
     */
    public function planExport(): array
    {
        $headers = ['Code', 'Name', 'Department', 'Job title', 'Status', 'Start date', 'End date',
            'Tasks', 'Completed', 'Completion %', 'Overdue tasks', 'Documents approved'];

        $rows = OnboardingPlan::query()
            ->with(['hrPerson', 'tasks'])
            ->orderByDesc('start_date')
            ->get()
            ->map(function (OnboardingPlan $plan) {
                $tasks = $plan->tasks;
                $done = $tasks->filter(fn ($t) => in_array($t->status?->value ?? $t->status, ['completed', 'skipped'], true))->count();

                return [
                    $plan->id,
                    $plan->hrPerson?->fullName() ?: '',
                    $plan->hrPerson?->department ?: '',
                    $plan->hrPerson?->job_title ?: '',
                    $plan->status?->value ?? (string) $plan->status,
                    $plan->start_date?->toDateString() ?: '',
                    $plan->end_date?->toDateString() ?: '',
                    $tasks->count(),
                    $done,
                    $tasks->count() > 0 ? (int) round($done / $tasks->count() * 100) : 0,
                    $tasks->filter(fn ($t) => $t->due_date
                        && $t->due_date->isPast()
                        && in_array($t->status?->value ?? $t->status, ['pending', 'in_progress'], true))->count(),
                    OnboardingDocument::where('hr_person_id', $plan->hr_person_id)
                        ->where('status', 'approved')->count(),
                ];
            })->all();

        return [$headers, $rows];
    }
}
