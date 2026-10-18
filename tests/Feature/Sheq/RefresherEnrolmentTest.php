<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\TrainingTrackingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Refresher course auto-enrolment (REG-FIX-7).
 *
 * Both halves of this existed and nothing joined them. A competency blocks work when it expires,
 * and `sheq_competencies.lms_course_id` names the course that renews it — so the system knew a
 * forklift licence would lapse on the 14th, knew which course renews it, and told nobody to book
 * it. The person found out at a boom gate.
 *
 * The tests below are mostly about the ways automatic enrolment turns into a nuisance, because a
 * nuisance gets switched off and then the control is gone:
 *
 *   - re-enrolling somebody every night, wiping the progress of anybody halfway through
 *   - enrolling people in a course that does not exist, or one belonging to another tenant
 *   - a refresher due the day the licence lapses, which is a refresher completed a day late
 *   - dredging up every competency that has expired since the module was installed
 */
class RefresherEnrolmentTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        if (! app(TrainingTrackingService::class)->isAvailable()) {
            $this->markTestSkipped('The LMS module is not installed on this database.');
        }

        $this->user = $this->userWith();
        $this->personId = $this->person($this->user->id);
    }

    private function service(): TrainingTrackingService
    {
        return app(TrainingTrackingService::class);
    }

    public function test_a_lapsing_competency_books_its_refresher(): void
    {
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId, blocksWork: true);
        $this->record($competencyId, Carbon::today()->addDays(30));

        $result = $this->service()->enrolRefreshers($this->companyId);

        $this->assertSame(1, $result['enrolled'],
            'The system knows the licence lapses in thirty days and which course renews it, and '
            .'nobody is booked on it.');

        $enrolment = DB::table('lms_enrollments')
            ->where('company_id', $this->companyId)->where('user_id', $this->user->id)
            ->where('course_id', $courseId)->first();

        $this->assertNotNull($enrolment);
        $this->assertSame('enrolled', $enrolment->status);
    }

    public function test_the_refresher_is_due_before_the_licence_lapses_not_on_the_day(): void
    {
        // A refresher due the day a licence expires is a refresher completed a day late.
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $expiry = Carbon::today()->addDays(30);
        $this->record($competencyId, $expiry);

        $this->service()->enrolRefreshers($this->companyId);

        $due = DB::table('lms_enrollments')
            ->where('company_id', $this->companyId)->where('course_id', $courseId)
            ->value('due_date');

        $this->assertSame($expiry->copy()->subDays(7)->toDateString(),
            Carbon::parse($due)->toDateString(),
            'The refresher is due on or after the expiry date, so completing it on time still '
            .'leaves a gap where the person cannot work.');
    }

    public function test_a_refresher_for_an_imminent_lapse_is_due_today_not_in_the_past(): void
    {
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $this->record($competencyId, Carbon::today()->addDays(2));

        $this->service()->enrolRefreshers($this->companyId);

        $due = Carbon::parse(DB::table('lms_enrollments')
            ->where('company_id', $this->companyId)->where('course_id', $courseId)
            ->value('due_date'));

        $this->assertSame(Carbon::today()->toDateString(), $due->toDateString(),
            'Subtracting seven days produced a due date in the past, so the enrolment arrives '
            .'already overdue and the person is chased for being late to something booked today.');
    }

    public function test_running_the_sweep_again_does_not_reset_somebody_halfway_through(): void
    {
        /*
         * The worst possible failure mode here. A nightly sweep that re-enrols wipes the progress
         * of everybody currently taking the course it told them to take — which teaches people to
         * ignore the enrolment.
         */
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $this->record($competencyId, Carbon::today()->addDays(30));

        $this->service()->enrolRefreshers($this->companyId);

        DB::table('lms_enrollments')
            ->where('company_id', $this->companyId)->where('course_id', $courseId)
            ->update(['progress_pct' => 60, 'status' => 'in_progress']);

        for ($i = 0; $i < 3; $i++) {
            $result = $this->service()->enrolRefreshers($this->companyId);
            $this->assertSame(0, $result['enrolled']);
            $this->assertSame(1, $result['skipped_already']);
        }

        $rows = DB::table('lms_enrollments')
            ->where('company_id', $this->companyId)->where('course_id', $courseId)->get();

        $this->assertCount(1, $rows, 'The sweep created a second enrolment for the same course.');
        $this->assertSame(60, (int) $rows[0]->progress_pct,
            'Re-enrolment reset the progress of somebody more than halfway through the course.');
    }

    public function test_an_enrolment_somebody_created_by_hand_is_left_alone(): void
    {
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $this->record($competencyId, Carbon::today()->addDays(30));

        DB::table('lms_enrollments')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'user_id' => $this->user->id, 'course_id' => $courseId,
            'status' => 'enrolled', 'progress_pct' => 0, 'enrolled_at' => now(),
            'time_spent_mins' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service()->enrolRefreshers($this->companyId);

        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(1, DB::table('lms_enrollments')
            ->where('company_id', $this->companyId)->where('course_id', $courseId)->count(),
            'A training officer who booked the course by hand got a duplicate from the sweep.');
    }

    public function test_a_completed_enrolment_does_not_stop_the_next_cycle(): void
    {
        /*
         * The opposite failure: a competency renewed annually needs a new enrolment each year,
         * and matching on "ever enrolled" would book the refresher once and never again.
         */
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $this->record($competencyId, Carbon::today()->addDays(30));

        DB::table('lms_enrollments')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'user_id' => $this->user->id, 'course_id' => $courseId,
            'status' => 'completed', 'progress_pct' => 100,
            'enrolled_at' => now()->subYear(), 'completed_at' => now()->subYear(),
            'time_spent_mins' => 90, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->service()->enrolRefreshers($this->companyId)['enrolled'],
            'Last year\'s completed refresher prevents this year\'s, so the course is booked once '
            .'in the lifetime of the competency.');
    }

    public function test_a_competency_with_no_course_is_counted_not_silently_dropped(): void
    {
        // Renewed by an external provider. Enrolling somebody in nothing would produce a to-do
        // they cannot action, and reporting it as enrolled would be a lie.
        $competencyId = $this->competency('EXTERNAL', null);
        $this->record($competencyId, Carbon::today()->addDays(30));

        $result = $this->service()->enrolRefreshers($this->companyId);

        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(1, $result['skipped_no_course']);
    }

    public function test_a_competency_pointing_at_a_deleted_course_does_not_create_a_dead_enrolment(): void
    {
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $this->record($competencyId, Carbon::today()->addDays(30));

        DB::table('lms_courses')->where('id', $courseId)->update(['deleted_at' => now()]);

        $result = $this->service()->enrolRefreshers($this->companyId);

        $this->assertSame(0, $result['enrolled'],
            'An enrolment was created against a deleted course, which nobody can open.');
        $this->assertSame(1, $result['skipped_no_course']);
    }

    public function test_another_tenants_course_is_not_used(): void
    {
        $other = (int) DB::table('companies')->where('id', '!=', $this->companyId)->min('id');

        if ($other === 0) {
            $this->markTestSkipped('Only one company in the test database.');
        }

        $foreign = (int) DB::table('lms_courses')->insertGetId([
            'uuid' => (string) Str::uuid(), 'title' => 'Theirs', 'company_id' => $other,
            'is_published' => true, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $competencyId = $this->competency('FORKLIFT', $foreign);
        $this->record($competencyId, Carbon::today()->addDays(30));

        $this->assertSame(0, $this->service()->enrolRefreshers($this->companyId)['enrolled'],
            'A competency pointing at another tenant\'s course enrolled somebody in it.');
    }

    public function test_somebody_with_no_login_is_counted_rather_than_enrolled(): void
    {
        /*
         * `lms_enrollments` keys on `users.id` and a competency record keys on `hr_people.id`. A
         * person with no account — and every contractor worker — cannot be enrolled, and is
         * chased through the contractor notices instead.
         */
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $orphan = $this->person(null);
        $this->record($competencyId, Carbon::today()->addDays(30), $orphan);

        $result = $this->service()->enrolRefreshers($this->companyId);

        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(1, $result['skipped_no_account']);
    }

    public function test_a_competency_that_lapsed_years_ago_is_not_dredged_up(): void
    {
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $this->record($competencyId, Carbon::today()->subYears(3));

        $this->assertSame(0, $this->service()->enrolRefreshers($this->companyId)['enrolled'],
            'Switching this on enrols everybody in every course for every competency that has '
            .'expired since the module was installed.');
    }

    public function test_one_that_lapsed_last_week_still_gets_booked(): void
    {
        // They cannot work until it is renewed, so this is the most urgent case there is.
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $this->record($competencyId, Carbon::today()->subDays(5));

        $this->assertSame(1, $this->service()->enrolRefreshers($this->companyId)['enrolled']);
    }

    public function test_a_revoked_record_is_not_refreshed(): void
    {
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $recordId = $this->record($competencyId, Carbon::today()->addDays(30));

        DB::table('sheq_competency_records')->where('id', $recordId)
            ->update(['revoked_at' => now(), 'revocation_reason' => 'Left the company']);

        $this->assertSame(0, $this->service()->enrolRefreshers($this->companyId)['enrolled'],
            'Somebody whose appointment was revoked is booked on a refresher for it.');
    }

    public function test_an_unaccepted_appointment_is_not_refreshed(): void
    {
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $recordId = $this->record($competencyId, Carbon::today()->addDays(30));

        DB::table('sheq_competency_records')->where('id', $recordId)
            ->update(['appointment_scope' => 'SITE', 'accepted_at' => null]);

        $this->assertSame(0, $this->service()->enrolRefreshers($this->companyId)['enrolled'],
            'An appointment letter nobody signed produced a refresher booking, so the first thing '
            .'the person hears about the role is a course they did not agree to.');
    }

    public function test_the_enrolment_says_why_it_exists(): void
    {
        /*
         * The question somebody asks is "why am I booked on this course", and the answer is a
         * competency expiring on a date. Audited against the competency record rather than the
         * enrolment, because recording it the other way round leaves the LMS row looking like
         * somebody chose it.
         */
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId, blocksWork: true);
        $recordId = $this->record($competencyId, Carbon::today()->addDays(30));

        $this->service()->enrolRefreshers($this->companyId);

        $audit = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'COMPETENCY_REFRESHER_ENROLLED')
            ->where('subject_id', $recordId)
            ->first(['after', 'reason', 'actor_type']);

        $this->assertNotNull($audit, 'An automatic enrolment appears with no explanation.');
        $this->assertSame('SYSTEM', $audit->actor_type,
            'The enrolment is attributed to a user who did not make it.');
        $this->assertStringContainsString('expires on', (string) $audit->reason);

        $after = json_decode($audit->after, true);

        $this->assertSame((int) $courseId, $after['course_id']);
        $this->assertTrue($after['blocks_work']);
    }

    public function test_the_reminders_command_books_refreshers(): void
    {
        // The door. A service method nothing calls is the state this requirement describes.
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $this->record($competencyId, Carbon::today()->addDays(30));

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertTrue(DB::table('lms_enrollments')
            ->where('company_id', $this->companyId)->where('course_id', $courseId)->exists(),
            'Nothing on the schedule books refreshers, so the enrolment only happens if somebody '
            .'calls the service by hand.');
    }

    public function test_reopening_an_enrolment_does_not_erase_last_cycles_certificate(): void
    {
        /*
         * The premise of the re-open. `lms_enrollments` is unique on (user_id, course_id), so a
         * new cycle has to reuse the row — which is only safe because completion evidence lives
         * in `lms_certificates` with its own issue and expiry dates. If it did not, booking this
         * year's refresher would delete the proof they passed last year's.
         */
        $courseId = $this->course('Forklift refresher');
        $competencyId = $this->competency('FORKLIFT', $courseId);
        $this->record($competencyId, Carbon::today()->addDays(30));

        $enrolmentId = (int) DB::table('lms_enrollments')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'user_id' => $this->user->id, 'course_id' => $courseId,
            'status' => 'completed', 'progress_pct' => 100, 'score' => 88,
            'enrolled_at' => now()->subYear(), 'completed_at' => now()->subYear(),
            'time_spent_mins' => 90, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $certificateId = (int) DB::table('lms_certificates')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'enrollment_id' => $enrolmentId, 'hr_person_id' => $this->personId,
            'certificate_number' => 'CERT-'.Str::upper(Str::random(6)),
            'issued_at' => now()->subYear(),
            'expires_at' => Carbon::today()->addDays(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->service()->enrolRefreshers($this->companyId)['enrolled']);

        $enrolment = DB::table('lms_enrollments')->where('id', $enrolmentId)->first();

        // Re-opened for the new cycle.
        $this->assertSame('enrolled', $enrolment->status);
        $this->assertNull($enrolment->completed_at);
        $this->assertSame(0, (int) $enrolment->progress_pct);

        // And last year's certificate is untouched.
        $certificate = DB::table('lms_certificates')->where('id', $certificateId)->first();

        $this->assertNotNull($certificate,
            'Re-opening the enrolment destroyed the certificate that proves they passed, so the '
            .'only record of last year\'s competence is gone.');
        $this->assertNotNull($certificate->issued_at);

        // The audit says it was a re-open rather than a first booking, because the two are
        // different facts about the same person.
        $after = json_decode((string) DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'COMPETENCY_REFRESHER_ENROLLED')
            ->orderByDesc('id')->value('after'), true);

        $this->assertTrue($after['reopened']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function course(string $title): int
    {
        return (int) DB::table('lms_courses')->insertGetId([
            'uuid' => (string) Str::uuid(), 'title' => $title,
            'company_id' => $this->companyId, 'is_published' => true,
            'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function competency(string $code, ?int $courseId, bool $blocksWork = false): int
    {
        return (int) DB::table('sheq_competencies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => $code.Str::upper(Str::random(4)), 'title' => $code.' licence',
            'competency_type' => 'LICENCE', 'lms_course_id' => $courseId,
            'blocks_work_when_expired' => $blocksWork,
            'is_mandatory' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function record(int $competencyId, Carbon $expires, ?int $personId = null): int
    {
        return (int) DB::table('sheq_competency_records')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'competency_id' => $competencyId, 'hr_person_id' => $personId ?? $this->personId,
            'status' => 'COMPLIANT', 'achieved_at' => Carbon::today()->subYear(),
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function person(?int $userId): int
    {
        return (int) DB::table('hr_people')->insertGetId([
            'company_id' => $this->companyId, 'first_name' => 'Sipho', 'surname' => 'Khumalo',
            'user_id' => $userId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWith(): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ref-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        return User::withoutGlobalScopes()->findOrFail($userId);
    }
}
