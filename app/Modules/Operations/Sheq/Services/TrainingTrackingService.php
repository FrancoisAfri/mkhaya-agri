<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Safety training visibility, read from the existing Learning Management module.
 *
 * **This module deliberately stores no training records of its own.** Courses, enrolments,
 * completions, certificates, SETA accreditation and expiry all live in LMS, which already does that
 * job properly. A SHEQ-local copy would be a second version of the truth, and the two would
 * disagree within a month — at which point a safety officer and a training officer produce
 * different compliance figures for the same workforce and neither can say which is right.
 *
 * So this reads. What it adds is the SHEQ question that LMS does not ask: *is this person trained
 * for the hazardous work they are about to do, and is that training still current?* LMS knows
 * somebody completed a forklift course; SHEQ knows a forklift licence is a competency that blocks
 * work when it lapses. Joining the two is the entire purpose of this class.
 *
 * Where LMS is not installed, every method degrades to an empty result rather than throwing. A
 * self-hosted customer who bought SHEQ without LMS should see "no training data" on a dashboard,
 * not a stack trace.
 */
class TrainingTrackingService
{
    public function isAvailable(): bool
    {
        return Schema::hasTable('lms_enrollments') && Schema::hasTable('lms_courses');
    }

    /**
     * A person's safety training, current and lapsed.
     *
     * @return list<array<string,mixed>>
     */
    public function forPerson(int $companyId, int $userId): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return DB::table('lms_enrollments as e')
            ->join('lms_courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.company_id', $companyId)
            ->where('e.user_id', $userId)
            ->whereNull('e.deleted_at')
            ->orderByDesc('e.completed_at')
            ->get(['e.uuid', 'e.status', 'e.progress_pct', 'e.score', 'e.completed_at',
                'e.due_date', 'c.title', 'c.is_mandatory', 'c.expires_after_days',
                'c.unit_standard_code', 'c.nqf_level'])
            ->map(function ($row) {
                // Expiry is a property of the course, not of the enrolment, so it is computed
                // rather than read — and a course with no expiry never lapses.
                $expiresOn = $row->completed_at !== null && $row->expires_after_days
                    ? Carbon::parse($row->completed_at)->addDays((int) $row->expires_after_days)
                    : null;

                return [
                    'course' => $row->title,
                    'status' => $row->status,
                    'progress_pct' => (int) $row->progress_pct,
                    'score' => $row->score === null ? null : (float) $row->score,
                    'completed_at' => $row->completed_at,
                    'due_date' => $row->due_date,
                    'expires_on' => $expiresOn?->toDateString(),
                    'expired' => $expiresOn !== null && $expiresOn->isPast(),
                    'is_mandatory' => (bool) $row->is_mandatory,
                    'unit_standard' => $row->unit_standard_code,
                    'nqf_level' => $row->nqf_level,
                ];
            })->values()->all();
    }

    /**
     * Training compliance across the workforce — the dashboard figure.
     *
     * Counts mandatory courses only. Including optional ones would make the percentage move
     * whenever somebody publishes a new elective, which is exactly the kind of number that gets
     * quietly ignored because it never means anything.
     *
     * @return array{assigned:int, completed:int, overdue:int, expired:int, compliance_pct:float}
     */
    public function compliance(int $companyId): array
    {
        if (! $this->isAvailable()) {
            return ['assigned' => 0, 'completed' => 0, 'overdue' => 0, 'expired' => 0,
                'compliance_pct' => 0.0];
        }

        $today = Carbon::today();

        $rows = DB::table('lms_enrollments as e')
            ->join('lms_courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.company_id', $companyId)
            ->where('c.is_mandatory', true)
            ->whereNull('e.deleted_at')
            ->get(['e.status', 'e.completed_at', 'e.due_date', 'c.expires_after_days']);

        $assigned = $rows->count();
        $completed = 0;
        $overdue = 0;
        $expired = 0;

        foreach ($rows as $row) {
            $isComplete = $row->completed_at !== null;

            if (! $isComplete) {
                if ($row->due_date !== null && Carbon::parse($row->due_date)->lt($today)) {
                    $overdue++;
                }

                continue;
            }

            if ($row->expires_after_days
                && Carbon::parse($row->completed_at)->addDays((int) $row->expires_after_days)->lt($today)) {
                // Lapsed training is not training. Counting it as complete is how a compliance
                // figure stays green while half the certificates behind it have expired.
                $expired++;

                continue;
            }

            $completed++;
        }

        return [
            'assigned' => $assigned,
            'completed' => $completed,
            'overdue' => $overdue,
            'expired' => $expired,
            'compliance_pct' => $assigned === 0 ? 0.0 : round(($completed / $assigned) * 100, 1),
        ];
    }

    /**
     * Mandatory training that has lapsed or is about to.
     *
     * @return list<array<string,mixed>>
     */
    public function expiringWithin(int $companyId, int $days = 60): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $today = Carbon::today();
        $horizon = $today->copy()->addDays($days);

        return DB::table('lms_enrollments as e')
            ->join('lms_courses as c', 'c.id', '=', 'e.course_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('e.company_id', $companyId)
            ->where('c.is_mandatory', true)
            ->whereNotNull('e.completed_at')
            ->whereNotNull('c.expires_after_days')
            ->whereNull('e.deleted_at')
            ->get(['e.completed_at', 'c.title', 'c.expires_after_days',
                'p.first_name', 'p.surname', 'e.user_id'])
            ->map(function ($row) {
                $expiresOn = Carbon::parse($row->completed_at)
                    ->addDays((int) $row->expires_after_days);

                return [
                    'course' => $row->title,
                    'person' => trim(($row->first_name ?? '').' '.($row->surname ?? '')) ?: '—',
                    'user_id' => (int) $row->user_id,
                    'expires_on' => $expiresOn->toDateString(),
                    'expired' => $expiresOn->isPast(),
                    '_sort' => $expiresOn->timestamp,
                ];
            })
            ->filter(fn ($row) => Carbon::parse($row['expires_on'])->lte($horizon))
            ->sortBy('_sort')
            ->map(function ($row) {
                unset($row['_sort']);

                return $row;
            })
            ->values()->all();
    }

    /**
     * Whether somebody's mandatory safety training is current.
     *
     * The question a permit or a task assignment actually needs answered.
     *
     * @return array{cleared:bool, reasons:list<string>}
     */
    public function isTrainedForWork(int $companyId, int $userId): array
    {
        $reasons = [];

        foreach ($this->forPerson($companyId, $userId) as $record) {
            if (! $record['is_mandatory']) {
                continue;
            }

            if ($record['completed_at'] === null) {
                $reasons[] = "{$record['course']} is not complete.";

                continue;
            }

            if ($record['expired']) {
                $reasons[] = "{$record['course']} expired on {$record['expires_on']}.";
            }
        }

        return ['cleared' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * Enrol the holders of lapsing competencies in their refresher course (REG-FIX-7).
     *
     * The gate and the course reference both existed: a competency blocks work when it expires,
     * and `sheq_competencies.lms_course_id` names the course that renews it. Nothing joined them,
     * so the system knew a forklift licence would lapse on the 14th, knew which course renews it,
     * and told nobody to book it. The person found out at a boom gate.
     *
     * Four rules, each of which is a way this could have become a nuisance instead of a control:
     *
     * **Only where a course is named.** A competency with no `lms_course_id` is renewed by an
     * external provider, and enrolling somebody in nothing would produce a to-do they cannot
     * action.
     *
     * **Only people with a login.** `lms_enrollments` keys on `users.id` while a competency
     * record keys on `hr_people.id`, and a contractor worker has neither. They are chased through
     * `ContractorNotifier` instead, which is the channel they can actually receive.
     *
     * **Never twice.** An existing OPEN enrolment for the same course is left alone — including
     * one somebody created by hand. Re-enrolling resets progress, so a daily sweep would wipe the
     * work of anybody halfway through the course it just told them to take. A COMPLETED one is
     * re-opened instead of duplicated, because the LMS keeps one enrolment row per person per
     * course and completion evidence lives in `lms_certificates`.
     *
     * **Due before the expiry, not on it.** A refresher due the day a licence lapses is a
     * refresher completed a day late. Seven days earlier, or today if the lapse is nearer than
     * that.
     *
     * @return array{enrolled:int, skipped_no_course:int, skipped_no_account:int,
     *     skipped_already:int}
     */
    public function enrolRefreshers(int $companyId, int $withinDays = 60,
        ?int $actorId = null): array
    {
        $result = ['enrolled' => 0, 'skipped_no_course' => 0, 'skipped_no_account' => 0,
            'skipped_already' => 0];

        if (! $this->isAvailable()) {
            // LMS is not installed. Not a failure: a customer who bought SHEQ without LMS renews
            // competencies through whatever they already use.
            return $result;
        }

        $today = Carbon::today();

        $due = DB::table('sheq_competency_records as r')
            ->join('sheq_competencies as k', 'k.id', '=', 'r.competency_id')
            ->leftJoin('hr_people as p', 'p.id', '=', 'r.hr_person_id')
            ->where('r.company_id', $companyId)
            ->whereNull('r.deleted_at')->whereNull('r.revoked_at')
            ->whereNotNull('r.achieved_at')
            // The same four conditions `deriveStatus()` uses for a record that is in force. A
            // lapsed-and-already-replaced record must not trigger a second enrolment.
            ->whereNotIn('r.status', ['EXEMPT', 'IN_PROGRESS', 'FAILED'])
            ->where(fn ($q) => $q->whereNull('r.appointment_scope')
                ->orWhereNotNull('r.accepted_at'))
            ->whereNotNull('r.expires_at')
            ->whereBetween('r.expires_at', [
                // A short lookback so a licence that lapsed last week still gets its refresher
                // booked, without dredging up years of history on first run.
                $today->copy()->subDays(14)->toDateString(),
                $today->copy()->addDays($withinDays)->toDateString(),
            ])
            ->get(['r.id as record_id', 'r.expires_at', 'r.hr_person_id',
                'k.id as competency_id', 'k.code', 'k.title', 'k.lms_course_id',
                'k.blocks_work_when_expired', 'p.user_id']);

        foreach ($due as $row) {
            if ($row->lms_course_id === null) {
                $result['skipped_no_course']++;

                continue;
            }

            if ($row->user_id === null) {
                $result['skipped_no_account']++;

                continue;
            }

            // Does the course still exist and belong here? A competency pointing at a deleted
            // course would otherwise insert an enrolment nobody can open.
            $course = DB::table('lms_courses')
                ->where('id', $row->lms_course_id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
                ->first(['id', 'title']);

            if ($course === null) {
                $result['skipped_no_course']++;

                continue;
            }

            /*
             * `lms_enrollments` is UNIQUE on (user_id, course_id): one row per person per course,
             * for ever. So an annually renewed competency cannot get a fresh enrolment each year
             * — the LMS's model is that the row is reused, and a second insert is refused by the
             * database rather than by this code.
             *
             * That makes three cases, not two:
             *
             *   - an open enrolment: leave it completely alone. Resetting it would wipe the
             *     progress of somebody halfway through the course this would have booked.
             *   - a COMPLETED enrolment: re-open it for the new cycle. Safe, because completion
             *     evidence lives in `lms_certificates` with its own issue and expiry dates, so
             *     reopening the enrolment does not erase the fact that they passed last year.
             *   - nothing: insert.
             */
            $existing = DB::table('lms_enrollments')
                ->where('company_id', $companyId)
                ->where('user_id', $row->user_id)
                ->where('course_id', $course->id)
                ->whereNull('deleted_at')
                ->first(['id', 'completed_at']);

            if ($existing !== null && $existing->completed_at === null) {
                $result['skipped_already']++;

                continue;
            }

            $expiry = Carbon::parse($row->expires_at);

            $dueDate = $expiry->copy()->subDays(7);

            if ($dueDate->lt($today)) {
                // Subtracting seven days from an imminent expiry puts the due date in the past,
                // so the enrolment would arrive already overdue and the person would be chased
                // for being late to something booked today.
                $dueDate = $today->copy();
            }

            if ($existing !== null) {
                DB::table('lms_enrollments')->where('id', $existing->id)->update([
                    'status' => 'enrolled',
                    'progress_pct' => 0,
                    'score' => null,
                    'enrolled_at' => now(),
                    'started_at' => null,
                    'completed_at' => null,
                    'due_date' => $dueDate->toDateString(),
                    'time_spent_mins' => 0,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('lms_enrollments')->insert([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'user_id' => $row->user_id,
                    'course_id' => $course->id,
                    'status' => 'enrolled',
                    'progress_pct' => 0,
                    'enrolled_at' => now(),
                    'due_date' => $dueDate->toDateString(),
                    'time_spent_mins' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            /*
             * Audited, and against the competency record rather than the enrolment.
             *
             * The question somebody asks later is "why am I booked on this course", and the
             * answer is a competency expiring on a date — not an enrolment id. Recording it the
             * other way round would leave the LMS row looking like somebody chose it.
             */
            DB::table('sheq_audit_log')->insert([
                'company_id' => $companyId,
                'actor_id' => $actorId,
                'actor_type' => $actorId === null ? 'SYSTEM' : 'USER',
                'action' => 'COMPETENCY_REFRESHER_ENROLLED',
                'subject_type' => 'COMPETENCY_RECORD',
                'subject_id' => (int) $row->record_id,
                'after' => json_encode([
                    'competency' => $row->code,
                    'course_id' => (int) $course->id,
                    'course' => $course->title,
                    'expires_at' => $row->expires_at,
                    'due_date' => $dueDate->toDateString(),
                    'blocks_work' => (bool) $row->blocks_work_when_expired,
                    'reopened' => $existing !== null,
                ]),
                'reason' => sprintf('%s expires on %s', $row->title, $row->expires_at),
            ]);

            $result['enrolled']++;
        }

        return $result;
    }
}
