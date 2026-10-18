<?php

namespace App\Modules\HR\LMS\Services;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The agricultural training library every farm starts with.
 *
 * The data is SAQA's own register — qualifications, skills programmes and the compliance training
 * South African farms are expected to run — kept as one JSON file (database/data/lms/) so the
 * catalogue is reviewed and updated in one place, with each record pointing at the SAQA page it was
 * checked against.
 *
 * Two halves, because of how the LMS is shaped:
 *
 *   - Unit standards and their specific outcomes are the national register: one shared copy for the
 *     whole platform (lms_unit_standards has no company), installed by registerUnitStandards().
 *   - Courses, their modules, categories and certifications belong to a farm. installFor() gives a
 *     company its own copy, so a farm can edit, unpublish or extend the courses without touching
 *     anybody else's.
 *
 * Installing is idempotent. A course is found again by its library_key, so a re-run, a backfill or a
 * library update adds what is missing and never duplicates — and never overwrites a course the farm
 * has since edited, because only missing courses are created.
 */
class AgriTrainingLibrary
{
    /** SAQA: one credit is ten notional hours of learning. */
    private const MINUTES_PER_CREDIT = 600;

    /** A qualification's quiz: this many questions, spread across its modules. */
    private const QUALIFICATION_QUIZ = 20;

    private ?array $library = null;

    /** @var array<string, array|null> unit key => its lessons and quiz */
    private array $content = [];

    public function __construct(private TenantContext $tenant) {}

    public static function path(): string
    {
        return database_path('data/lms/agri_training_library.json');
    }

    /** @return array{version:int, categories:array, unit_standards:array, courses:array} */
    public function library(): array
    {
        if ($this->library !== null) {
            return $this->library;
        }

        $raw = @file_get_contents(self::path());
        $data = $raw === false ? null : json_decode($raw, true);

        if (! is_array($data) || ! isset($data['courses'])) {
            throw new RuntimeException('The agricultural training library could not be read from '.self::path());
        }

        return $this->library = $data + ['categories' => [], 'unit_standards' => []];
    }

    /**
     * The shared SAQA register: every unit standard the library's courses are built from, with its
     * specific outcomes. Platform-wide, so it runs with no tenant applied.
     *
     * @return array{unit_standards:int, outcomes:int}
     */
    public function registerUnitStandards(bool $refresh = false): array
    {
        $result = ['unit_standards' => 0, 'outcomes' => 0];

        // One read of what is already registered, so a sign-up that finds the register complete costs
        // two queries rather than one per unit standard.
        $ids = DB::table('lms_unit_standards')->pluck('id', 'saqa_id')->all();
        $haveOutcomes = DB::table('lms_unit_standard_outcomes')
            ->get(['unit_standard_id', 'outcome_code'])
            ->mapWithKeys(fn ($o) => [$o->unit_standard_id.'|'.$o->outcome_code => true])
            ->all();
        $newOutcomes = [];

        foreach ($this->library()['unit_standards'] as $us) {
            $saqaId = (string) $us['saqa_id'];
            $id = $ids[$saqaId] ?? null;

            $row = [
                'title' => Str::limit((string) $us['title'], 250, ''),
                'credits' => (float) ($us['credits'] ?? 0),
                'nqf_level' => (int) ($us['nqf_level'] ?? 0),
                'field_of_study' => $us['field_of_study'] ?? 'Agriculture and Nature Conservation',
                'sub_field' => $us['sub_field'] ?? null,
                // Only the SETA SAQA names. Most legacy agricultural unit standards list none now
                // that the QCTO is their quality assurer, so null is the honest answer for those.
                'seta_id' => $this->setaId($us['seta'] ?? null),
                'type' => $us['type'] ?? 'unit_standard',
                'status' => $us['status'] ?? 'registered',
                'registered_at' => $us['registered_at'] ?? null,
                'expires_at' => $us['expires_at'] ?? null,
                'purpose' => $us['purpose'] ?? null,
                'updated_at' => now(),
            ];

            if ($id === null) {
                $id = $ids[$saqaId] = DB::table('lms_unit_standards')->insertGetId($row + [
                    'saqa_id' => $saqaId,
                    'created_at' => now(),
                ]);
                $result['unit_standards']++;
            } elseif ($refresh) {
                DB::table('lms_unit_standards')->where('id', $id)->update($row);
            }

            foreach (array_values($us['outcomes'] ?? []) as $i => $outcome) {
                $title = is_array($outcome) ? ($outcome['title'] ?? '') : (string) $outcome;
                $code = is_array($outcome) && ! empty($outcome['code']) ? (string) $outcome['code'] : 'SO'.($i + 1);
                if ($title === '' || isset($haveOutcomes[$id.'|'.$code])) {
                    continue;
                }
                $haveOutcomes[$id.'|'.$code] = true;
                $newOutcomes[] = [
                    'unit_standard_id' => $id,
                    'outcome_code' => $code,
                    'outcome_title' => Str::limit($title, 250, ''),
                    'performance_criteria' => is_array($outcome) ? ($outcome['performance_criteria'] ?? null) : null,
                    'sort_order' => $i + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($newOutcomes, 500) as $chunk) {
            DB::table('lms_unit_standard_outcomes')->insert($chunk);
        }
        $result['outcomes'] = count($newOutcomes);

        return $result;
    }

    /**
     * Give one company its own copy of the library: categories, courses with their unit-standard
     * modules, and a certification for every course that has to be renewed.
     *
     * @return array{courses:int, modules:int, certifications:int, categories:int}
     */
    public function installFor(int $companyId, ?int $createdBy = null): array
    {
        $this->registerUnitStandards();

        return $this->tenant->run($companyId, fn () => DB::transaction(
            fn () => $this->installInto($companyId, $createdBy ?? $this->ownerOf($companyId))
        ));
    }

    /**
     * Every company that should have the library. Used by the backfill command and by the
     * DatabaseSeeder; a new company is handled by the provisioner as it is created.
     *
     * @return array<int, array> company id => result
     */
    public function installForAll(): array
    {
        $results = [];
        foreach ($this->tenant->activeCompanyIds() as $id) {
            try {
                $results[(int) $id] = $this->installFor((int) $id);
            } catch (\Throwable $e) {
                Log::warning('AgriTrainingLibrary: install failed', ['company' => $id, 'error' => $e->getMessage()]);
                $results[(int) $id] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function installInto(int $companyId, ?int $createdBy): array
    {
        $result = ['courses' => 0, 'modules' => 0, 'lessons' => 0, 'questions' => 0, 'certifications' => 0, 'categories' => 0, 'moved' => 0, 'retired' => 0];
        $library = $this->library();

        $categoryIds = [];
        foreach ($library['categories'] as $i => $category) {
            [$categoryIds[$category['name']], $created] = $this->category($companyId, $category, $i + 1);
            $result['categories'] += $created ? 1 : 0;
        }

        $unitStandards = collect($library['unit_standards'])->keyBy(fn ($us) => (string) $us['saqa_id']);

        // Everything this farm already has from the library, in one read (deleted courses included,
        // so a course the farm deleted is recognised and not put back).
        $installed = DB::table('lms_courses')
            ->where('company_id', $companyId)
            ->whereNotNull('library_key')
            ->get(['id', 'library_key', 'category_id', 'deleted_at'])
            ->keyBy('library_key');

        foreach ($library['courses'] as $course) {
            $key = (string) $course['key'];

            $categoryName = $course['category'] ?? null;
            if ($categoryName !== null && ! isset($categoryIds[$categoryName])) {
                [$categoryIds[$categoryName], $created] = $this->category($companyId, ['name' => $categoryName], 99);
                $result['categories'] += $created ? 1 : 0;
            }
            $categoryId = $categoryName !== null ? $categoryIds[$categoryName] : null;

            $existing = $installed->get($key);

            if ($existing !== null) {
                // A course the farm deleted stays deleted.
                if ($existing->deleted_at === null) {
                    $result['moved'] += $this->recategorise($existing, $categoryId, $library['retired_categories'] ?? []);
                    $added = $this->backfillContent((int) $existing->id, $companyId, $course);
                    $result['lessons'] += $added['lessons'];
                    $result['questions'] += $added['questions'];
                }

                continue;
            }

            $credits = (float) ($course['credits'] ?? 0);
            $mandatory = (bool) ($course['is_mandatory'] ?? false);
            $validDays = isset($course['refresh_months']) && $course['refresh_months']
                ? (int) round($course['refresh_months'] * 365 / 12)
                : null;
            $hasContent = $this->courseHasContent($course);

            $courseId = DB::table('lms_courses')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'library_key' => $key,
                'company_id' => $companyId,
                'title' => Str::limit((string) $course['title'], 250, ''),
                'description' => $this->description($course, $hasContent),
                'category_id' => $categoryId,
                // In-app lessons when the library has them; otherwise provider-delivered, recorded here.
                'type' => $hasContent ? 'document' : 'external',
                'duration_mins' => $credits > 0 ? (int) round($credits * self::MINUTES_PER_CREDIT) : (int) ($course['duration_mins'] ?? 480),
                'pass_score' => (int) ($course['pass_score'] ?? 70),
                'expires_after_days' => $validDays,
                'is_mandatory' => $mandatory,
                'is_published' => true,
                'created_by' => $createdBy,
                'nqf_level' => isset($course['nqf_level']) ? (int) $course['nqf_level'] : null,
                'credits' => $credits ?: null,
                'saqa_id' => isset($course['saqa_id']) ? (string) $course['saqa_id'] : null,
                'unit_standard_code' => isset($course['unit_standard_code']) ? (string) $course['unit_standard_code'] : null,
                'ofo_code' => $course['ofo_code'] ?? null,
                'seta_id' => $this->setaId($course['seta'] ?? null),
                'qualification_component' => null,
                'is_qcto_registered' => (bool) ($course['is_qcto_registered'] ?? false),
                'is_accredited' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $result['courses']++;

            $moduleRows = [];
            foreach (array_values($course['modules'] ?? []) as $i => $module) {
                $us = isset($module['unit_standard_id']) ? $unitStandards->get((string) $module['unit_standard_id']) : null;
                $moduleRows[] = [
                    'course_id' => $courseId,
                    'company_id' => $companyId,
                    'title' => Str::limit($this->moduleTitle($module), 250, ''),
                    'description' => $this->moduleDescription($module, $us),
                    'sort_order' => $i + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            // One insert per course; the ids come back by sort order (index i was given i + 1).
            $moduleIds = [];
            if ($moduleRows !== []) {
                DB::table('lms_course_modules')->insert($moduleRows);
                foreach (DB::table('lms_course_modules')->where('course_id', $courseId)->orderBy('sort_order')->pluck('id', 'sort_order') as $sort => $id) {
                    $moduleIds[(int) $sort - 1] = (int) $id;
                }
                $result['modules'] += count($moduleRows);
            }

            $added = $this->addContent($courseId, $companyId, $course, $moduleIds);
            $result['lessons'] += $added['lessons'];
            $result['questions'] += $added['questions'];

            if ($validDays !== null && $createdBy !== null && Schema::hasTable('lms_certifications')) {
                DB::table('lms_certifications')->insert([
                    'company_id' => $companyId,
                    'name' => Str::limit((string) $course['title'], 250, ''),
                    'description' => 'Renew every '.$course['refresh_months'].' months'
                        .(! empty($course['legal_basis']) ? ' — '.$course['legal_basis'] : '').'.',
                    'course_id' => $courseId,
                    'valid_days' => $validDays,
                    'refresher_before_days' => min(90, max(14, (int) round($validDays / 12))),
                    'refresher_course_id' => $courseId,
                    'reminder_days' => json_encode([60, 30, 7]),
                    'actions_blocked_when_expired' => json_encode([]),
                    'requires_understanding_score' => false,
                    'min_understanding_score' => 0,
                    'auto_issue' => true,
                    'is_active' => true,
                    'created_by' => $createdBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $result['certifications']++;
            }
        }

        $result['retired'] = $this->retireEmptyCategories($companyId, $library['retired_categories'] ?? []);

        return $result;
    }

    /**
     * Categories older versions of the library used, removed once nothing is filed under them. One
     * the farm still has courses in (its own, or library courses it re-filed there) is left alone.
     */
    private function retireEmptyCategories(int $companyId, array $retired): int
    {
        if ($retired === []) {
            return 0;
        }

        return DB::table('lms_categories')
            ->where('company_id', $companyId)
            ->whereIn('name', $retired)
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('lms_courses')->whereColumn('lms_courses.category_id', 'lms_categories.id'))
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    /** The content file key for a library module: its unit standard, or its QCTO module code. */
    public static function unitKey(array $module): ?string
    {
        if (! empty($module['unit_standard_id'])) {
            return 'US-'.$module['unit_standard_id'];
        }

        return ! empty($module['code']) ? 'MOD-'.$module['code'] : null;
    }

    /** Lessons and quiz written for one unit, or null when none have been written yet. */
    public function unitContent(?string $unitKey): ?array
    {
        if ($unitKey === null) {
            return null;
        }
        if (array_key_exists($unitKey, $this->content)) {
            return $this->content[$unitKey];
        }

        $file = database_path('data/lms/content/units/'.basename($unitKey).'.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return $this->content[$unitKey] = (is_array($data) && ! empty($data['lessons'])) ? $data : null;
    }

    private function courseHasContent(array $course): bool
    {
        foreach ($course['modules'] ?? [] as $module) {
            if ($this->unitContent(self::unitKey($module)) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lessons under each module, then the course quiz: every question of a single-unit course, or an
     * even spread of up to QUALIFICATION_QUIZ questions across a qualification's modules.
     *
     * @param  array<int, int>  $moduleIds  library module index => lms_course_modules.id
     * @return array{lessons:int, questions:int}
     */
    private function addContent(int $courseId, int $companyId, array $course, array $moduleIds, bool $withQuiz = true): array
    {
        $added = ['lessons' => 0, 'questions' => 0];
        $pools = [];

        $lessonRows = [];
        foreach (array_values($course['modules'] ?? []) as $i => $module) {
            $content = $this->unitContent(self::unitKey($module));
            if ($content === null || ! isset($moduleIds[$i])) {
                continue;
            }

            foreach (array_values($content['lessons']) as $n => $lesson) {
                $lessonRows[] = [
                    'module_id' => $moduleIds[$i],
                    'course_id' => $courseId,
                    'company_id' => $companyId,
                    'title' => Str::limit((string) $lesson['title'], 250, ''),
                    'type' => 'text',
                    'content_body' => (string) $lesson['html'],
                    'external_url' => null,
                    'duration_mins' => max(5, (int) ($lesson['minutes'] ?? 15)),
                    'sort_order' => $n + 1,
                    // The first lesson of the course can be read before enrolling.
                    'is_preview' => $lessonRows === [],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $pools[] = array_values($content['quiz'] ?? []);
        }
        foreach (array_chunk($lessonRows, 200) as $chunk) {
            DB::table('lms_lessons')->insert($chunk);
        }
        $added['lessons'] = count($lessonRows);

        if (! $withQuiz || $pools === []) {
            return $added;
        }

        // Round-robin across modules so a qualification's quiz samples all of it, not module one.
        $limit = count($pools) === 1 ? count($pools[0]) : self::QUALIFICATION_QUIZ;
        $questions = [];
        for ($round = 0; count($questions) < $limit; $round++) {
            $took = false;
            foreach ($pools as $pool) {
                if (isset($pool[$round]) && count($questions) < $limit) {
                    $questions[] = $pool[$round];
                    $took = true;
                }
            }
            if (! $took) {
                break;
            }
        }
        if ($questions === []) {
            return $added;
        }

        DB::table('lms_quiz_questions')->insert(array_map(fn ($q, $n) => [
            'course_id' => $courseId,
            'company_id' => $companyId,
            'question' => (string) $q['question'],
            'type' => in_array($q['type'] ?? '', ['mcq', 'true_false', 'multi_select'], true) ? $q['type'] : 'mcq',
            'sort_order' => $n + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $questions, array_keys($questions)));

        // The course had no quiz before this (backfill only adds one where none exists), so its
        // questions are exactly these, in this order.
        $questionIds = DB::table('lms_quiz_questions')->where('course_id', $courseId)->orderBy('sort_order')->pluck('id')->all();
        $optionRows = [];
        foreach ($questions as $n => $q) {
            foreach ($this->shuffledOptions($q) as $o => $option) {
                $optionRows[] = [
                    'question_id' => $questionIds[$n],
                    'company_id' => $companyId,
                    'option_text' => Str::limit((string) $option['text'], 250, ''),
                    'is_correct' => (bool) ($option['correct'] ?? false),
                    'sort_order' => $o + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($optionRows, 500) as $chunk) {
            DB::table('lms_quiz_options')->insert($chunk);
        }
        $added['questions'] = count($questions);

        return $added;
    }

    /**
     * A question's options in a fixed shuffled order. Authors tend to put the right answer in the same
     * place; ordering by a hash of question and option spreads it out, and the same question always
     * comes out the same way, so every farm sees identical quizzes. True/False keeps its order.
     */
    private function shuffledOptions(array $q): array
    {
        $options = array_values($q['options'] ?? []);
        if (($q['type'] ?? '') === 'true_false') {
            return $options;
        }
        usort($options, fn ($a, $b) => crc32($q['question'].'|'.$a['text']) <=> crc32($q['question'].'|'.$b['text']));

        return $options;
    }

    /**
     * Content for a course a farm already has. Only where the farm has added nothing of its own: a
     * module that already has lessons, or a course that already has a quiz, is left alone.
     *
     * @return array{lessons:int, questions:int}
     */
    private function backfillContent(int $courseId, int $companyId, array $course): array
    {
        if (! $this->courseHasContent($course)) {
            return ['lessons' => 0, 'questions' => 0];
        }

        $modules = DB::table('lms_course_modules')->where('course_id', $courseId)->orderBy('sort_order')->get(['id', 'title']);

        // A course installed before the library gave it any modules (a qualification SAQA lists no
        // components for) has nothing for lessons to hang on: give it the library's modules first.
        if ($modules->isEmpty() && ! empty($course['modules'])) {
            $unitStandards = collect($this->library()['unit_standards'])->keyBy(fn ($us) => (string) $us['saqa_id']);
            DB::table('lms_course_modules')->insert(array_map(fn ($module, $i) => [
                'course_id' => $courseId,
                'company_id' => $companyId,
                'title' => Str::limit($this->moduleTitle($module), 250, ''),
                'description' => $this->moduleDescription($module, isset($module['unit_standard_id']) ? $unitStandards->get((string) $module['unit_standard_id']) : null),
                'sort_order' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ], array_values($course['modules']), array_keys(array_values($course['modules']))));
            $modules = DB::table('lms_course_modules')->where('course_id', $courseId)->orderBy('sort_order')->get(['id', 'title']);
        }
        $byTitle = $modules->keyBy(fn ($m) => self::titleKey($m->title));
        $withLessons = DB::table('lms_lessons')->where('course_id', $courseId)->distinct()->pluck('module_id')->flip();

        $moduleIds = [];
        foreach (array_values($course['modules'] ?? []) as $i => $module) {
            $row = $byTitle->get(self::titleKey(Str::limit($this->moduleTitle($module), 250, '')));
            if ($row !== null && ! $withLessons->has($row->id)) {
                $moduleIds[$i] = (int) $row->id;
            }
        }

        $hasQuiz = DB::table('lms_quiz_questions')->where('course_id', $courseId)->exists();
        $added = $this->addContent($courseId, $companyId, $course, $moduleIds, ! $hasQuiz);

        if ($added['lessons'] > 0) {
            DB::table('lms_courses')->where('id', $courseId)->where('type', 'external')
                ->update(['type' => 'document', 'description' => $this->description($course, true), 'updated_at' => now()]);
        }

        return $added;
    }

    /**
     * A module title for matching: case-insensitive, and blind to the cut-off tails (", at", ";") that
     * SAQA's QCTO module titles carried before the library cleaned them.
     */
    private static function titleKey(string $title): string
    {
        return Str::lower(preg_replace('/(\s*[;,]\s*(at)?)+\s*$/i', '', trim($title)));
    }

    /** Move a library course out of a category the library has retired, unless the farm re-filed it. */
    private function recategorise(object $existing, ?int $categoryId, array $retired): int
    {
        if ($categoryId === null || $existing->category_id === null || (int) $existing->category_id === $categoryId || $retired === []) {
            return 0;
        }

        $current = DB::table('lms_categories')->where('id', $existing->category_id)->value('name');
        if ($current === null || ! in_array($current, $retired, true)) {
            return 0;
        }

        DB::table('lms_courses')->where('id', $existing->id)->update(['category_id' => $categoryId, 'updated_at' => now()]);

        return 1;
    }

    /** @return array{0:int, 1:bool} category id, and whether it was created */
    private function category(int $companyId, array $category, int $sort): array
    {
        $id = DB::table('lms_categories')
            ->where('company_id', $companyId)
            ->whereRaw('lower(name) = ?', [Str::lower($category['name'])])
            ->whereNull('deleted_at')
            ->value('id');

        if ($id !== null) {
            return [(int) $id, false];
        }

        return [(int) DB::table('lms_categories')->insertGetId([
            'company_id' => $companyId,
            'name' => $category['name'],
            'description' => $category['description'] ?? null,
            'icon' => $category['icon'] ?? 'tabler-plant-2',
            'color' => $category['color'] ?? '#28C76F',
            'sort_order' => $sort,
            'created_at' => now(),
            'updated_at' => now(),
        ]), true];
    }

    private function description(array $course, bool $hasContent = false): string
    {
        $lines = [trim((string) ($course['purpose'] ?? ''))];

        $facts = array_filter([
            isset($course['saqa_id']) ? 'SAQA ID '.$course['saqa_id'] : null,
            isset($course['nqf_level']) ? 'NQF level '.$course['nqf_level'] : null,
            isset($course['credits']) ? $course['credits'].' credits' : null,
            $course['quality_assurer'] ?? null,
            $course['registration_status'] ?? null,
            ! empty($course['last_date_for_enrolment']) ? 'last enrolment '.$course['last_date_for_enrolment'] : null,
            ! empty($course['last_date_for_achievement']) ? 'last achievement '.$course['last_date_for_achievement'] : null,
        ]);
        if ($facts) {
            $lines[] = implode(' · ', $facts).'.';
        }
        if (! empty($course['enrolment_closed'])) {
            $lines[] = 'Closed to new enrolments on SAQA — learners already enrolled may still complete by the last achievement date.';
        }
        if (! empty($course['note'])) {
            $lines[] = $course['note'];
        }
        if (! empty($course['legal_basis'])) {
            $lines[] = 'Legal basis: '.$course['legal_basis'].'.';
        }
        if ($hasContent) {
            $lines[] = 'Lessons and quiz: draft learning material written to the SAQA outcomes. Have an accredited '
                .'training provider review them before using them for credit-bearing assessment.';
        }
        if (! empty($course['source_url'])) {
            $lines[] = 'Source: '.$course['source_url'];
        }

        return trim(implode("\n\n", array_filter($lines)));
    }

    private function moduleTitle(array $module): string
    {
        // Codes the library made up only to key a module's lessons are not shown to anybody.
        $code = $module['unit_standard_id'] ?? (empty($module['internal_code']) ? ($module['code'] ?? null) : null);

        return $code ? "{$code} — {$module['title']}" : (string) $module['title'];
    }

    private function moduleDescription(array $module, ?array $us): ?string
    {
        $facts = array_filter([
            $module['type'] ?? $us['type'] ?? null,
            isset($module['nqf_level']) || isset($us['nqf_level']) ? 'NQF '.($module['nqf_level'] ?? $us['nqf_level']) : null,
            isset($module['credits']) || isset($us['credits']) ? ($module['credits'] ?? $us['credits']).' credits' : null,
        ]);

        $outcomes = collect($us['outcomes'] ?? $module['outcomes'] ?? [])
            ->map(fn ($o) => is_array($o) ? ($o['title'] ?? '') : (string) $o)
            ->filter()
            ->values();

        $text = $facts ? implode(' · ', $facts) : '';
        if ($outcomes->isNotEmpty()) {
            $text .= ($text !== '' ? "\n\n" : '').'Specific outcomes:'."\n".$outcomes->map(fn ($o, $i) => ($i + 1).'. '.$o)->implode("\n");
        }

        return $text !== '' ? $text : null;
    }

    private function setaId(?string $acronym): ?int
    {
        if ($acronym === null || $acronym === '') {
            return null;
        }

        static $cache = [];
        $key = Str::upper(trim($acronym));

        return $cache[$key] ??= DB::table('lms_setas')
            ->whereRaw('upper(acronym) = ?', [$key])
            ->value('id');
    }

    /**
     * The company's owner, or failing that its first user, so courses have an author. A company
     * with no users yet (installed straight after creation) falls back to the first platform
     * account, because lms_courses.created_by is required.
     */
    private function ownerOf(int $companyId): ?int
    {
        $id = DB::table('users')->where('company_id', $companyId)
            ->when(Schema::hasColumn('users', 'is_company_owner'), fn ($q) => $q->orderByDesc('is_company_owner'))
            ->orderBy('id')
            ->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }
}
