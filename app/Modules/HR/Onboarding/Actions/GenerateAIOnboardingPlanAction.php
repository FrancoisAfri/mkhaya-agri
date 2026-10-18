<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Enums\OnboardingTaskType;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Support\Carbon;

class GenerateAIOnboardingPlanAction
{
    /**
     * Generate a structured task list based on job title and department.
     * Returns an array of tasks in the shape expected by CreateOnboardingPlanAction.
     *
     * @return array{tasks: array<int, array<string, mixed>>, start_date: string, end_date: string}
     */
    public function execute(HrPerson $hrPerson, array $data): array
    {
        $startDate   = Carbon::parse($data['start_date'] ?? now()->toDateString());
        $department  = strtolower($data['department'] ?? '');
        $jobTitle    = strtolower($data['job_title'] ?? '');

        $tasks = [];

        // ── Day 0: Arrival & Setup ────────────────────────────────────────────
        $tasks[] = $this->task(OnboardingTaskType::Meeting, 'Welcome meeting with manager', $startDate, 0,
            'Introductory 1-on-1 with line manager to discuss role expectations, team structure and first-week plan.');

        $tasks[] = $this->task(OnboardingTaskType::Introduction, 'Welcome pack & office orientation', $startDate, 0,
            'Collect access card, welcome pack and receive office tour.');

        $tasks[] = $this->task(OnboardingTaskType::SystemSetup, 'Workstation setup', $startDate, 0,
            'Set up laptop, configure email, install required applications and verify system access.');

        // ── Week 1: Integration ───────────────────────────────────────────────
        $tasks[] = $this->task(OnboardingTaskType::Introduction, 'Team introductions', $startDate, 1,
            'Meet colleagues across the immediate team and key stakeholders.');

        $tasks[] = $this->task(OnboardingTaskType::SystemSetup, 'System access verification', $startDate, 2,
            'Confirm access to all required systems, dashboards and shared drives.');

        $tasks[] = $this->task(OnboardingTaskType::PolicyAcknowledgement, 'Review & acknowledge HR policies', $startDate, 3,
            'Read the employee handbook, code of conduct, leave policy and sign the acknowledgement form.');

        $tasks[] = $this->task(OnboardingTaskType::Training, 'Compliance & safety training', $startDate, 4,
            'Complete mandatory compliance, data protection (POPIA) and workplace safety modules.');

        // ── Department-specific Week 1 ────────────────────────────────────────
        if ($this->isDepartment($department, ['it', 'technology', 'engineering', 'dev'])) {
            $tasks[] = $this->task(OnboardingTaskType::Training, 'Development environment & code standards walkthrough', $startDate, 5,
                'Set up dev environment, review coding standards, branching strategy and deployment processes.');
        } elseif ($this->isDepartment($department, ['finance', 'accounting'])) {
            $tasks[] = $this->task(OnboardingTaskType::Training, 'Finance systems & chart of accounts orientation', $startDate, 5,
                'Introduction to ERP finance modules, approval workflows and reporting structures.');
        } elseif ($this->isDepartment($department, ['hr', 'human resources', 'people'])) {
            $tasks[] = $this->task(OnboardingTaskType::Training, 'HR systems & process walkthrough', $startDate, 5,
                'Introduction to HRIS, payroll calendar, recruitment process and employee lifecycle.');
        } elseif ($this->isDepartment($department, ['sales', 'commercial', 'business development'])) {
            $tasks[] = $this->task(OnboardingTaskType::Reading, 'Product & pricing overview', $startDate, 5,
                'Review product catalogue, pricing guide, target market segments and sales playbook.');
        } else {
            $tasks[] = $this->task(OnboardingTaskType::Reading, 'Department overview & key processes', $startDate, 5,
                'Review departmental strategy, key processes, reporting lines and current projects.');
        }

        // ── Month 1: Deep-dive ────────────────────────────────────────────────
        $tasks[] = $this->task(OnboardingTaskType::Meeting, 'Department walkthrough & cross-functional introductions', $startDate, 7,
            'Shadow key team members and attend department meetings to understand workflows.');

        $tasks[] = $this->task(OnboardingTaskType::Training, 'Role-specific training', $startDate, 10,
            'Complete role-specific training modules relevant to ' . ($data['job_title'] ?? 'the position') . '.');

        $tasks[] = $this->task(OnboardingTaskType::Reading, 'Review current projects & objectives', $startDate, 14,
            'Get up to speed on active projects, OKRs and team goals for the current quarter.');

        $tasks[] = $this->task(OnboardingTaskType::Meeting, '30-day check-in with manager', $startDate, 30,
            'Formal check-in to discuss progress, address any challenges and align on 60-day goals.');

        // ── Manager-level extras ──────────────────────────────────────────────
        if ($this->isManagerRole($jobTitle)) {
            $tasks[] = $this->task(OnboardingTaskType::Meeting, 'Team structure & direct reports introductions', $startDate, 2,
                'Meet all direct reports individually for introductory conversations.');

            $tasks[] = $this->task(OnboardingTaskType::Reading, 'Review team performance data & ongoing projects', $startDate, 7,
                'Review team KPIs, performance history and understand ongoing workstreams.');
        }

        // Sort by due_date ascending
        usort($tasks, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));

        $endDate = $startDate->copy()->addDays(30)->toDateString();

        return [
            'start_date' => $startDate->toDateString(),
            'end_date'   => $endDate,
            'tasks'      => array_values($tasks),
        ];
    }

    private function task(
        OnboardingTaskType $type,
        string $title,
        Carbon $startDate,
        int $dayOffset,
        string $description = '',
    ): array {
        return [
            'type'        => $type->value,
            'title'       => $title,
            'description' => $description,
            'due_date'    => $startDate->copy()->addDays($dayOffset)->toDateString(),
            'assigned_to' => null,
        ];
    }

    private function isDepartment(string $department, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($department, $keyword)) {
                return true;
            }
        }
        return false;
    }

    private function isManagerRole(string $jobTitle): bool
    {
        $managerKeywords = ['manager', 'head', 'director', 'lead', 'supervisor', 'chief', 'vp', 'president'];
        foreach ($managerKeywords as $keyword) {
            if (str_contains($jobTitle, $keyword)) {
                return true;
            }
        }
        return false;
    }
}
