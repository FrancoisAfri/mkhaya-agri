<?php

namespace App\Modules\HR\LMS\Services\Qcto;

use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\Qcto\LmsAtrItem;
use App\Modules\HR\LMS\Models\Qcto\LmsAtrReport;
use App\Modules\HR\LMS\Models\Qcto\LmsWspPlan;

class LmsWspAtrService
{
    /**
     * Auto-populate WSP items from the course catalogue.
     * Uses existing compliance rules + mandatory courses to suggest planned training.
     */
    public function suggestWspItems(LmsWspPlan $plan): array
    {
        $courses = LmsCourse::where('is_mandatory', true)
            ->orWhere('is_qcto_registered', true)
            ->get(['id', 'title', 'nqf_level', 'credits', 'ofo_code', 'saqa_id']);

        $items = [];
        foreach ($courses as $course) {
            $items[] = [
                'wsp_plan_id'          => $plan->id,
                'course_id'            => $course->id,
                'training_type'        => 'skills_programme',
                'training_description' => $course->title,
                'nqf_level'            => $course->nqf_level,
                'credits'              => $course->credits,
                'ofo_code'             => $course->ofo_code,
                'saqa_id'              => $course->saqa_id,
                'planned_beneficiaries'=> 0,
                'planned_cost_per_person'=> 0,
                'total_planned_cost'   => 0,
            ];
        }

        return $items;
    }

    /**
     * Generate ATR items from completed enrollments in the report period.
     */
    public function generateAtrItems(LmsAtrReport $report): int
    {
        $enrollments = LmsEnrollment::with(['user.person', 'course'])
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$report->period_start, $report->period_end])
            ->get();

        $count = 0;
        foreach ($enrollments as $enrollment) {
            $person = optional($enrollment->user)->person;
            if (!$person) continue;

            LmsAtrItem::create([
                'atr_report_id'               => $report->id,
                'hr_person_id'                => $person->id,
                'course_id'                   => $enrollment->course_id,
                'training_type'               => $enrollment->course->is_qcto_registered
                    ? 'skills_programme'
                    : 'short_course',
                'training_provider'           => 'Internal',
                'provider_accreditation_number'=> $enrollment->course->seta_accreditation_number,
                'start_date'                  => $enrollment->started_at ?? $enrollment->enrolled_at,
                'end_date'                    => $enrollment->completed_at,
                'nqf_level'                   => $enrollment->course->nqf_level,
                'credits'                     => $enrollment->course->credits,
                'cost_per_person'             => 0,
                'result'                      => $enrollment->score >= ($enrollment->course->pass_score ?? 0)
                    ? 'completed'
                    : 'failed',
                'ofo_code'                    => $enrollment->course->ofo_code,
                'saqa_id'                     => $enrollment->course->saqa_id,
                'race'                        => $this->mapEthnicity($person->ethnicity),
                'gender'                      => strtolower($person->gender ?? 'male'),
                'has_disability'              => (bool) $person->disabled,
            ]);
            $count++;
        }

        // Update total actual spend and calculate spend % of leviable payroll
        $total = $report->items()->sum('cost_per_person');

        $report->loadMissing('wspPlan');
        $levy          = (float) ($report->wspPlan?->total_levy_paid ?? 0);
        $annualPayroll = $levy > 0 ? $levy / 0.01 : 0;
        $actualSpendPct = $annualPayroll > 0 ? round(($total / $annualPayroll) * 100, 2) : 0;

        $report->update([
            'total_actual_spend' => $total,
            'actual_spend_pct'   => $actualSpendPct,
        ]);

        return $count;
    }

    /**
     * Calculate WSP planned spend percentage of payroll.
     */
    public function calculateSpendPct(LmsWspPlan $plan): float
    {
        if (!$plan->total_levy_paid || $plan->total_levy_paid <= 0) return 0;
        // Levy is 1% of payroll; mandatory grant = 20% of levy
        // Planned spend as % of annual payroll = planned_spend / (levy_paid / 0.01)
        $annualPayroll = $plan->total_levy_paid / 0.01;
        return $annualPayroll > 0
            ? round(($plan->total_planned_spend / $annualPayroll) * 100, 2)
            : 0;
    }

    /**
     * Export WSP as CSV in SETA-compatible format.
     */
    // Official SETA demographic group keys used in WSP/ATR submissions
    const WSP_DEMOGRAPHIC_COLUMNS = [
        'african_male'    => 'African Male',
        'african_female'  => 'African Female',
        'coloured_male'   => 'Coloured Male',
        'coloured_female' => 'Coloured Female',
        'indian_male'     => 'Indian/Asian Male',
        'indian_female'   => 'Indian/Asian Female',
        'white_male'      => 'White Male',
        'white_female'    => 'White Female',
        'disabled'        => 'People with Disabilities',
        'foreign'         => 'Foreign Nationals',
    ];

    public function exportWspCsv(LmsWspPlan $plan): string
    {
        // Ensure demographics are loaded to avoid N+1
        $plan->loadMissing('items.demographics');

        $lines = [];
        $lines[] = implode(',', array_merge(
            ['Training Type', 'Description', 'NQF Level', 'Credits', 'OFO Code', 'SAQA ID'],
            array_values(self::WSP_DEMOGRAPHIC_COLUMNS),
            ['Total Planned', 'Cost Per Person', 'Total Cost']
        ));

        foreach ($plan->items as $item) {
            $demoCounts = [];

            if ($item->demographics->isNotEmpty()) {
                // New path: use the per-demographic breakdown table.
                $byKey = $item->demographics->pluck('beneficiary_count', 'demographic_key');
                foreach (array_keys(self::WSP_DEMOGRAPHIC_COLUMNS) as $key) {
                    $demoCounts[] = (int) ($byKey[$key] ?? 0);
                }
            } else {
                // Legacy path: single target_group → all beneficiaries in that column.
                foreach (array_keys(self::WSP_DEMOGRAPHIC_COLUMNS) as $key) {
                    $demoCounts[] = ($item->target_group === $key) ? (int) $item->planned_beneficiaries : 0;
                }
            }

            $lines[] = implode(',', array_merge(
                [
                    $item->training_type,
                    '"' . str_replace('"', '""', $item->training_description ?? '') . '"',
                    $item->nqf_level ?? '',
                    $item->credits ?? '',
                    $item->ofo_code ?? '',
                    $item->saqa_id ?? '',
                ],
                $demoCounts,
                [
                    $item->planned_beneficiaries,
                    $item->planned_cost_per_person,
                    $item->total_planned_cost,
                ]
            ));
        }

        return implode("\n", $lines);
    }

    /**
     * Export ATR as CSV in SETA-compatible format.
     */
    public function exportAtrCsv(LmsAtrReport $report): string
    {
        $lines = [];
        $lines[] = implode(',', [
            'Employee ID', 'First Name', 'Last Name',
            'ID Number', 'Race', 'Gender', 'Disability',
            'Training Type', 'Provider', 'Provider Accreditation No', 'Start Date', 'End Date',
            'NQF Level', 'Credits', 'Cost', 'Result',
            'Certificate Number', 'OFO Code', 'SAQA ID',
        ]);

        foreach ($report->items()->with('hrPerson')->get() as $item) {
            $person = $item->hrPerson;
            $lines[] = implode(',', [
                $person->employee_number ?? $person->id,
                '"' . ($person->first_name ?? '') . '"',
                '"' . ($person->surname ?? '') . '"',
                $person->id_number ?? '',
                $item->race ?? '',
                $item->gender ?? '',
                $item->has_disability ? 'Yes' : 'No',
                $item->training_type,
                '"' . ($item->training_provider ?? '') . '"',
                $item->provider_accreditation_number ?? '',
                $item->start_date?->format('Y-m-d') ?? '',
                $item->end_date?->format('Y-m-d') ?? '',
                $item->nqf_level ?? '',
                $item->credits ?? '',
                $item->cost_per_person,
                $item->result,
                $item->certificate_number ?? '',
                $item->ofo_code ?? '',
                $item->saqa_id ?? '',
            ]);
        }

        return implode("\n", $lines);
    }

    private function mapEthnicity(?string $ethnicity): string
    {
        return match(strtolower($ethnicity ?? '')) {
            'african', 'black'   => 'A',
            'coloured'           => 'C',
            'indian', 'asian'    => 'I',
            'white'              => 'W',
            default              => 'N',
        };
    }
}
