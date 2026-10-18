<?php

namespace Tests\Feature\LMS;

use App\Modules\HR\LMS\Services\AgriTrainingLibrary;
use App\Modules\Tenancy\Services\CompanyProvisioner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every farm starts with the SAQA agricultural training library: qualifications, skills
 * programmes and the compliance training South African farms are expected to run.
 */
class AgriTrainingLibraryTest extends TestCase
{
    use DatabaseTransactions;

    private function library(): array
    {
        return app(AgriTrainingLibrary::class)->library();
    }

    private function signUp(): int
    {
        $result = app(CompanyProvisioner::class)->provision([
            'company_name' => 'Boplaas Farm '.uniqid(),
            'admin_name' => 'Thandi Mokoena',
            'email' => 'thandi'.uniqid().'@boplaas.test',
            'password' => 'secret-password',
        ]);

        return (int) $result['company']->id;
    }

    public function test_the_library_file_covers_qualifications_skills_programmes_and_compliance(): void
    {
        $kinds = collect($this->library()['courses'])->countBy('kind');

        $this->assertGreaterThan(30, $kinds['qualification'] ?? 0);
        $this->assertGreaterThan(30, $kinds['skills_programme'] ?? 0);
        $this->assertGreaterThan(5, $kinds['compliance'] ?? 0);

        // Every record says where it was checked, and every key is unique so installing is idempotent.
        foreach ($this->library()['courses'] as $course) {
            $this->assertNotEmpty($course['saqa_id'], $course['title'].' has no SAQA ID.');
            $this->assertStringContainsString('saqa.org.za', (string) $course['source_url'], $course['title'].' has no SAQA source.');
        }
        $keys = array_column($this->library()['courses'], 'key');
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_a_farm_that_signs_up_gets_its_own_copy_of_the_whole_library(): void
    {
        $companyId = $this->signUp();
        $expected = count($this->library()['courses']);

        $courses = DB::table('lms_courses')->where('company_id', $companyId)->whereNotNull('library_key');
        $this->assertSame($expected, $courses->count(), 'The new farm did not receive every library course.');
        $this->assertSame($expected, (clone $courses)->where('is_published', true)->count());

        // Qualifications arrive with their unit standards as modules, not as empty shells.
        $qualification = DB::table('lms_courses')->where('company_id', $companyId)
            ->where('library_key', 'like', 'QUAL-%')->first();
        $this->assertNotNull($qualification);
        $this->assertNotNull($qualification->nqf_level);
        $this->assertGreaterThan(0, DB::table('lms_course_modules')->where('course_id', $qualification->id)->count());

        // The categories belong to the farm too.
        $this->assertGreaterThan(5, DB::table('lms_categories')->where('company_id', $companyId)->count());
    }

    public function test_compliance_courses_are_mandatory_and_first_aid_must_be_renewed(): void
    {
        $companyId = $this->signUp();

        $compliance = collect($this->library()['courses'])->where('kind', 'compliance')->pluck('key');
        $this->assertSame(
            $compliance->count(),
            DB::table('lms_courses')->where('company_id', $companyId)->whereIn('library_key', $compliance)->where('is_mandatory', true)->count(),
        );

        // First Aid Level 1 (SAQA 119567): renewed every 36 months, with a certification to track it.
        $firstAid = DB::table('lms_courses')->where('company_id', $companyId)->where('library_key', 'US-119567')->first();
        $this->assertNotNull($firstAid, 'First Aid Level 1 is missing from the library.');
        $this->assertSame(1095, (int) $firstAid->expires_after_days);
        $this->assertTrue(DB::table('lms_certifications')->where('course_id', $firstAid->id)->where('valid_days', 1095)->exists());
    }

    public function test_installing_again_adds_nothing_and_keeps_the_farms_own_changes(): void
    {
        $companyId = $this->signUp();

        DB::table('lms_courses')->where('company_id', $companyId)->where('library_key', 'US-119567')
            ->update(['title' => 'First Aid — Boplaas induction']);

        $again = app(AgriTrainingLibrary::class)->installFor($companyId);

        $this->assertSame(0, $again['courses']);
        $this->assertSame(0, $again['modules']);
        $this->assertSame('First Aid — Boplaas induction', DB::table('lms_courses')
            ->where('company_id', $companyId)->where('library_key', 'US-119567')->value('title'));
    }

    public function test_farms_do_not_share_courses(): void
    {
        $first = $this->signUp();
        $second = $this->signUp();

        $firstIds = DB::table('lms_courses')->where('company_id', $first)->pluck('id');
        $this->assertSame(0, DB::table('lms_course_modules')->where('company_id', $second)->whereIn('course_id', $firstIds)->count());
        $this->assertSame(
            DB::table('lms_courses')->where('company_id', $first)->whereNotNull('library_key')->count(),
            DB::table('lms_courses')->where('company_id', $second)->whereNotNull('library_key')->count(),
        );
    }

    /** The first library course whose units have written lessons, of the given kind. */
    private function courseWithContent(string $kind): ?array
    {
        $library = app(AgriTrainingLibrary::class);
        foreach ($library->library()['courses'] as $course) {
            if ($course['kind'] !== $kind) {
                continue;
            }
            $withContent = collect($course['modules'])
                ->filter(fn ($m) => $library->unitContent(AgriTrainingLibrary::unitKey($m)) !== null)
                ->count();
            if ($withContent >= ($kind === 'qualification' ? 3 : 1)) {
                return $course;
            }
        }

        return null;
    }

    public function test_a_course_arrives_with_its_lessons_and_a_quiz_that_can_be_marked(): void
    {
        $course = $this->courseWithContent('skills_programme');
        if ($course === null) {
            $this->markTestSkipped('No lesson content has been written yet.');
        }

        $companyId = $this->signUp();
        $row = DB::table('lms_courses')->where('company_id', $companyId)->where('library_key', $course['key'])->first();

        $lessons = DB::table('lms_lessons')->where('course_id', $row->id)->get();
        $this->assertGreaterThanOrEqual(2, $lessons->count());
        $this->assertTrue($lessons->every(fn ($l) => $l->type === 'text' && str_contains((string) $l->content_body, '<p>')));
        $this->assertSame('document', $row->type);
        $this->assertStringContainsString('accredited', (string) $row->description);

        $questions = DB::table('lms_quiz_questions')->where('course_id', $row->id)->get();
        $this->assertSame(8, $questions->count());
        foreach ($questions as $q) {
            $correct = DB::table('lms_quiz_options')->where('question_id', $q->id)->where('is_correct', true)->count();
            $q->type === 'multi_select'
                ? $this->assertGreaterThanOrEqual(2, $correct, $q->question)
                : $this->assertSame(1, $correct, $q->question);
        }
    }

    public function test_a_qualification_quiz_samples_its_modules_and_stops_at_twenty(): void
    {
        $course = $this->courseWithContent('qualification');
        if ($course === null) {
            $this->markTestSkipped('No qualification has lesson content yet.');
        }

        $companyId = $this->signUp();
        $courseId = DB::table('lms_courses')->where('company_id', $companyId)->where('library_key', $course['key'])->value('id');

        $count = DB::table('lms_quiz_questions')->where('course_id', $courseId)->count();
        $this->assertGreaterThan(8, $count);
        $this->assertLessThanOrEqual(20, $count);
        $this->assertGreaterThan(1, DB::table('lms_lessons')->where('course_id', $courseId)->distinct()->count('module_id'));
    }

    public function test_an_existing_farm_gets_lessons_added_but_never_over_its_own(): void
    {
        $course = $this->courseWithContent('qualification');
        if ($course === null) {
            $this->markTestSkipped('No qualification has lesson content yet.');
        }

        $companyId = $this->signUp();
        $courseId = DB::table('lms_courses')->where('company_id', $companyId)->where('library_key', $course['key'])->value('id');
        $modules = DB::table('lms_lessons')->where('course_id', $courseId)->distinct()->pluck('module_id');

        // As a farm that installed the library before it had content: no lessons, no quiz…
        DB::table('lms_quiz_options')->whereIn('question_id', DB::table('lms_quiz_questions')->where('course_id', $courseId)->pluck('id'))->delete();
        DB::table('lms_quiz_questions')->where('course_id', $courseId)->delete();
        DB::table('lms_lessons')->where('course_id', $courseId)->delete();
        // …except one module where the farm wrote its own lesson.
        DB::table('lms_lessons')->insert([
            'module_id' => $modules->first(), 'course_id' => $courseId, 'company_id' => $companyId,
            'title' => 'Our own induction', 'type' => 'text', 'content_body' => '<p>Ours</p>',
            'duration_mins' => 10, 'sort_order' => 1, 'is_preview' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(AgriTrainingLibrary::class)->installFor($companyId);

        $this->assertGreaterThan(0, $result['lessons']);
        $this->assertGreaterThan(0, $result['questions']);
        $this->assertSame(['Our own induction'], DB::table('lms_lessons')->where('module_id', $modules->first())->pluck('title')->all());

        // And running again adds nothing more.
        $again = app(AgriTrainingLibrary::class)->installFor($companyId);
        $this->assertSame(0, $again['lessons']);
        $this->assertSame(0, $again['questions']);
    }

    public function test_courses_are_filed_by_animal_and_crop(): void
    {
        $companyId = $this->signUp();
        $names = DB::table('lms_categories')->where('company_id', $companyId)->pluck('name');

        foreach (['Livestock · Poultry', 'Livestock · Cattle (Beef)', 'Livestock · Sheep & Goats', 'Livestock · Pigs', 'Crops · Grains & Oilseeds', 'Crops · Fruit & Orchards'] as $expected) {
            $this->assertContains($expected, $names->all());
        }
        $this->assertNotContains('Livestock', $names->all(), 'The retired generic category should not be created.');
    }

    public function test_the_saqa_register_is_shared_with_its_specific_outcomes(): void
    {
        app(AgriTrainingLibrary::class)->registerUnitStandards();

        $us = DB::table('lms_unit_standards')->where('saqa_id', '116125')->first();
        $this->assertNotNull($us, 'Unit standard 116125 (apply crop protection products) is not in the register.');
        $this->assertGreaterThan(0, DB::table('lms_unit_standard_outcomes')->where('unit_standard_id', $us->id)->count());
    }
}
