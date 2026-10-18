<?php

namespace App\Modules\HR\Offboarding\Http\Controllers;

use App\Modules\HR\Offboarding\Models\OffboardingClearance;
use App\Modules\HR\Offboarding\Models\OffboardingExitInterview;
use App\Modules\HR\Offboarding\Models\OffboardingPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OffboardingReportController
{
    private const ALLOWED_REPORTS = ['plans_summary', 'exit_interviews', 'clearance_status'];

    public function index(): Response
    {
        $stats = [
            'total_plans'      => OffboardingPlan::count(),
            'completed_plans'  => OffboardingPlan::where('status', 'completed')->count(),
            'active_plans'     => OffboardingPlan::where('status', 'active')->count(),
            'total_interviews' => OffboardingExitInterview::count(),
            'total_clearances' => OffboardingClearance::count(),
            'pending_clearances' => OffboardingClearance::where('status', 'pending')->count(),
        ];

        return Inertia::render('Modules/Offboarding/Reports', ['stats' => $stats]);
    }

    public function export(Request $request, string $key): StreamedResponse
    {
        abort_unless(in_array($key, self::ALLOWED_REPORTS, true), 422, 'Unknown report key.');

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
            'status'    => ['nullable', 'string'],
        ]);

        return match ($key) {
            'plans_summary'    => $this->plansSummary($validated),
            'exit_interviews'  => $this->exitInterviews($validated),
            'clearance_status' => $this->clearanceStatus($validated),
        };
    }

    private function plansSummary(array $filters): StreamedResponse
    {
        $filename = 'offboarding_plans_' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Employee Name', 'Email', 'Termination Type', 'Status',
                'Resignation Date', 'Last Working Day', 'Completion %',
                'Rehire Eligible', 'Rehire Notes',
                'Counteroffer Made', 'Counteroffer Outcome',
                'Notes', 'Created At',
            ]);

            OffboardingPlan::with('hrPerson')
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                ->orderBy('created_at', 'desc')
                ->chunk(200, function ($plans) use ($handle) {
                    foreach ($plans as $plan) {
                        $candidate = $plan->hrPerson;
                        fputcsv($handle, [
                            $this->personName($candidate),
                            $candidate?->email ?? '',
                            Str::title(str_replace('_', ' ', $plan->termination_type ?? '')),
                            $plan->status->label(),
                            $plan->resignation_date?->toDateString() ?? '',
                            $plan->last_working_day?->toDateString() ?? '',
                            $plan->completionPercentage() . '%',
                            $plan->rehire_eligible === true ? 'Yes' : ($plan->rehire_eligible === false ? 'No' : ''),
                            $plan->rehire_notes ?? '',
                            $plan->counteroffer_made === true ? 'Yes' : ($plan->counteroffer_made === false ? 'No' : ''),
                            Str::title($plan->counteroffer_outcome ?? ''),
                            $plan->notes ?? '',
                            $plan->created_at->toDateString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function exitInterviews(array $filters): StreamedResponse
    {
        $filename = 'offboarding_exit_interviews_' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Employee Name', 'Email', 'Interview Date', 'Reason for Leaving',
                'Satisfaction Score', 'Would Recommend',
                'Strengths', 'Improvements', 'Additional Feedback',
                'Anonymous', 'Conducted By', 'Submitted At',
            ]);

            OffboardingExitInterview::with(['plan.hrPerson', 'conductedBy'])
                ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                ->orderBy('created_at', 'desc')
                ->chunk(200, function ($interviews) use ($handle) {
                    foreach ($interviews as $interview) {
                        $candidate = $interview->plan?->hrPerson;
                        fputcsv($handle, [
                            $interview->is_anonymous ? 'Anonymous' : $this->personName($candidate),
                            $interview->is_anonymous ? '' : ($candidate?->email ?? ''),
                            $interview->interview_date?->toDateString() ?? '',
                            Str::title(str_replace('_', ' ', $interview->reason_for_leaving ?? '')),
                            $interview->satisfaction_score ?? '',
                            $interview->would_recommend === true ? 'Yes' : ($interview->would_recommend === false ? 'No' : ''),
                            $interview->strengths ?? '',
                            $interview->improvements ?? '',
                            $interview->additional_feedback ?? '',
                            $interview->is_anonymous ? 'Yes' : 'No',
                            $interview->conductedBy?->name ?? '',
                            $interview->created_at->toDateString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function clearanceStatus(array $filters): StreamedResponse
    {
        $filename = 'offboarding_clearances_' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Employee Name', 'Email', 'Department', 'Clearance Status',
                'Signed Off By', 'Signed Off At', 'Notes',
            ]);

            OffboardingClearance::with(['plan.hrPerson', 'signedOffBy'])
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['date_from'] ?? null, fn ($q, $d) =>
                    $q->whereHas('plan', fn ($pq) => $pq->whereDate('created_at', '>=', $d))
                )
                ->when($filters['date_to'] ?? null, fn ($q, $d) =>
                    $q->whereHas('plan', fn ($pq) => $pq->whereDate('created_at', '<=', $d))
                )
                ->orderBy('plan_id')
                ->orderBy('department')
                ->chunk(500, function ($clearances) use ($handle) {
                    foreach ($clearances as $clearance) {
                        $candidate = $clearance->plan?->hrPerson;
                        fputcsv($handle, [
                            $this->personName($candidate),
                            $candidate?->email ?? '',
                            Str::ucfirst($clearance->department),
                            Str::ucfirst($clearance->status),
                            $clearance->signedOffBy?->name ?? '',
                            $clearance->signed_off_at?->toDateString() ?? '',
                            $clearance->notes ?? '',
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Build a display name from an HR person (hr_people: first_name + surname). */
    private function personName(mixed $person): string
    {
        if (! $person) {
            return 'Unknown';
        }

        return trim("{$person->first_name} {$person->surname}") ?: ($person->email ?? 'Unknown');
    }

}
