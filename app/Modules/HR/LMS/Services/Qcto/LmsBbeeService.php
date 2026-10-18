<?php

namespace App\Modules\HR\LMS\Services\Qcto;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\Qcto\LmsBbeeTrainingSpend;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment;
use Illuminate\Support\Carbon;

class LmsBbeeService
{
    // B-BBEE Amended Codes: Skills Development element weighting
    const SD_PRIORITY_ELEMENT_POINTS = 20;
    const SPEND_TARGET_PCT            = 6.0;   // 6% of leviable payroll (indicative)
    const LEARNERSHIP_BONUS_PCT       = 0.5;   // bonus points for learnerships

    /**
     * Record a training spend entry when an enrollment completes.
     */
    public function recordSpend(LmsEnrollment $enrollment, float $cost = 0): void
    {
        $person = optional($enrollment->user)->person;
        if (!$person) return;

        $course = $enrollment->course;

        LmsBbeeTrainingSpend::updateOrCreate(
            [
                'spend_year'    => $enrollment->completed_at
                    ? Carbon::parse($enrollment->completed_at)->year
                    : now()->year,
                'hr_person_id'  => $person->id,
                'enrollment_id' => $enrollment->id,
            ],
            [
                'course_id'      => $enrollment->course_id,
                'race'           => $this->mapRace($person->ethnicity),
                'gender'         => strtolower($person->gender ?? 'male'),
                'has_disability' => (bool) $person->disabled,
                'is_sa_citizen'  => $this->isSaCitizen($person),
                'training_type'  => $course->is_qcto_registered
                    ? 'skills_programme'
                    : 'short_course',
                'cost'           => $cost,
                'nqf_level'      => $course->nqf_level,
                'credits'        => $course->credits,
                'is_accredited'  => (bool) $course->is_accredited,
                'is_learnership' => LmsLearnershipEnrolment::where('k_enrollment_id', $enrollment->id)
                    ->orWhere('p_enrollment_id', $enrollment->id)
                    ->exists(),
                'is_bursary'     => false,
                'training_date'  => $enrollment->completed_at ?? now(),
                'result'         => $enrollment->status,
            ]
        );
    }

    /**
     * Record a B-BBEE training spend entry when a learnership enrolment completes.
     * Sets is_learnership = true so sub-indicators 4 and 5 can be computed correctly.
     */
    public function recordLearnershipSpend(LmsLearnershipEnrolment $enrolment, float $cost = 0): void
    {
        $person = $enrolment->hrPerson;
        if (!$person) return;

        $enrolment->loadMissing('learnership');
        $learnership = $enrolment->learnership;

        $year = $enrolment->end_date ? $enrolment->end_date->year : now()->year;

        LmsBbeeTrainingSpend::updateOrCreate(
            [
                'spend_year'     => $year,
                'hr_person_id'   => $person->id,
                'learnership_id' => $enrolment->learnership_id,
            ],
            [
                'race'           => $this->mapRace($person->ethnicity),
                'gender'         => strtolower($person->gender ?? 'male'),
                'has_disability' => (bool) $person->disabled,
                'is_sa_citizen'  => $this->isSaCitizen($person),
                'training_type'  => 'learnership',
                'cost'           => $cost,
                'nqf_level'      => $learnership?->nqf_level,
                'credits'        => $learnership?->total_credits,
                'is_accredited'  => true,
                'is_learnership' => true,
                'is_bursary'     => false,
                'training_date'  => $enrolment->end_date ?? now(),
                'result'         => $enrolment->passed ? 'completed' : $enrolment->status,
            ]
        );
    }

    /**
     * Calculate the B-BBEE Skills Development scorecard for a given year.
     * Implements the 5 sub-indicators under the 2013 Amended Codes of Good Practice.
     */
    public function calculateScorecard(int $year, float $annualLeviablePayroll): array
    {
        $spends = LmsBbeeTrainingSpend::where('spend_year', $year)->get();

        $totalSpend        = $spends->sum('cost');
        $blackSpend        = $spends->filter(fn($s) => $s->isBlack())->sum('cost');
        $blackFemaleSpend  = $spends->filter(fn($s) => $s->isBlackFemale())->sum('cost');
        $disabledSpend     = $spends->filter(fn($s) => $s->has_disability)->sum('cost');
        $accreditedSpend   = $spends->where('is_accredited', true)->sum('cost');

        // Learnership sub-indicators 4 & 5: count only black (A/C/I) learners
        // per 2013 Amended Codes of Good Practice — SD element targets historically
        // disadvantaged individuals.
        $learnershipsEnrolled  = $spends->where('is_learnership', true)->filter(fn($s) => $s->isBlack())->count();
        $learnershipsAbsorbed  = $spends->where('is_learnership', true)->where('is_absorbed', true)->filter(fn($s) => $s->isBlack())->count();

        $spendPct         = $annualLeviablePayroll > 0
            ? ($totalSpend / $annualLeviablePayroll) * 100
            : 0;
        $blackPct         = $totalSpend > 0 ? ($blackSpend / $totalSpend) * 100 : 0;
        $blackFemalePct   = $totalSpend > 0 ? ($blackFemaleSpend / $totalSpend) * 100 : 0;

        // ── Sub-indicator 1: SD Expenditure ≥ 6% of leviable payroll (max 6 pts) ──
        // 2013 Amended Codes Statement 400 Generic Scorecard
        $sub1 = min(6.0, round(($spendPct / self::SPEND_TARGET_PCT) * 6, 2));

        // ── Sub-indicator 2: SD Expenditure on Black employees ≥ 85% of spend (max 2 pts) ──
        $sub2 = min(2.0, round(($blackPct / 85) * 2, 2));

        // ── Sub-indicator 3: SD Expenditure on Black Female employees ≥ 40% (max 2 pts) ────
        $sub3 = min(2.0, round(($blackFemalePct / 40) * 2, 2));

        // ── Sub-indicator 4: Black employees in learnerships/apprenticeships (max 5 pts) ──
        $sub4 = min(5.0, round($learnershipsEnrolled * 0.5, 2));

        // ── Sub-indicator 5: Black learners absorbed post-learnership (max 5 pts) ──────────
        $absorptionRate = $learnershipsEnrolled > 0
            ? $learnershipsAbsorbed / $learnershipsEnrolled
            : 0;
        $sub5 = min(5.0, round($absorptionRate * 5, 2));

        $earnedPoints = $sub1 + $sub2 + $sub3 + $sub4 + $sub5;
        $maxPoints    = self::SD_PRIORITY_ELEMENT_POINTS;

        // ── Sub-minimum threshold: 40% of 20 pts = 8 pts ─────────────────────
        // Under priority element rules a breach triggers a 1-level B-BBEE discount
        $subMinimumThreshold = $maxPoints * 0.40;
        $subMinimumBreach    = $earnedPoints < $subMinimumThreshold;

        $complianceLevel = match(true) {
            $earnedPoints >= 20 => 'Level 1',
            $earnedPoints >= 16 => 'Level 2',
            $earnedPoints >= 12 => 'Level 3',
            $earnedPoints >= 8  => 'Level 4',
            $earnedPoints >= 6  => 'Level 5',
            default             => 'Non-compliant',
        };

        return [
            'year'                    => $year,
            'annual_leviable_payroll' => $annualLeviablePayroll,
            'total_spend'             => round($totalSpend, 2),
            'black_spend'             => round($blackSpend, 2),
            'black_female_spend'      => round($blackFemaleSpend, 2),
            'disabled_spend'          => round($disabledSpend, 2),
            'accredited_spend'        => round($accreditedSpend, 2),
            'learnerships_enrolled'   => $learnershipsEnrolled,
            'learnerships_absorbed'   => $learnershipsAbsorbed,
            'spend_pct_of_payroll'    => round($spendPct, 2),
            'black_pct_of_spend'      => round($blackPct, 2),
            'black_female_pct'        => round($blackFemalePct, 2),
            // Sub-indicator breakdown
            'sub1_spend_points'       => $sub1,
            'sub2_black_points'       => $sub2,
            'sub3_black_female_points'=> $sub3,
            'sub4_learnership_points' => $sub4,
            'sub5_absorption_points'  => $sub5,
            'earned_points'           => round($earnedPoints, 2),
            'max_points'              => $maxPoints,
            'sub_minimum_threshold'   => $subMinimumThreshold,
            'sub_minimum_breach'      => $subMinimumBreach,
            'compliance_level'        => $complianceLevel,
            'demographic_breakdown'   => $this->demographicBreakdown($spends),
        ];
    }

    /**
     * Breakdown of training spend by demographic category.
     */
    private function demographicBreakdown($spends): array
    {
        $buckets = [
            'african_male'    => 0, 'african_female'   => 0,
            'coloured_male'   => 0, 'coloured_female'  => 0,
            'indian_male'     => 0, 'indian_female'    => 0,
            'white_male'      => 0, 'white_female'     => 0,
            'disabled'        => 0, 'other'            => 0,
        ];

        foreach ($spends as $s) {
            $bucket = $s->getDemographicBucket();
            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + $s->cost;
        }

        return array_map(fn($v) => round($v, 2), $buckets);
    }

    private function mapRace(?string $ethnicity): string
    {
        return match(strtolower($ethnicity ?? '')) {
            'african', 'black' => 'A',
            'coloured'         => 'C',
            'indian', 'asian'  => 'I',
            'white'            => 'W',
            default            => 'N',
        };
    }

    private function isSaCitizen(HRPerson $person): bool
    {
        $citizenship = strtolower($person->citizenship ?? 'south african');
        return in_array($citizenship, ['south african', 'sa', 'rsa', '']);
    }
}
