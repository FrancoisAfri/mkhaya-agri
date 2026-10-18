<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Models\ProbationPeriod;
use App\Modules\HR\Onboarding\Models\ProbationReview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CaptureProbationReviewAction
{
    public function execute(ProbationPeriod $probationPeriod, array $data, int $createdBy): ProbationReview
    {
        return DB::transaction(function () use ($probationPeriod, $data, $createdBy): ProbationReview {
            $review = ProbationReview::create([
                'probation_period_id'   => $probationPeriod->id,
                'review_date'           => $data['review_date'],
                'review_type'           => $data['review_type'] ?? 'mid',
                'performance_score'     => $data['performance_score'] ?? null,
                'manager_feedback'      => $data['manager_feedback'] ?? null,
                'employee_feedback'     => $data['employee_feedback'] ?? null,
                'areas_of_improvement'  => $data['areas_of_improvement'] ?? null,
                'recommendation'        => $data['recommendation'] ?? null,
                'reviewed_by'           => $createdBy,
                'completed_at'          => now(),
                'created_by'            => $createdBy,
            ]);

            $recommendation = $data['recommendation'] ?? null;

            if ($recommendation === 'confirm') {
                $probationPeriod->update([
                    'status'           => 'completed',
                    'outcome'          => 'confirmed',
                    'outcome_notes'    => $data['manager_feedback'] ?? null,
                    'next_review_date' => null,
                ]);
            } elseif ($recommendation === 'extend') {
                $extensionMonths   = (int) ($data['extension_months'] ?? 1);
                $currentEnd        = $probationPeriod->extended_end_date ?? $probationPeriod->end_date;
                $newEnd            = Carbon::parse($currentEnd)->addMonths($extensionMonths);
                $nextReviewDate    = Carbon::parse($currentEnd)->addMonths((int) ceil($extensionMonths / 2));

                $probationPeriod->update([
                    'status'            => 'extended',
                    'outcome'           => 'extended',
                    'extended_end_date' => $newEnd->toDateString(),
                    'next_review_date'  => $nextReviewDate->toDateString(),
                ]);
            } elseif ($recommendation === 'dismiss') {
                $probationPeriod->update([
                    'status'           => 'failed',
                    'outcome'          => 'dismissed',
                    'outcome_notes'    => $data['manager_feedback'] ?? null,
                    'next_review_date' => null,
                ]);
            } else {
                // No recommendation yet — push next review date forward by ~4 weeks
                $probationPeriod->update([
                    'next_review_date' => now()->addWeeks(4)->toDateString(),
                ]);
            }

            return $review;
        });
    }
}
