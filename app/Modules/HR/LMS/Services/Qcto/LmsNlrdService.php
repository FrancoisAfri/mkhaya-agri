<?php

namespace App\Modules\HR\LMS\Services\Qcto;

use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment;
use App\Modules\HR\LMS\Models\Qcto\LmsNlrdSubmission;
use App\Modules\HR\LMS\Models\Qcto\LmsPoePortfolio;

class LmsNlrdService
{
    // ── Public entry points ───────────────────────────────────────────────────

    public function buildExport(LmsNlrdSubmission $submission): array
    {
        return match($submission->submission_type) {
            'learner_achievement' => $this->buildAchievementExport($submission),
            'learner_enrolment'   => $this->buildEnrolmentExport($submission),
            default               => [],
        };
    }

    /**
     * Learner enrolment export — skills programmes and learnerships.
     */
    public function buildEnrolmentExport(LmsNlrdSubmission $submission): array
    {
        $rows = [];

        // Skills programme enrolments
        $enrollments = LmsEnrollment::with(['user.person', 'course'])
            ->whereBetween('enrolled_at', [$submission->period_start, $submission->period_end])
            ->whereHas('course', fn($q) => $q->where('is_qcto_registered', true))
            ->get();

        foreach ($enrollments as $e) {
            $person = optional($e->user)->person;
            if (!$person) continue;

            $rows[] = $this->buildLearnerRow($person, $e->course, [
                'enrolment_date'  => $e->enrolled_at?->format('Y-m-d'),
                'completion_date' => $e->completed_at?->format('Y-m-d'),
                'result'          => $e->status === 'completed' ? 'C' : 'E',
                'saqa_learner_id' => null,
                'seta_learner_id' => null,
            ]);
        }

        // Learnership enrolments
        $learnershipEnrolments = LmsLearnershipEnrolment::with(['hrPerson', 'learnership'])
            ->whereBetween('start_date', [$submission->period_start, $submission->period_end])
            ->get();

        foreach ($learnershipEnrolments as $le) {
            $person = $le->hrPerson;
            if (!$person) continue;

            $rows[] = [
                'record_type'     => 'LEARNERSHIP',
                'id_number'       => $person->id_number ?? '',
                'date_of_birth'   => $this->extractDobFromId($person->id_number),
                'first_name'      => $person->first_name ?? '',
                'last_name'       => $person->surname ?? '',
                'race'            => $this->mapRace($person->ethnicity),
                'gender'          => strtolower($person->gender ?? ''),
                'disability'      => $person->disabled ? 'Y' : 'N',
                'saqa_qual_id'    => $le->learnership->saqa_id ?? '',
                'ofo_code'        => $le->learnership->ofo_code ?? '',
                'nqf_level'       => $le->learnership->nqf_level ?? '',
                'credits'         => $le->learnership->total_credits ?? '',
                'seta_learner_id' => $le->seta_learner_id ?? '',
                'enrolment_date'  => $le->start_date?->format('Y-m-d'),
                'completion_date' => $le->we_completed_at?->format('Y-m-d'),
                'result'          => $le->passed === true ? 'C' : ($le->passed === false ? 'F' : 'E'),
            ];
        }

        return $rows;
    }

    /**
     * GAP 2 — Learner achievement export from competent PoE portfolios.
     * Maps to NLRD learner_results format per SAQA spec.
     */
    public function buildAchievementExport(LmsNlrdSubmission $submission): array
    {
        $rows = [];

        $portfolios = LmsPoePortfolio::with(['hrPerson', 'course', 'assessor'])
            ->where('assessment_decision', 'competent')
            ->whereBetween('assessed_at', [$submission->period_start, $submission->period_end])
            ->whereNotNull('hr_person_id')
            ->get();

        foreach ($portfolios as $portfolio) {
            $person = $portfolio->hrPerson;
            if (!$person) continue;

            $rows[] = [
                'record_type'     => 'ACHIEVEMENT',
                'id_number'       => $person->id_number ?? '',
                'date_of_birth'   => $this->extractDobFromId($person->id_number),
                'first_name'      => $person->first_name ?? '',
                'last_name'       => $person->surname ?? '',
                'race'            => $this->mapRace($person->ethnicity),
                'gender'          => strtolower($person->gender ?? ''),
                'disability'      => $person->disabled ? 'Y' : 'N',
                'saqa_qual_id'    => $portfolio->course?->saqa_id ?? '',
                'ofo_code'        => $portfolio->course?->ofo_code ?? '',
                'nqf_level'       => $portfolio->course?->nqf_level ?? '',
                'credits'         => $portfolio->course?->credits ?? '',
                'seta_learner_id' => '',
                'enrolment_date'  => $portfolio->created_at?->format('Y-m-d'),
                'completion_date' => $portfolio->assessed_at?->format('Y-m-d'),
                'result'          => 'C',
                'portfolio_id'    => $portfolio->id,
                'assessor_ref'    => $portfolio->assessor?->registration_number ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Generate CSV for NLRD submission.
     */
    public function generateCsv(LmsNlrdSubmission $submission): string
    {
        $rows = $this->buildExport($submission);

        if (empty($rows)) return '';

        $header = array_keys($rows[0]);
        $lines  = [implode(',', $header)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                fn($v) => '"' . str_replace('"', '""', (string) $v) . '"',
                $row
            ));
        }

        return implode("\n", $lines);
    }

    /**
     * Validate the records before submission — returns array of errors.
     * GAP 6: SA ID format validation.
     * GAP 5 (NLRD): seta_learner_id required for learnerships.
     * GAP 7: credit mismatch warning for learnerships.
     */
    public function validate(LmsNlrdSubmission $submission): array
    {
        $rows   = $this->buildExport($submission);
        $errors = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2;

            if (empty($row['id_number'])) {
                $errors[] = "Row {$line}: Missing ID number";
            } elseif (!$this->validateSaIdFormat($row['id_number'])) {
                $errors[] = "Row {$line}: Invalid SA ID number \"{$row['id_number']}\" — must be 13 digits with valid date, citizenship digit (0/1), and Luhn checksum";
            }

            if (empty($row['saqa_qual_id'])) {
                $errors[] = "Row {$line}: Missing SAQA qualification ID";
            }

            if (empty($row['enrolment_date'])) {
                $errors[] = "Row {$line}: Missing enrolment date";
            }

            if (($row['record_type'] ?? '') === 'LEARNERSHIP' && empty($row['seta_learner_id'])) {
                $errors[] = "Row {$line}: Learnership record missing SETA learner ID — required for NLRD submission";
            }
        }

        // GAP 7 — Credit mismatch check for learnerships in this period
        $learnershipEnrolments = \App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment::with(['learnership.knowledgeCourse', 'learnership.practicalCourse'])
            ->whereBetween('start_date', [$submission->period_start, $submission->period_end])
            ->get();

        foreach ($learnershipEnrolments as $le) {
            if ($le->learnership?->hasCreditMismatch()) {
                $errors[] = "Credit mismatch on learnership \"{$le->learnership->title}\" (ID {$le->learnership_id}): K+P component credits (" . $le->learnership->getTotalComponentCredits() . ") do not equal total_credits ({$le->learnership->total_credits}). Correct before SETA submission.";
            }
        }

        return $errors;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildLearnerRow($person, $course, array $extra): array
    {
        return array_merge([
            'record_type'     => 'SKILLS_PROGRAMME',
            'id_number'       => $person->id_number ?? '',
            'date_of_birth'   => $this->extractDobFromId($person->id_number),
            'first_name'      => $person->first_name ?? '',
            'last_name'       => $person->surname ?? '',
            'race'            => $this->mapRace($person->ethnicity),
            'gender'          => strtolower($person->gender ?? ''),
            'disability'      => $person->disabled ? 'Y' : 'N',
            'saqa_qual_id'    => $course->saqa_id ?? '',
            'ofo_code'        => $course->ofo_code ?? '',
            'nqf_level'       => $course->nqf_level ?? '',
            'credits'         => $course->credits ?? '',
        ], $extra);
    }

    /**
     * Extract date_of_birth from a 13-digit SA ID number (YYMMDD → YYYY-MM-DD).
     * Returns empty string if the ID is missing or invalid.
     */
    private function extractDobFromId(?string $id): string
    {
        if (!$id || !preg_match('/^\d{13}$/', preg_replace('/\s+/', '', $id))) {
            return '';
        }
        $yy    = (int) substr($id, 0, 2);
        $mm    = substr($id, 2, 2);
        $dd    = substr($id, 4, 2);
        $year  = $yy <= (int) date('y') ? 2000 + $yy : 1900 + $yy;
        return sprintf('%04d-%s-%s', $year, $mm, $dd);
    }

    /**
     * GAP 6 — Validate a South African ID number.
     * Rules: 13 digits, YYMMDD is a valid date, citizenship digit 0 or 1, Luhn check.
     */
    private function validateSaIdFormat(string $id): bool
    {
        $id = preg_replace('/\s+/', '', $id);

        if (!preg_match('/^\d{13}$/', $id)) {
            return false;
        }

        // Date component (YYMMDD)
        $year  = (int) substr($id, 0, 2);
        $month = (int) substr($id, 2, 2);
        $day   = (int) substr($id, 4, 2);

        if ($month < 1 || $month > 12) return false;
        if ($day < 1 || $day > 31)     return false;

        // Citizenship digit must be 0 (SA citizen) or 1 (permanent resident)
        $citizenDigit = (int) $id[10];
        if (!in_array($citizenDigit, [0, 1], true)) return false;

        // Luhn checksum (digit 13 = check digit)
        return $this->luhnCheck($id);
    }

    private function luhnCheck(string $number): bool
    {
        $sum    = 0;
        $nDigits = strlen($number);
        $parity  = $nDigits % 2;

        for ($i = 0; $i < $nDigits; $i++) {
            $digit = (int) $number[$i];
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) $digit -= 9;
            }
            $sum += $digit;
        }

        return ($sum % 10) === 0;
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
}
