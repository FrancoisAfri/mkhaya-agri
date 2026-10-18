<?php

namespace Database\Seeders;

use App\Core\User\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Comprehensive LMS test-data seeder.
 *
 * Populates every active LMS feature with realistic, inter-related sample data:
 * SETAs/AQPs/SDFs/registered persons, categories, skills (+ employee skills,
 * job-title skills, gaps), courses → modules → lessons → materials/contents,
 * learning paths, question banks + questionnaires (questions/options/assignments),
 * enrolments + lesson completions + certificates, assessment attempts/responses/
 * manual marking/scores, understanding scores + weak areas + gap reports,
 * AI recommendations/sessions/artefacts, compliance rules/assignments/escalations/
 * reports, certificate templates + certifications + employee certifications +
 * expiry logs, effectiveness snapshots/reports, PA recommendations + development
 * plans, scheduled reports, unit standards + outcomes, learnerships + enrolments +
 * logbooks + EISA, PoE portfolios/sections/evidence, WSP/ATR + items, B-BBEE
 * spend and payroll snapshots.
 *
 * Inserts run against the verified live schema via the DB query builder so the
 * data always matches the real columns. Each section is independently guarded —
 * a failure is reported and skipped rather than aborting the run.
 */
class LmsTestDataSeeder extends Seeder
{
    private array $userIds = [];
    private array $hrPersonIds = [];
    private array $positionIds = [];
    private ?int $cycleId = null;

    private array $courseIds = [];      // title => id
    private array $skillIds = [];       // name  => id
    private ?int $setaId = null;
    private ?int $sdfId = null;
    private array $questionnaires = []; // [ ['id','course_id','pass','questions'=>[ ['id','type','points','correct'] ]] ]

    /** All LMS tables, child-first, for the clean wipe. */
    private array $wipeTables = [
        'lms_question_responses', 'lms_manual_marking', 'lms_assessment_scores', 'lms_questionnaire_attempts',
        'lms_questionnaire_assignments', 'question_skill_mapping', 'lms_question_options', 'lms_questions', 'lms_question_banks',
        'lms_lesson_completions', 'lms_course_materials', 'lms_course_contents', 'lms_course_prerequisites',
        'lms_learning_gap_reports', 'lms_weak_learning_areas', 'lms_understanding_scores',
        'lms_ai_artefacts', 'lms_ai_recommendations', 'lms_ai_sessions',
        'lms_compliance_escalations', 'lms_compliance_assignments', 'lms_compliance_reports', 'lms_compliance_rules',
        'certification_expiry_logs', 'employee_certifications', 'lms_certifications', 'lms_certificate_templates',
        'lms_certificates',
        'lms_effectiveness_snapshots', 'lms_effectiveness_reports',
        'lms_pa_training_recommendations', 'lms_development_training_plans',
        'lms_scheduled_reports',
        'lms_poe_evidence', 'lms_poe_sections', 'lms_poe_portfolios',
        'lms_learnership_logbook', 'lms_eisa_schedules', 'lms_learnership_enrolments',
        'lms_atr_items', 'lms_atr_reports', 'lms_wsp_items', 'lms_wsp_plans',
        'lms_bbee_training_spend', 'lms_payroll_snapshots',
        'lms_unit_standard_outcomes', 'lms_unit_standards',
        'lms_learnerships',
        'lms_learning_path_courses', 'lms_learning_paths',
        'lms_enrollments',
        'lms_lessons', 'lms_course_modules', 'lms_courses',
        'course_skills', 'employee_skill_gaps', 'employee_skills', 'job_title_skills', 'skills',
        'lms_registered_persons', 'lms_sdfs', 'lms_aqps', 'lms_setas',
        'lms_categories',
    ];

    public function run(): void
    {
        $this->command->info('Seeding comprehensive LMS test data…');

        $this->userIds = User::pluck('id')->all();
        if (count($this->userIds) < 1) {
            $this->command->warn('No users found — skipping.');
            return;
        }
        $this->hrPersonIds = Schema::hasTable('hr_people') ? DB::table('hr_people')->pluck('id')->all() : [];
        $this->positionIds = Schema::hasTable('positions') ? DB::table('positions')->pluck('id')->all() : [];
        $this->cycleId = $this->firstId(['performance_appraisal_cycles', 'performance_cycles', 'appraisal_review_cycles']);

        $this->wipe();

        $sections = [
            'seedSetas', 'seedCategories', 'seedSkills', 'seedAqps', 'seedSdfs', 'seedRegisteredPersons',
            'seedCourses', 'seedCourseExtras', 'seedLearningPaths', 'seedQuestionnaires',
            'seedEnrollments', 'seedAssessments', 'seedUnderstanding', 'seedAi',
            'seedComplianceRules', 'seedCertifications', 'seedEffectiveness', 'seedPaIntegration',
            'seedScheduledReports', 'seedQctoCore', 'seedLearnerships', 'seedPoe', 'seedWspAtr', 'seedBbee',
        ];

        foreach ($sections as $m) {
            try {
                $this->$m();
            } catch (\Throwable $e) {
                $this->command->warn("  ✗ {$m}: " . $e->getMessage());
            }
        }

        $this->command->info('✓ LMS test data complete.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private function wipe(): void
    {
        foreach ($this->wipeTables as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }
            try {
                DB::table($t)->delete();
            } catch (\Throwable $e) {
                $this->command->warn("    (wipe skipped {$t}: " . $e->getMessage() . ')');
            }
        }
        $this->command->info('  ✓ Wiped existing LMS data');
    }

    private function ins(string $table, array $data): int
    {
        $now = now();
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        if (Schema::hasColumn($table, 'uuid') && ! array_key_exists('uuid', $data)) {
            $data['uuid'] = (string) Str::uuid();
        }
        return DB::table($table)->insertGetId($data);
    }

    private function firstId(array $tables): ?int
    {
        foreach ($tables as $t) {
            if (Schema::hasTable($t)) {
                $id = DB::table($t)->value('id');
                if ($id) {
                    return $id;
                }
            }
        }
        return null;
    }

    private function uid(int $i): int
    {
        return $this->userIds[$i % count($this->userIds)];
    }

    private function pid(int $i): ?int
    {
        return $this->positionIds ? $this->positionIds[$i % count($this->positionIds)] : null;
    }

    private function hrId(int $i): ?int
    {
        return $this->hrPersonIds ? $this->hrPersonIds[$i % count($this->hrPersonIds)] : null;
    }

    private function grade(float $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default      => 'F',
        };
    }

    // ── SETAs ─────────────────────────────────────────────────────────────────

    private function seedSetas(): void
    {
        $setas = [
            ['Services Sector Education and Training Authority', 'SERVICES SETA', '20', 'www.servicesseta.org.za', 'info@servicesseta.org.za', '011 276 9600'],
            ['Manufacturing, Engineering and Related Services SETA', 'merSETA', '17', 'www.merseta.org.za', 'info@merseta.org.za', '010 219 3000'],
            ['Banking Sector Education and Training Authority', 'BANKSETA', '06', 'www.bankseta.org.za', 'info@bankseta.org.za', '011 805 9661'],
        ];
        foreach ($setas as $i => [$name, $acr, $levy, $web, $email, $phone]) {
            $id = $this->ins('lms_setas', [
                'name'          => $name,
                'acronym'       => $acr,
                'description'   => "{$acr} accredited skills development authority.",
                'website'       => $web,
                'levy_code'     => $levy,
                'contact_email' => $email,
                'contact_phone' => $phone,
                'is_active'     => true,
            ]);
            if ($i === 0) {
                $this->setaId = $id;
            }
        }
        $this->command->info('  ✓ SETAs (3)');
    }

    // ── Categories ──────────────────────────────────────────────────────────────

    private function seedCategories(): void
    {
        $cats = [
            ['Health & Safety', 'shield-check', 'error', 1],
            ['Leadership & Management', 'crown', 'warning', 2],
            ['Information Technology', 'cpu', 'primary', 3],
            ['Human Resources', 'users', 'info', 4],
            ['Finance & Accounting', 'currency-dollar', 'success', 5],
            ['QCTO / NQF Programmes', 'certificate', 'secondary', 6],
        ];
        foreach ($cats as [$name, $icon, $color, $sort]) {
            $this->ins('lms_categories', [
                'name'        => $name,
                'description' => $name . ' training programmes.',
                'icon'        => $icon,
                'color'       => $color,
                'sort_order'  => $sort,
            ]);
        }
        $this->command->info('  ✓ Categories (6)');
    }

    // ── Skills (+ job-title skills, employee skills) ────────────────────────────

    private function seedSkills(): void
    {
        $labels = json_encode(['Novice', 'Beginner', 'Competent', 'Proficient', 'Expert']);
        $skills = [
            ['Occupational Health & Safety', 'Compliance'],
            ['Leadership', 'Management'],
            ['Conflict Management', 'Management'],
            ['Cybersecurity Awareness', 'IT'],
            ['Data Protection (POPIA)', 'Compliance'],
            ['Financial Literacy', 'Finance'],
            ['Communication', 'Soft Skills'],
            ['Project Management', 'Management'],
            ['Skills Development Facilitation', 'HR'],
            ['Coaching & Mentoring', 'Management'],
        ];
        foreach ($skills as [$name, $cat]) {
            $this->skillIds[$name] = $this->ins('skills', [
                'name'        => $name,
                'slug'        => Str::slug($name),
                'description' => "Proficiency in {$name}.",
                'category'    => $cat,
                'max_level'   => 5,
                'level_labels' => $labels,
                'is_active'   => true,
                'created_by'  => $this->userIds[0],
            ]);
        }

        $skillIdList = array_values($this->skillIds);

        foreach ($this->positionIds as $i => $posId) {
            foreach (array_slice($skillIdList, $i % 3, 3) as $sId) {
                $this->ins('job_title_skills', [
                    'position_id'    => $posId,
                    'skill_id'       => $sId,
                    'required_level' => rand(3, 5),
                    'is_mandatory'   => (bool) rand(0, 1),
                ]);
            }
        }

        foreach ($this->userIds as $i => $uid) {
            foreach (array_slice($skillIdList, $i % 4, 3) as $sId) {
                $verified = (bool) rand(0, 1);
                $this->ins('employee_skills', [
                    'user_id'         => $uid,
                    'skill_id'        => $sId,
                    'current_level'   => rand(1, 5),
                    'verified'        => $verified,
                    'verified_by'     => $verified ? $this->userIds[0] : null,
                    'verified_at'     => $verified ? now()->subDays(rand(5, 60)) : null,
                    'source'          => ['self_assessment', 'manager', 'assessment', 'course'][rand(0, 3)],
                    'last_assessed_at' => now()->subDays(rand(5, 120)),
                    'notes'           => null,
                ]);
            }
        }
        $this->command->info('  ✓ Skills (10), job-title skills & employee skills');
    }

    // ── AQPs ──────────────────────────────────────────────────────────────────

    private function seedAqps(): void
    {
        $aqps = [
            ['Quality Council for Trades and Occupations', 'QCTO', 'QCT-2024-001', 'www.qcto.org.za', 'info@qcto.org.za', '012 003 1800'],
            ['SA Institute of Chartered Accountants', 'SAICA', 'SAI-2024-002', 'www.saica.co.za', 'info@saica.co.za', '011 621 6600'],
            ['Institute of People Management', 'IPM', 'IPM-2024-003', 'www.ipm.co.za', 'training@ipm.co.za', '011 481 8000'],
        ];
        foreach ($aqps as [$name, $acr, $reg, $web, $email, $phone]) {
            $this->ins('lms_aqps', [
                'name'                => $name,
                'acronym'             => $acr,
                'seta_id'             => $this->setaId,
                'registration_number' => $reg,
                'website'             => $web,
                'contact_email'       => $email,
                'contact_phone'       => $phone,
                'is_active'           => true,
                'notes'               => null,
            ]);
        }
        $this->command->info('  ✓ AQPs (3)');
    }

    // ── SDFs ──────────────────────────────────────────────────────────────────

    private function seedSdfs(): void
    {
        $depts = ['Human Resources', 'Training', 'Compliance'];
        foreach (array_slice($this->userIds, 0, 3) as $i => $uid) {
            $id = $this->ins('lms_sdfs', [
                'user_id'             => $uid,
                'seta_id'             => $this->setaId,
                'registration_number' => 'SDF-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'registered_at'       => now()->subYears(2)->addMonths($i),
                'expires_at'          => now()->addMonths(6 + $i * 3),
                'is_primary'          => $i === 0,
                'department'          => $depts[$i],
                'status'              => 'active',
                'notes'               => null,
                'created_by'          => $this->userIds[0],
            ]);
            if ($i === 0) {
                $this->sdfId = $id;
            }
        }
        $this->command->info('  ✓ SDFs (3)');
    }

    // ── Registered Persons (assessors / moderators) ─────────────────────────────

    private function seedRegisteredPersons(): void
    {
        $persons = [
            ['Thabo', 'Mokoena', 'assessor', [4, 5, 6], 365],
            ['Nomsa', 'Dlamini', 'moderator', [4, 5, 6, 7], 180],
            ['Sipho', 'Nkosi', 'assessor_moderator', [3, 4, 5], 90],
            ['Liezel', 'van Wyk', 'assessor', [5, 6, 7], 270],
            ['Blessing', 'Chirwa', 'moderator', [4, 5], 45],
        ];
        foreach ($persons as $i => [$first, $last, $type, $levels, $days]) {
            $this->ins('lms_registered_persons', [
                'user_id'             => $this->uid($i),
                'first_name'          => $first,
                'last_name'           => $last,
                'id_number'           => '80010' . str_pad((string) ($i + 1), 8, '0'),
                'email'               => strtolower("{$first}.{$last}@training.co.za"),
                'person_type'         => $type,
                'registration_number' => 'REG-' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                'seta_id'             => $this->setaId,
                'registered_at'       => now()->subYears(2),
                'expires_at'          => now()->addDays($days),
                'nqf_levels'          => json_encode($levels),
                'fields_of_study'     => json_encode(['Management', 'Health & Safety']),
                'status'              => $days < 60 ? 'lapsed' : 'active',
                'notes'               => null,
                'created_by'          => $this->userIds[0],
            ]);
        }
        $this->command->info('  ✓ Registered Persons (5)');
    }

    // ── Courses (+ modules, lessons) ────────────────────────────────────────────

    private function seedCourses(): void
    {
        $catIds = DB::table('lms_categories')->pluck('id', 'name');

        $defs = [
            ['Workplace Health & Safety Essentials', 'document', 120, 75, true, 3, 4, 'Health & Safety', false, [
                ['Introduction to OHS', ['The OHS Act', 'Your Rights and Responsibilities', 'Hazard Identification']],
                ['Incident Management', ['Incident Reporting', 'Emergency Procedures', 'First Aid Basics']],
            ]],
            ['Leadership Foundations', 'video', 240, 70, false, 5, 8, 'Leadership & Management', false, [
                ['Leadership Styles', ['What is Leadership?', 'Situational Leadership', 'Transformational vs Transactional']],
                ['Team Management', ['Building High-Performance Teams', 'Delegation Skills', 'Managing Conflict']],
                ['Performance Coaching', ['Coaching vs Mentoring', 'Setting SMART Goals', 'Performance Conversations']],
            ]],
            ['Cybersecurity Awareness', 'document', 90, 80, true, 4, 3, 'Information Technology', false, [
                ['Cyber Threats', ['Phishing Attacks', 'Social Engineering', 'Malware Basics']],
                ['Data Protection', ['POPIA Compliance', 'Password Management', 'Secure File Sharing']],
            ]],
            ['Employment Equity & Diversity', 'document', 90, 70, true, 4, 3, 'Human Resources', false, [
                ['EE Act Overview', ['Purpose of the EE Act', 'Designated Groups', 'EE Plans and Reporting']],
                ['Workplace Diversity', ['Preventing Discrimination', 'Harassment Policy', 'Grievance Procedures']],
            ]],
            ['Financial Literacy for Employees', 'document', 60, 65, false, 3, 2, 'Finance & Accounting', false, [
                ['Personal Finance', ['Understanding Your Payslip', 'Budgeting Basics', 'Saving and Investing']],
                ['Tax & Retirement', ['Income Tax Explained', 'UIF and COIDA', 'Retirement Fund Basics']],
            ]],
            ['Skills Development & SETA Compliance', 'document', 150, 75, false, 6, 10, 'QCTO / NQF Programmes', true, [
                ['Skills Development Framework', ['The Skills Development Act', 'SETA Structure and Roles', 'NSDS Priorities']],
                ['WSP & ATR', ['Preparing the WSP', 'Completing the ATR', 'Mandatory Grant Claims']],
                ['Learnerships', ['What is a Learnership?', 'Registering Learners', 'QCTO Requirements']],
            ]],
        ];

        foreach ($defs as [$title, $type, $dur, $pass, $mand, $nqf, $credits, $cat, $qcto, $modules]) {
            $courseId = $this->ins('lms_courses', [
                'title'              => $title,
                'description'        => "{$title} — a structured programme aligned to South African legislation and best practice.",
                'category_id'        => $catIds[$cat] ?? null,
                'type'               => $type,
                'duration_mins'      => $dur,
                'pass_score'         => $pass,
                'is_mandatory'       => $mand,
                'is_published'       => true,
                'expires_after_days' => $mand ? 365 : null,
                'nqf_level'          => $nqf,
                'credits'            => $credits,
                'seta_id'            => $qcto ? $this->setaId : null,
                'is_accredited'      => $qcto,
                'is_qcto_registered' => $qcto,
                'created_by'         => $this->userIds[0],
            ]);
            $this->courseIds[$title] = $courseId;

            foreach ($modules as $mIdx => [$modTitle, $lessons]) {
                $modId = $this->ins('lms_course_modules', [
                    'course_id'   => $courseId,
                    'title'       => $modTitle,
                    'description' => "Module covering {$modTitle}.",
                    'sort_order'  => $mIdx,
                ]);
                foreach ($lessons as $lIdx => $lesTitle) {
                    $this->ins('lms_lessons', [
                        'module_id'     => $modId,
                        'course_id'     => $courseId,
                        'title'         => $lesTitle,
                        'type'          => $type === 'video' ? 'video' : 'text',
                        'content_body'  => $this->lessonHtml($lesTitle),
                        'external_url'  => $type === 'video' ? 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' : null,
                        'duration_mins' => rand(15, 30),
                        'sort_order'    => $lIdx,
                        'is_preview'    => $mIdx === 0 && $lIdx === 0,
                    ]);
                }
            }
        }
        $this->command->info('  ✓ Courses (' . count($this->courseIds) . ') with modules & lessons');
    }

    // ── Course extras: materials, contents, prerequisites, course-skills, gaps ──

    private function seedCourseExtras(): void
    {
        $skillIdList = array_values($this->skillIds);

        foreach ($this->courseIds as $title => $courseId) {
            $firstLesson = DB::table('lms_lessons')->where('course_id', $courseId)->orderBy('id')->first();

            $this->ins('lms_course_materials', [
                'course_id'  => $courseId,
                'lesson_id'  => $firstLesson?->id,
                'title'      => "{$title} — Study Guide (PDF)",
                'type'       => 'document',
                'disk'       => 'public',
                'path'       => 'lms/materials/' . Str::slug($title) . '-guide.pdf',
                'file_name'  => Str::slug($title) . '-guide.pdf',
                'mime_type'  => 'application/pdf',
                'size_bytes' => rand(150000, 900000),
                'sort_order' => 0,
                'uploaded_by' => $this->userIds[0],
            ]);

            $this->ins('lms_course_contents', [
                'course_id'     => $courseId,
                'type'          => 'text',
                'title'         => "Overview of {$title}",
                'body'          => $this->lessonHtml("Overview of {$title}"),
                'sort_order'    => 0,
                'duration_mins' => 10,
            ]);

            foreach (array_slice($skillIdList, array_rand($skillIdList), 2) as $sId) {
                if (! DB::table('course_skills')->where(['course_id' => $courseId, 'skill_id' => $sId])->exists()) {
                    $this->ins('course_skills', [
                        'course_id'         => $courseId,
                        'skill_id'          => $sId,
                        'skill_level_gained' => rand(1, 3),
                    ]);
                }
            }
        }

        if (isset($this->courseIds['Leadership Foundations'], $this->courseIds['Workplace Health & Safety Essentials'])) {
            $this->ins('lms_course_prerequisites', [
                'course_id'              => $this->courseIds['Leadership Foundations'],
                'prerequisite_course_id' => $this->courseIds['Workplace Health & Safety Essentials'],
            ]);
        }

        foreach ($this->userIds as $i => $uid) {
            $sId = $skillIdList[$i % count($skillIdList)];
            $required = rand(3, 5);
            $current = rand(1, $required);
            $recCourse = array_values($this->courseIds)[$i % count($this->courseIds)];
            $this->ins('employee_skill_gaps', [
                'user_id'             => $uid,
                'skill_id'            => $sId,
                'position_id'         => $this->pid($i),
                'current_level'       => $current,
                'required_level'      => $required,
                'gap'                 => $required - $current,
                'recommended_course_id' => $recCourse,
                'status'              => $current >= $required ? 'closed' : 'open',
                'identified_at'       => now()->subDays(rand(10, 90)),
                'closed_at'           => $current >= $required ? now()->subDays(rand(1, 9)) : null,
            ]);
        }
        $this->command->info('  ✓ Materials, contents, prerequisites, course-skills & skill gaps');
    }

    // ── Learning Paths ──────────────────────────────────────────────────────────

    private function seedLearningPaths(): void
    {
        $paths = [
            ['New Manager Onboarding Path', true, ['Workplace Health & Safety Essentials', 'Leadership Foundations', 'Employment Equity & Diversity']],
            ['Compliance Fundamentals Path', true, ['Workplace Health & Safety Essentials', 'Cybersecurity Awareness']],
        ];
        foreach ($paths as [$title, $mandatory, $courses]) {
            $pathId = $this->ins('lms_learning_paths', [
                'title'        => $title,
                'description'  => "{$title} — a curated sequence of courses.",
                'is_mandatory' => $mandatory,
                'is_published' => true,
                'created_by'   => $this->userIds[0],
            ]);
            foreach ($courses as $sort => $cTitle) {
                if (! isset($this->courseIds[$cTitle])) {
                    continue;
                }
                $this->ins('lms_learning_path_courses', [
                    'path_id'     => $pathId,
                    'course_id'   => $this->courseIds[$cTitle],
                    'sort_order'  => $sort,
                    'is_required' => true,
                ]);
            }
        }
        $this->command->info('  ✓ Learning Paths (2) with courses');
    }

    // ── Questionnaires (banks, questionnaires, questions, options, assignments) ──

    private function seedQuestionnaires(): void
    {
        $banks = [];
        foreach (['Compliance Bank', 'Leadership Bank'] as $bName) {
            $banks[] = $this->ins('lms_question_banks', [
                'title'       => $bName,
                'description' => "Reusable questions — {$bName}.",
                'category'    => 'General',
                'created_by'  => $this->userIds[0],
            ]);
        }

        $skillIdList = array_values($this->skillIds);

        foreach ($this->courseIds as $title => $courseId) {
            $pass = (int) DB::table('lms_courses')->where('id', $courseId)->value('pass_score');
            $qData = $this->questionTemplates($title);

            $qnId = $this->ins('lms_questionnaires', [
                'title'             => "{$title} — Final Assessment",
                'description'       => "Final assessment for {$title}.",
                'instructions'      => 'Answer all questions. You need ' . $pass . '% to pass.',
                'type'              => 'final_test',
                'time_limit_mins'   => 30,
                'pass_score'        => $pass,
                'allow_retake'      => true,
                'max_retakes'       => 3,
                'randomise_questions' => true,
                'randomise_options' => true,
                'show_answers_after' => true,
                'created_by'        => $this->userIds[0],
            ]);

            $this->ins('lms_questionnaire_assignments', [
                'questionnaire_id'    => $qnId,
                'course_id'           => $courseId,
                'assignment_type'     => 'post_course',
                'is_required'         => true,
                'due_days_after_enrol' => 30,
            ]);

            $questions = [];
            foreach ($qData as $sort => $q) {
                $correctText = null;
                if (in_array($q['type'], ['mcq', 'true_false'])) {
                    foreach ($q['options'] as [$optText, $isCorrect]) {
                        if ($isCorrect) {
                            $correctText = $optText;
                        }
                    }
                }

                $questionId = $this->ins('lms_questions', [
                    'questionnaire_id' => $qnId,
                    'bank_id'          => $banks[0],
                    'type'             => $q['type'],
                    'question_text'    => $q['text'],
                    'context_text'     => null,
                    'correct_answer'   => $correctText !== null ? json_encode($correctText) : null,
                    'explanation'      => $q['explanation'] ?? null,
                    'points'           => $q['points'],
                    'sort_order'       => $sort,
                ]);

                if (in_array($q['type'], ['mcq', 'true_false'])) {
                    foreach ($q['options'] as $oi => [$optText, $isCorrect]) {
                        $this->ins('lms_question_options', [
                            'question_id' => $questionId,
                            'option_text' => $optText,
                            'is_correct'  => $isCorrect,
                            'rating_value' => null,
                            'sort_order'  => $oi,
                        ]);
                    }
                }

                $this->ins('question_skill_mapping', [
                    'question_id' => $questionId,
                    'skill_id'    => $skillIdList[$sort % count($skillIdList)],
                ]);

                $questions[] = ['id' => $questionId, 'type' => $q['type'], 'points' => $q['points'], 'correct' => $correctText];
            }

            $this->questionnaires[] = ['id' => $qnId, 'course_id' => $courseId, 'pass' => $pass, 'questions' => $questions];
        }
        $this->command->info('  ✓ Question banks (2) & questionnaires (' . count($this->questionnaires) . ')');
    }

    private function questionTemplates(string $title): array
    {
        return [
            ['type' => 'mcq', 'points' => 2, 'text' => "Which statement best applies to {$title}?",
                'explanation' => 'Refer to the relevant Act.',
                'options' => [['It is governed by SA legislation', true], ['It is optional', false], ['It does not apply at work', false], ['None of the above', false]]],
            ['type' => 'true_false', 'points' => 1, 'text' => "{$title} is a once-off requirement that never needs review.",
                'options' => [['True', false], ['False', true]]],
            ['type' => 'mcq', 'points' => 2, 'text' => 'What is the primary benefit of completing this course?',
                'options' => [['Improved compliance & competence', true], ['Nothing', false], ['Longer meetings', false], ['Higher tax', false]]],
            ['type' => 'short_answer', 'points' => 2, 'text' => 'Name one key principle covered in this course.',
                'explanation' => 'Any valid principle is accepted.'],
            ['type' => 'long_answer', 'points' => 3, 'text' => 'Describe how you will apply this learning in your role (manual marking).'],
        ];
    }

    // ── Enrolments (+ lesson completions + certificates) ────────────────────────

    private function seedEnrollments(): void
    {
        $statuses = ['completed', 'in_progress', 'enrolled', 'completed', 'failed'];
        $courseList = array_values($this->courseIds);

        foreach ($this->userIds as $ui => $userId) {
            $myCourses = array_slice($courseList, 0, min(4, count($courseList)));
            foreach ($myCourses as $idx => $courseId) {
                $status = $statuses[($ui + $idx) % count($statuses)];
                $enrolledAt = now()->subDays(rand(20, 120));
                $score = match ($status) {
                    'completed' => rand(70, 98),
                    'failed'    => rand(35, 64),
                    default     => null,
                };
                $progress = match ($status) {
                    'completed', 'failed' => 100,
                    'in_progress'         => rand(20, 80),
                    default               => 0,
                };
                $completedAt = in_array($status, ['completed', 'failed']) ? (clone $enrolledAt)->addDays(rand(5, 30)) : null;

                $enrollId = $this->ins('lms_enrollments', [
                    'user_id'        => $userId,
                    'course_id'      => $courseId,
                    'status'         => $status,
                    'progress_pct'   => $progress,
                    'score'          => $score,
                    'enrolled_at'    => $enrolledAt,
                    'started_at'     => $status !== 'enrolled' ? (clone $enrolledAt)->addDay() : null,
                    'completed_at'   => $completedAt,
                    'due_date'       => (clone $enrolledAt)->addDays(60),
                    'time_spent_mins' => rand(15, 180),
                ]);

                $lessons = DB::table('lms_lessons')->where('course_id', $courseId)->orderBy('id')->pluck('id')->all();
                $completeCount = match ($status) {
                    'completed', 'failed' => count($lessons),
                    'in_progress'         => (int) floor(count($lessons) * 0.5),
                    default               => 0,
                };
                foreach (array_slice($lessons, 0, $completeCount) as $lessonId) {
                    $this->ins('lms_lesson_completions', [
                        'enrollment_id'  => $enrollId,
                        'lesson_id'      => $lessonId,
                        'completed_at'   => (clone $enrolledAt)->addDays(rand(2, 25)),
                        'time_spent_mins' => rand(5, 30),
                    ]);
                }

                if ($status === 'completed' && $score >= 70) {
                    $this->ins('lms_certificates', [
                        'enrollment_id'     => $enrollId,
                        'certificate_number' => 'CERT-' . strtoupper(Str::random(8)),
                        'issued_at'         => $completedAt,
                        'expires_at'        => (clone $completedAt)->addYear(),
                        'issued_by'         => $this->userIds[0],
                        'nqf_level'         => DB::table('lms_courses')->where('id', $courseId)->value('nqf_level'),
                        'hr_person_id'      => $this->hrId($ui),
                    ]);
                }
            }
        }
        $this->command->info('  ✓ Enrollments, lesson completions & certificates');
    }

    // ── Assessments (attempts, responses, scores, manual marking) ───────────────

    private function seedAssessments(): void
    {
        foreach ($this->questionnaires as $qn) {
            $assignmentId = DB::table('lms_questionnaire_assignments')->where('questionnaire_id', $qn['id'])->value('id');
            $enrollments = DB::table('lms_enrollments')
                ->where('course_id', $qn['course_id'])
                ->whereIn('status', ['completed', 'failed', 'in_progress'])
                ->limit(6)->get();

            foreach ($enrollments as $en) {
                $maxScore = array_sum(array_column($qn['questions'], 'points'));
                $willPass = $en->status === 'completed';

                $autoEarned = 0;
                $manualEarned = 0;
                $pendingManual = 0;

                $attemptId = $this->ins('lms_questionnaire_attempts', [
                    'questionnaire_id' => $qn['id'],
                    'user_id'          => $en->user_id,
                    'enrollment_id'    => $en->id,
                    'assignment_id'    => $assignmentId,
                    'status'           => 'completed',
                    'attempt_number'   => 1,
                    'started_at'       => now()->subDays(rand(5, 30)),
                    'completed_at'     => now()->subDays(rand(1, 4)),
                    'score'            => 0,
                ]);

                foreach ($qn['questions'] as $q) {
                    $isAuto = in_array($q['type'], ['mcq', 'true_false', 'short_answer']);
                    $correct = $isAuto ? ($willPass ? rand(0, 100) < 80 : rand(0, 100) < 45) : null;
                    $pts = $isAuto ? ($correct ? $q['points'] : 0) : null;
                    if ($isAuto) {
                        $autoEarned += $pts;
                    } else {
                        $pendingManual++;
                    }

                    $responseText = match ($q['type']) {
                        'mcq', 'true_false' => $correct ? ($q['correct'] ?? 'Correct option') : 'Distractor option',
                        'short_answer'      => 'Compliance with legislation',
                        default             => 'I will apply this by documenting procedures and coaching my team.',
                    };

                    $responseId = $this->ins('lms_question_responses', [
                        'attempt_id'    => $attemptId,
                        'question_id'   => $q['id'],
                        'response_text' => $responseText,
                        'response_json' => in_array($q['type'], ['mcq', 'true_false']) ? json_encode([$responseText]) : null,
                        'is_correct'    => $isAuto ? $correct : null,
                        'points_awarded' => $isAuto ? $pts : null,
                        'reviewed_by'   => $isAuto ? $this->userIds[0] : null,
                        'reviewed_at'   => $isAuto ? now()->subDays(rand(1, 3)) : null,
                        'reviewer_notes' => null,
                    ]);

                    if (! $isAuto) {
                        $marked = rand(0, 1) === 1;
                        $awarded = $marked ? rand(1, $q['points']) : null;
                        if ($marked) {
                            $manualEarned += $awarded;
                            $pendingManual--;
                        }
                        $this->ins('lms_manual_marking', [
                            'response_id'     => $responseId,
                            'attempt_id'      => $attemptId,
                            'question_id'     => $q['id'],
                            'questionnaire_id' => $qn['id'],
                            'user_id'         => $en->user_id,
                            'assigned_to'     => $this->userIds[0],
                            'status'          => $marked ? 'reviewed' : 'pending',
                            'max_points'      => $q['points'],
                            'points_awarded'  => $awarded,
                            'marker_notes'    => $marked ? 'Good practical application.' : null,
                            'marked_by'       => $marked ? $this->userIds[0] : null,
                            'marked_at'       => $marked ? now()->subDays(rand(1, 2)) : null,
                        ]);

                        if ($marked) {
                            DB::table('lms_question_responses')->where('id', $responseId)->update([
                                'points_awarded' => $awarded,
                                'reviewed_by'    => $this->userIds[0],
                                'reviewed_at'    => now()->subDays(rand(1, 2)),
                            ]);
                        }
                    }
                }

                $earned = $autoEarned + $manualEarned;
                $pct = $maxScore > 0 ? round($earned / $maxScore * 100, 2) : 0;
                $passed = $pct >= $qn['pass'];
                $fullyMarked = $pendingManual === 0;

                DB::table('lms_questionnaire_attempts')->where('id', $attemptId)->update(['score' => $pct]);

                $this->ins('lms_assessment_scores', [
                    'attempt_id'          => $attemptId,
                    'questionnaire_id'    => $qn['id'],
                    'user_id'             => $en->user_id,
                    'total_points'        => $maxScore,
                    'auto_earned_points'  => $autoEarned,
                    'manual_earned_points' => $manualEarned,
                    'total_earned_points' => $earned,
                    'percentage'          => $pct,
                    'passed'              => $passed,
                    'is_final'            => $fullyMarked,
                    'finalized_at'        => $fullyMarked ? now()->subDays(rand(0, 1)) : null,
                    'finalized_by'        => $fullyMarked ? $this->userIds[0] : null,
                ]);
            }
        }
        $this->command->info('  ✓ Attempts, responses, manual marking & assessment scores');
    }

    // ── Understanding scores, weak areas, learning gap reports ───────────────────

    private function seedUnderstanding(): void
    {
        $enrollments = DB::table('lms_enrollments')->whereIn('status', ['completed', 'failed'])->get();

        foreach ($enrollments as $en) {
            $quiz = $en->score ?? rand(40, 95);
            $lessonScore = rand(50, 100);
            $time = rand(50, 100);
            $practical = rand(40, 100);
            $manager = rand(50, 100);
            $total = round(($quiz * 0.4) + ($lessonScore * 0.2) + ($time * 0.15) + ($practical * 0.15) + ($manager * 0.1), 2);
            $guessing = $quiz > 85 && $time < 60;
            $rushed = $time < 55;
            $lowEngagement = $lessonScore < 55;
            $blocked = $total < 50;

            $this->ins('lms_understanding_scores', [
                'enrollment_id'         => $en->id,
                'user_id'               => $en->user_id,
                'course_id'             => $en->course_id,
                'lesson_completion_score' => $lessonScore,
                'quiz_score'            => $quiz,
                'practical_score'       => $practical,
                'time_score'            => $time,
                'manager_score'         => $manager,
                'total_score'           => $total,
                'grade'                 => $this->grade($total),
                'guessing_detected'     => $guessing,
                'rushed_detected'       => $rushed,
                'low_engagement_detected' => $lowEngagement,
                'is_certificate_blocked' => $blocked,
                'certificate_blocked_reason' => $blocked ? 'Understanding score below threshold.' : null,
                'refresher_recommended' => $total < 70,
                'manager_confirmed'     => (bool) rand(0, 1),
                'calculated_at'         => now()->subDays(rand(1, 20)),
            ]);

            if ($total < 70) {
                $qn = collect($this->questionnaires)->firstWhere('course_id', $en->course_id);
                $this->ins('lms_weak_learning_areas', [
                    'user_id'          => $en->user_id,
                    'course_id'        => $en->course_id,
                    'questionnaire_id' => $qn['id'] ?? null,
                    'topic'            => 'Core principles & application',
                    'failure_count'    => rand(1, 4),
                    'average_score'    => rand(20, 55),
                    'last_failed_at'   => now()->subDays(rand(1, 15)),
                    'is_resolved'      => false,
                ]);

                $this->ins('lms_learning_gap_reports', [
                    'enrollment_id'        => $en->id,
                    'user_id'              => $en->user_id,
                    'course_id'            => $en->course_id,
                    'report_type'          => 'individual',
                    'guessing_detected'    => $guessing,
                    'rushed_detected'      => $rushed,
                    'low_engagement_detected' => $lowEngagement,
                    'total_understanding_score' => $total,
                    'pass_threshold'       => 70,
                    'passed'               => false,
                    'weak_areas_count'     => 1,
                    'recommendations'      => json_encode([
                        'Revisit module 1 and retake the assessment after review.',
                        'Book a coaching session with your line manager.',
                    ]),
                    'generated_at'         => now()->subDays(rand(1, 10)),
                    'generated_by'         => $this->userIds[0],
                ]);
            }
        }
        $this->command->info('  ✓ Understanding scores, weak areas & gap reports');
    }

    // ── AI recommendations, sessions & artefacts ────────────────────────────────

    private function seedAi(): void
    {
        $types = ['job_title', 'performance_gap', 'failed_topics', 'career_path', 'policy_requirement', 'refresher'];
        $personas = ['employee', 'manager', 'hr_admin'];
        $courseList = array_values($this->courseIds);

        foreach ($this->userIds as $i => $uid) {
            for ($r = 0; $r < rand(1, 2); $r++) {
                $type = $types[($i + $r) % count($types)];
                $this->ins('lms_ai_recommendations', [
                    'user_id'             => $uid,
                    'course_id'           => $courseList[($i + $r) % count($courseList)],
                    'recommendation_type' => $type,
                    'reason'              => "Recommended based on your {$type} profile and recent activity.",
                    'priority'            => rand(1, 5),
                    'ai_context'          => json_encode(['source' => $type, 'confidence' => rand(60, 95) / 100]),
                    'ai_response'         => json_encode(['summary' => 'Completing this course will close an identified gap and improve role readiness.']),
                    'status'              => ['pending', 'accepted', 'dismissed'][rand(0, 2)],
                    'generated_at'        => now()->subDays(rand(1, 15)),
                    'expires_at'          => now()->addDays(30),
                    'created_by'          => $this->userIds[0],
                ]);
            }

            if ($i % 2 === 0) {
                $sessionUuid = (string) Str::uuid();
                $this->ins('lms_ai_sessions', [
                    'session_id'   => $sessionUuid,
                    'user_id'      => $uid,
                    'context_type' => 'course',
                    'context_id'   => $courseList[$i % count($courseList)],
                    'persona'      => $personas[$i % 3],
                    'title'        => 'Study help session',
                    'status'       => 'active',
                ]);
                $this->ins('lms_ai_artefacts', [
                    'user_id'         => $uid,
                    'session_id'      => $sessionUuid,
                    'artefact_type'   => ['study_guide', 'practice_quiz', 'summary'][$i % 3],
                    'context_type'    => 'course',
                    'context_id'      => $courseList[$i % count($courseList)],
                    'title'           => 'Generated study guide',
                    'content'         => 'Key points: understand the legislation, apply best practice, document actions.',
                    'structured_data' => json_encode(['bullets' => ['Point A', 'Point B', 'Point C']]),
                    'tokens_used'     => rand(200, 1500),
                ]);
            }
        }
        $this->command->info('  ✓ AI recommendations, sessions & artefacts');
    }

    // ── Compliance rules, assignments, escalations, reports ─────────────────────

    private function seedComplianceRules(): void
    {
        $mandatory = DB::table('lms_courses')->where('is_mandatory', true)->pluck('title', 'id');

        foreach ($mandatory as $courseId => $title) {
            $ruleId = $this->ins('lms_compliance_rules', [
                'course_id'           => $courseId,
                'name'                => 'Annual Compliance — ' . $title,
                'description'         => 'All employees must complete this course annually.',
                'scope_type'          => 'all',
                'scope_id'            => null,
                'scope_value'         => null,
                'frequency'           => 'annually',
                'frequency_days'      => 365,
                'due_within_days'     => 30,
                'grace_period_days'   => 7,
                // A list, not a scalar: the column is jsonb cast to array, so a bare 14 reads
                // back as an int and crashes the reminder sweep for every company behind it.
                'reminder_days_before' => [30, 14, 7, 1],
                'escalate_after_days' => 7,
                'escalate_to_role'    => 'HR',
                'restrict_actions'    => (bool) rand(0, 1),
                'is_active'           => true,
                'created_by'          => $this->userIds[0],
            ]);

            foreach ($this->userIds as $uid) {
                $offset = rand(-25, 45);
                $status = $offset < 0 ? (rand(0, 1) ? 'overdue' : 'completed') : (rand(0, 1) ? 'completed' : ($offset < 15 ? 'in_progress' : 'pending'));
                $waived = $status === 'pending' && rand(0, 5) === 0;
                $assignmentId = $this->ins('lms_compliance_assignments', [
                    'rule_id'      => $ruleId,
                    'user_id'      => $uid,
                    'course_id'    => $courseId,
                    'enrollment_id' => DB::table('lms_enrollments')->where(['user_id' => $uid, 'course_id' => $courseId])->value('id'),
                    'assigned_at'  => now()->subDays(30),
                    'due_date'     => now()->addDays($offset),
                    'completed_at' => $status === 'completed' ? now()->subDays(rand(1, 15)) : null,
                    'status'       => $waived ? 'waived' : $status,
                    'waived_by'    => $waived ? $this->userIds[0] : null,
                    'waived_reason' => $waived ? 'Exempted — on extended leave.' : null,
                    'waived_at'    => $waived ? now()->subDays(rand(1, 10)) : null,
                    'compliance_period_start' => now()->startOfYear(),
                    'compliance_period_end'   => now()->endOfYear(),
                ]);

                if ($status === 'overdue' && rand(0, 1)) {
                    $resolved = rand(0, 2);
                    $this->ins('lms_compliance_escalations', [
                        'assignment_id'    => $assignmentId,
                        'user_id'          => $uid,
                        'escalated_to_id'  => $this->userIds[0],
                        'escalated_to_role' => 'HR',
                        'days_overdue'     => abs($offset),
                        'note'             => 'Compliance training overdue beyond grace period.',
                        'status'           => ['open', 'acknowledged', 'resolved'][$resolved],
                        'escalated_at'     => now()->subDays(rand(1, 3)),
                        'resolved_at'      => $resolved === 2 ? now()->subDay() : null,
                        'resolved_by'      => $resolved === 2 ? $this->userIds[0] : null,
                    ]);
                }
            }

            $assigned = DB::table('lms_compliance_assignments')->where('rule_id', $ruleId);
            $total = (clone $assigned)->count();
            $completed = (clone $assigned)->where('status', 'completed')->count();
            $overdue = (clone $assigned)->where('status', 'overdue')->count();
            $pending = (clone $assigned)->whereIn('status', ['pending', 'in_progress'])->count();
            $this->ins('lms_compliance_reports', [
                'report_type'     => 'rule_snapshot',
                'scope_id'        => $courseId,
                'scope_name'      => $title,
                'period_start'    => now()->startOfYear(),
                'period_end'      => now(),
                'total_employees' => count($this->userIds),
                'total_assignments' => $total,
                'completed_count' => $completed,
                'overdue_count'   => $overdue,
                'pending_count'   => $pending,
                'compliance_rate' => $total ? round($completed / $total * 100, 2) : 0,
                'report_data'     => json_encode(['rule_id' => $ruleId]),
                'generated_at'    => now(),
                'generated_by'    => $this->userIds[0],
            ]);
        }
        $this->command->info('  ✓ Compliance rules, assignments, escalations & reports');
    }

    // ── Certifications ──────────────────────────────────────────────────────────

    private function seedCertifications(): void
    {
        $templateId = $this->ins('lms_certificate_templates', [
            'name'              => 'Default Certificate',
            'description'       => 'Standard accredited certificate template.',
            'orientation'       => 'landscape',
            'background_color'  => '#ffffff',
            'accent_color'      => '#1d4ed8',
            'title_text'        => 'Certificate of Completion',
            'subtitle_text'     => 'This certifies successful completion of the programme',
            'body_html'         => '<p>This certifies that the learner has successfully completed the programme.</p>',
            'signatory_1_name'  => 'Training Manager',
            'signatory_1_title' => 'Skills Development Facilitator',
            'signatory_2_name'  => 'Chief Executive Officer',
            'signatory_2_title' => 'CEO',
            'requires_understanding_score' => true,
            'min_understanding_score' => 60,
            'is_active'         => true,
            'created_by'        => $this->userIds[0],
        ]);

        DB::table('lms_certificates')->whereNull('template_id')->update(['template_id' => $templateId]);

        $mandatory = DB::table('lms_courses')->where('is_mandatory', true)->get(['id', 'title']);
        foreach ($mandatory as $course) {
            $certId = $this->ins('lms_certifications', [
                'name'                  => $course->title . ' Certification',
                'description'           => 'Formal certification for ' . $course->title . '.',
                'course_id'             => $course->id,
                'template_id'           => $templateId,
                'valid_days'            => 365,
                'refresher_before_days' => 30,
                'reminder_days'         => json_encode([30, 14, 7]),
                'actions_blocked_when_expired' => (bool) rand(0, 1),
                'requires_understanding_score' => true,
                'min_understanding_score' => 60,
                'auto_issue'            => true,
                'is_active'             => true,
                'created_by'            => $this->userIds[0],
            ]);

            $completed = DB::table('lms_enrollments')->where(['course_id' => $course->id, 'status' => 'completed'])->get();
            foreach ($completed as $en) {
                $issuedAt = now()->subMonths(rand(1, 14));
                $expiresAt = (clone $issuedAt)->addYear();
                $status = $expiresAt->isPast() ? 'expired' : (($expiresAt->diffInDays(now()) < 30) ? 'refresher_required' : 'active');
                $empCertId = $this->ins('employee_certifications', [
                    'user_id'         => $en->user_id,
                    'certification_id' => $certId,
                    'certificate_id'  => DB::table('lms_certificates')->where('enrollment_id', $en->id)->value('id'),
                    'status'          => $status,
                    'issued_at'       => $issuedAt,
                    'expires_at'      => $expiresAt,
                    'reminders_sent'  => $status === 'refresher_required' ? rand(1, 2) : 0,
                    'last_reminder_at' => $status === 'refresher_required' ? now()->subDays(rand(1, 10)) : null,
                    'notes'           => null,
                ]);

                $this->ins('certification_expiry_logs', [
                    'employee_certification_id' => $empCertId,
                    'user_id'                 => $en->user_id,
                    'certification_id'        => $certId,
                    'event_type'              => 'issued',
                    'days_until_expiry'       => 365,
                    'action_key'              => null,
                    'notes'                   => 'Certificate issued on ' . $issuedAt->toDateString(),
                    'created_by'              => $this->userIds[0],
                ]);
                if ($status === 'expired') {
                    $this->ins('certification_expiry_logs', [
                        'employee_certification_id' => $empCertId,
                        'user_id'                 => $en->user_id,
                        'certification_id'        => $certId,
                        'event_type'              => 'expired',
                        'days_until_expiry'       => 0,
                        'action_key'              => 'block_actions',
                        'notes'                   => 'Certificate expired on ' . $expiresAt->toDateString(),
                        'created_by'              => $this->userIds[0],
                    ]);
                }
            }
        }
        $this->command->info('  ✓ Certificate template, certifications, employee certifications & expiry logs');
    }

    // ── Effectiveness ───────────────────────────────────────────────────────────

    private function seedEffectiveness(): void
    {
        foreach ($this->courseIds as $title => $courseId) {
            $enrolled = DB::table('lms_enrollments')->where('course_id', $courseId);
            $enrolledCount = (clone $enrolled)->count();
            $completedCount = (clone $enrolled)->where('status', 'completed')->count();
            $passedCount = (clone $enrolled)->where('status', 'completed')->where('score', '>=', 70)->count();
            $avgScore = (clone $enrolled)->whereNotNull('score')->avg('score');
            $avgUnderstanding = DB::table('lms_understanding_scores')->where('course_id', $courseId)->avg('total_score');

            $this->ins('lms_effectiveness_snapshots', [
                'course_id'           => $courseId,
                'period_start'        => now()->startOfYear(),
                'period_end'          => now(),
                'completion_rate'     => $enrolledCount ? round($completedCount / $enrolledCount * 100, 2) : 0,
                'pass_rate'           => $completedCount ? round($passedCount / max(1, $completedCount) * 100, 2) : 0,
                'avg_score'           => round((float) $avgScore, 2),
                'avg_understanding_score' => round((float) $avgUnderstanding, 2),
                'understanding_improvement' => rand(5, 25),
                'repeated_failure_rate' => rand(2, 15),
                'roi_pct'             => rand(80, 220),
                'computed_at'         => now(),
                'snapshot_data'       => json_encode(['enrolled' => $enrolledCount, 'completed' => $completedCount]),
            ]);
        }

        $this->ins('lms_effectiveness_reports', [
            'title'        => 'Annual Training Effectiveness ' . now()->year,
            'report_type'  => 'organisation',
            'scope_type'   => 'organisation',
            'scope_id'     => null,
            'period_start' => now()->startOfYear(),
            'period_end'   => now(),
            'metrics'      => json_encode(['courses' => count($this->courseIds), 'roi_pct' => rand(110, 180)]),
            'generated_by' => $this->userIds[0],
            'generated_at' => now(),
        ]);
        $this->command->info('  ✓ Effectiveness snapshots & report');
    }

    // ── PA integration ──────────────────────────────────────────────────────────

    private function seedPaIntegration(): void
    {
        $courseList = array_values($this->courseIds);
        $statuses = ['pending', 'accepted', 'enrolled', 'completed', 'dismissed'];
        $priorities = ['low', 'medium', 'high', 'critical'];

        foreach (array_slice($this->userIds, 0, min(8, count($this->userIds))) as $i => $uid) {
            $this->ins('lms_pa_training_recommendations', [
                'employee_id'         => $uid,
                'cycle_id'            => $this->cycleId,
                'course_id'           => $courseList[$i % count($courseList)],
                'gap_area'            => ['Leadership', 'Communication', 'Technical Skills', 'Compliance'][$i % 4],
                'kpi_title'           => 'Develop ' . ['Leadership', 'Communication', 'Technical Skills', 'Compliance'][$i % 4],
                'score_pct'           => rand(40, 75),
                'recommendation_reason' => 'Identified development area from performance review.',
                'status'              => $statuses[$i % count($statuses)],
                'priority'            => $priorities[$i % 4],
                'created_by'          => $this->userIds[0],
                'enrolled_at'         => $i % 2 ? now()->subDays(rand(5, 40)) : null,
                'completed_at'        => $i % 3 === 0 ? now()->subDays(rand(1, 20)) : null,
            ]);

            if ($i % 2 === 0) {
                $this->ins('lms_development_training_plans', [
                    'employee_id'         => $uid,
                    'manager_id'          => $this->userIds[0],
                    'cycle_id'            => $this->cycleId,
                    'title'               => 'Development Plan ' . now()->year,
                    'status'              => ['draft', 'active', 'completed'][$i % 3],
                    'overall_progress'    => rand(0, 100),
                    'lms_activities'      => json_encode(['courses' => [$courseList[$i % count($courseList)]]]),
                    'notes'               => 'Individual development plan linked to performance appraisal.',
                    'target_completion_date' => now()->addMonths(6),
                    'created_by'          => $this->userIds[0],
                ]);
            }
        }
        $this->command->info('  ✓ PA training recommendations & development plans');
    }

    // ── Scheduled reports ───────────────────────────────────────────────────────

    private function seedScheduledReports(): void
    {
        // day_of_week is a smallint (0=Sun … 6=Sat); day_of_month is a smallint.
        $reports = [
            ['Weekly Compliance Digest', 'compliance', 'weekly', 1, null],
            ['Monthly Training Effectiveness', 'effectiveness', 'monthly', null, 1],
            ['Quarterly Skills Gap Report', 'skills_gap', 'quarterly', null, 1],
        ];
        foreach ($reports as $i => [$name, $key, $cadence, $dow, $dom]) {
            $this->ins('lms_scheduled_reports', [
                'name'         => $name,
                'report_key'   => $key,
                'cadence'      => $cadence,
                'run_time'     => '07:00',
                'day_of_week'  => $dow,
                'day_of_month' => $dom,
                'format'       => ['pdf', 'excel', 'pdf'][$i % 3],
                'recipients'   => json_encode(['hr@example.co.za', 'training@example.co.za']),
                'active'       => true,
                'next_run_at'  => now()->addDays(rand(1, 7)),
                'created_by'   => $this->userIds[0],
            ]);
        }
        $this->command->info('  ✓ Scheduled reports (3)');
    }

    // ── QCTO core: unit standards + outcomes ────────────────────────────────────

    private function seedQctoCore(): void
    {
        $units = [
            ['8013', 'Demonstrate knowledge of occupational health and safety', 4, 8],
            ['114971', 'Apply leadership concepts in a work context', 5, 12],
            ['252041', 'Promote a learning culture in an organisation', 5, 10],
        ];
        foreach ($units as [$saqa, $title, $nqf, $credits]) {
            $unitId = $this->ins('lms_unit_standards', [
                'saqa_id'       => $saqa,
                'title'         => $title,
                'credits'       => $credits,
                'nqf_level'     => $nqf,
                'field_of_study' => 'Business, Commerce and Management Studies',
                'sub_field'     => 'Generic Management',
                'seta_id'       => $this->setaId,
                'type'          => 'unit_standard',
                'status'        => 'registered',
                'registered_at' => now()->subYears(3),
                'expires_at'    => now()->addYears(2),
                'purpose'       => 'Learners credited with this unit standard are able to ' . strtolower($title) . '.',
            ]);
            for ($o = 1; $o <= 3; $o++) {
                $this->ins('lms_unit_standard_outcomes', [
                    'unit_standard_id'   => $unitId,
                    'outcome_code'       => "SO{$o}",
                    'outcome_title'      => "Specific Outcome {$o}",
                    'performance_criteria' => json_encode(["AC{$o}.1", "AC{$o}.2"]),
                    'sort_order'         => $o,
                ]);
            }
        }
        $this->command->info('  ✓ Unit standards (3) & outcomes');
    }

    // ── Learnerships (+ enrolments + logbook + EISA) ────────────────────────────

    private function seedLearnerships(): void
    {
        $kCourse = $this->courseIds['Skills Development & SETA Compliance'] ?? null;
        $pCourse = $this->courseIds['Leadership Foundations'] ?? null;
        $aqpId = DB::table('lms_aqps')->value('id');

        $defs = [
            ['Human Resources Management NQF 5', '59097', 5, 120, 12, 1200, 3500],
            ['Generic Management NQF 4', '57712', 4, 150, 18, 1500, 3000],
            ['IT Technical Support NQF 5', '78964', 5, 130, 12, 1000, 4000],
        ];
        foreach ($defs as $di => [$title, $saqa, $nqf, $credits, $months, $weHours, $stipend]) {
            $learnershipId = $this->ins('lms_learnerships', [
                'title'              => $title,
                'saqa_id'            => $saqa,
                'seta_id'            => $this->setaId,
                'nqf_level'          => $nqf,
                'total_credits'      => $credits,
                'duration_months'    => $months,
                'learnership_type'   => 'learnership',
                'ofo_code'           => ['2421101', '1221001', '3512001'][$di],
                'seta_programme_code' => 'SPC-' . rand(1000, 9999),
                'k_course_id'        => $kCourse,
                'p_course_id'        => $pCourse,
                'we_hours_required'  => $weHours,
                'we_credits'         => (int) round($credits * 0.3),
                'stipend_per_month'  => $stipend,
                'stipend_currency'   => 'ZAR',
                'is_active'          => true,
                'description'        => "{$title} accredited learnership programme.",
                'aqp_id'             => $aqpId,
                'created_by'         => $this->userIds[0],
            ]);

            $enrolCount = max(1, min(3, count($this->hrPersonIds)));
            for ($e = 0; $e < $enrolCount; $e++) {
                $status = ['active', 'completed', 'registered'][$e % 3];
                $hrId = $this->hrId($di + $e);
                $passed = $status === 'completed';
                $enrolId = $this->ins('lms_learnership_enrolments', [
                    'learnership_id'        => $learnershipId,
                    'hr_person_id'          => $hrId,
                    'seta_learner_id'       => 'SL-' . strtoupper(Str::random(6)),
                    'seta_registration_number' => 'SETA-' . strtoupper(Str::random(6)),
                    'start_date'            => now()->subMonths(rand(2, 6))->toDateString(),
                    'end_date'              => now()->addMonths($months)->toDateString(),
                    'status'                => $status,
                    'k_enrollment_id'       => null,
                    'p_enrollment_id'       => null,
                    'we_hours_logged'       => $passed ? $weHours : rand(100, $weHours),
                    'we_logbook_complete'   => $passed,
                    'k_completed_at'        => $passed ? now()->subMonths(rand(2, 4)) : null,
                    'p_completed_at'        => $passed ? now()->subMonths(rand(1, 2)) : null,
                    'we_completed_at'       => $passed ? now()->subDays(rand(10, 30)) : null,
                    'final_assessment_date' => $passed ? now()->subDays(rand(5, 20)) : null,
                    'final_score'           => $passed ? rand(60, 95) : null,
                    'passed'                => $passed,
                    'certificate_number'    => $passed ? 'LCERT-' . strtoupper(Str::random(6)) : null,
                    'certificate_issued_at' => $passed ? now()->subDays(rand(1, 10)) : null,
                    'stipend_amount'        => $stipend,
                    'notes'                 => null,
                    'created_by'            => $this->userIds[0],
                ]);

                for ($l = 0; $l < 3; $l++) {
                    $signed = rand(0, 1) === 1;
                    $this->ins('lms_learnership_logbook', [
                        'learnership_enrolment_id' => $enrolId,
                        'log_date'           => now()->subDays(rand(5, 60))->toDateString(),
                        'activity_description' => 'Completed workplace activity and reflection.',
                        'hours_logged'       => rand(4, 8),
                        'workplace_coach'    => 'Workplace Coach ' . ($l + 1),
                        'coach_signed_off'   => $signed,
                        'signed_off_at'      => $signed ? now()->subDays(rand(1, 10)) : null,
                        'learner_reflection' => 'I applied the theory in a real workplace task.',
                    ]);
                }

                $this->ins('lms_eisa_schedules', [
                    'learnership_id'     => $learnershipId,
                    'aqp_id'             => $aqpId,
                    'hr_person_id'       => $hrId,
                    'scheduled_date'     => now()->addMonths(rand(1, 5))->toDateString(),
                    'venue'              => 'Regional Assessment Centre',
                    'status'             => $passed ? 'completed' : 'scheduled',
                    'score'              => $passed ? rand(60, 95) : null,
                    'result'             => $passed ? 'competent' : null,
                    'certificate_number' => $passed ? 'EISA-' . strtoupper(Str::random(6)) : null,
                    'certificate_issued_at' => $passed ? now()->subDays(rand(1, 10)) : null,
                    'aqp_reference'      => 'AQP-' . strtoupper(Str::random(5)),
                    'notes'              => null,
                    'captured_by'        => $this->userIds[0],
                ]);
            }
        }
        $this->command->info('  ✓ Learnerships (3) with enrolments, logbooks & EISA');
    }

    // ── Portfolio of Evidence ───────────────────────────────────────────────────

    private function seedPoe(): void
    {
        $enrolments = DB::table('lms_learnership_enrolments')->limit(4)->get();
        $units = DB::table('lms_unit_standards')->pluck('id')->all();
        // assessor_id / moderator_id reference lms_registered_persons, not users.
        $assessorIds = DB::table('lms_registered_persons')->whereIn('person_type', ['assessor', 'assessor_moderator'])->pluck('id')->all();
        $moderatorIds = DB::table('lms_registered_persons')->whereIn('person_type', ['moderator', 'assessor_moderator'])->pluck('id')->all();
        if (empty($units) || $enrolments->isEmpty() || empty($assessorIds)) {
            $this->command->info('  ✓ PoE skipped (no unit standards / enrolments / assessors)');
            return;
        }

        foreach ($enrolments as $i => $le) {
            $decision = ['competent', 'not_yet_competent', null][$i % 3];
            $assessorId = $assessorIds[$i % count($assessorIds)];
            $moderatorId = $moderatorIds ? $moderatorIds[$i % count($moderatorIds)] : null;
            $portfolioId = $this->ins('lms_poe_portfolios', [
                'user_id'           => $this->uid($i),
                'course_id'         => null,
                'enrollment_id'     => null,
                'learnership_enrolment_id' => $le->id,
                'title'             => 'Portfolio of Evidence',
                'status'            => $decision ? 'assessed' : 'submitted',
                'assessor_id'       => $assessorId,
                'moderator_id'      => $decision === 'competent' ? $moderatorId : null,
                'submitted_at'      => now()->subDays(rand(10, 30)),
                'assessed_at'       => $decision ? now()->subDays(rand(1, 10)) : null,
                'moderated_at'      => $decision === 'competent' ? now()->subDays(rand(0, 5)) : null,
                'assessment_decision' => $decision,
                'moderation_decision' => $decision === 'competent' ? 'agreed' : null,
                'assessor_feedback' => $decision ? 'Evidence reviewed and meets requirements.' : null,
                'moderator_feedback' => $decision === 'competent' ? 'Moderation agreed.' : null,
                'learner_declared'  => true,
                'declared_at'       => now()->subDays(rand(11, 31)),
                'created_by'        => $this->userIds[0],
                'hr_person_id'      => $le->hr_person_id,
            ]);

            foreach (array_slice($units, 0, 2) as $si => $unitId) {
                $sectionId = $this->ins('lms_poe_sections', [
                    'poe_portfolio_id'  => $portfolioId,
                    'unit_standard_id'  => $unitId,
                    'title'             => 'Section ' . ($si + 1),
                    'description'       => 'Evidence aligned to the unit standard.',
                    'outcome_number'    => (string) ($si + 1),
                    'status'            => $decision ? 'assessed' : 'submitted',
                    'sort_order'        => $si,
                    'assessor_notes'    => $decision ? 'Meets the assessment criteria.' : null,
                    'assessment_decision' => $decision,
                    'learner_declaration' => true,
                ]);

                $verified = (bool) $decision;
                $this->ins('lms_poe_evidence', [
                    'poe_section_id'   => $sectionId,
                    'title'            => 'Workplace evidence document',
                    'description'      => 'Signed workplace activity record.',
                    'evidence_type'    => ['document', 'observation', 'report'][$si % 3],
                    'file_name'        => 'evidence-' . ($si + 1) . '.pdf',
                    'mime_type'        => 'application/pdf',
                    'size_bytes'       => rand(100000, 500000),
                    'is_verified'      => $verified,
                    'verified_by'      => $verified ? $this->userIds[0] : null,
                    'verified_at'      => $verified ? now()->subDays(rand(1, 8)) : null,
                    'verification_notes' => $verified ? 'Verified authentic.' : null,
                    'uploaded_by'      => $this->userIds[0],
                    'version'          => 1,
                ]);
            }
        }
        $this->command->info('  ✓ PoE portfolios, sections & evidence');
    }

    // ── WSP / ATR ────────────────────────────────────────────────────────────────

    private function seedWspAtr(): void
    {
        if (! $this->setaId) {
            $this->command->info('  ✓ WSP/ATR skipped (no SETA)');
            return;
        }

        $wspId = $this->ins('lms_wsp_plans', [
            'plan_year'           => now()->year,
            'seta_id'             => $this->setaId,
            'sdf_id'              => $this->sdfId,
            'due_date'            => now()->addMonths(2)->toDateString(),
            'submission_date'     => now()->subMonths(2)->toDateString(),
            'status'              => 'submitted',
            'total_levy_paid'     => 250000,
            'total_planned_spend' => 180000,
            'planned_spend_pct'   => 72,
            'mandatory_grant_claimed' => true,
            'discretionary_grant_applied' => true,
            'submission_reference' => 'WSP-' . now()->year . '-' . strtoupper(Str::random(5)),
            'notes'               => 'Annual Workplace Skills Plan.',
            'created_by'          => $this->userIds[0],
        ]);

        $courseList = array_values($this->courseIds);
        $learnershipId = DB::table('lms_learnerships')->value('id');
        $targets = ['african_male', 'african_female', 'coloured_female', 'indian_male', 'white_female', 'disabled'];
        foreach ($courseList as $i => $courseId) {
            $beneficiaries = rand(5, 30);
            $costPer = rand(1500, 4000);
            $this->ins('lms_wsp_items', [
                'wsp_plan_id'          => $wspId,
                'course_id'            => $courseId,
                'learnership_id'       => $i === 0 ? $learnershipId : null,
                'position_id'          => $this->pid($i),
                'training_type'        => $i === 0 ? 'learnership' : 'short_course',
                'training_description' => DB::table('lms_courses')->where('id', $courseId)->value('title'),
                'target_group'         => $targets[$i % count($targets)],
                'nqf_level'            => DB::table('lms_courses')->where('id', $courseId)->value('nqf_level'),
                'credits'              => DB::table('lms_courses')->where('id', $courseId)->value('credits'),
                'planned_beneficiaries' => $beneficiaries,
                'planned_cost_per_person' => $costPer,
                'total_planned_cost'   => $beneficiaries * $costPer,
            ]);
        }

        $atrId = $this->ins('lms_atr_reports', [
            'wsp_plan_id'         => $wspId,
            'seta_id'             => $this->setaId,
            'sdf_id'              => $this->sdfId,
            'report_year'         => now()->year - 1,
            'period_start'        => now()->subYear()->startOfYear()->toDateString(),
            'period_end'          => now()->subYear()->endOfYear()->toDateString(),
            'submission_date'     => now()->subMonths(2)->toDateString(),
            'status'              => 'submitted',
            'total_actual_spend'  => 165000,
            'actual_spend_pct'    => 92,
            'submission_reference' => 'ATR-' . (now()->year - 1) . '-' . strtoupper(Str::random(5)),
            'notes'               => 'Annual Training Report.',
            'created_by'          => $this->userIds[0],
        ]);

        $races = ['african', 'coloured', 'indian', 'white'];
        $genders = ['male', 'female'];
        $results = ['completed', 'in_progress', 'failed'];
        foreach (array_slice($this->hrPersonIds, 0, min(8, count($this->hrPersonIds))) as $i => $hrId) {
            $courseId = $courseList[$i % count($courseList)];
            $this->ins('lms_atr_items', [
                'atr_report_id'    => $atrId,
                'hr_person_id'     => $hrId,
                'course_id'        => $courseId,
                'learnership_id'   => $i === 0 ? $learnershipId : null,
                'training_type'    => 'short_course',
                'training_provider' => 'Internal Training Academy',
                'start_date'       => now()->subMonths(rand(3, 10))->toDateString(),
                'end_date'         => now()->subMonths(rand(1, 2))->toDateString(),
                'nqf_level'        => DB::table('lms_courses')->where('id', $courseId)->value('nqf_level'),
                'credits'          => DB::table('lms_courses')->where('id', $courseId)->value('credits'),
                'cost_per_person'  => rand(2000, 8000),
                'result'           => $results[$i % 3],
                'race'             => $races[$i % 4],
                'gender'           => $genders[$i % 2],
                'has_disability'   => $i % 7 === 0,
            ]);
        }
        $this->command->info('  ✓ WSP plan, items, ATR report & items');
    }

    // ── B-BBEE training spend + payroll snapshots ───────────────────────────────

    private function seedBbee(): void
    {
        $courseList = array_values($this->courseIds);
        $races = ['african', 'coloured', 'indian', 'white'];
        $genders = ['male', 'female'];
        $results = ['completed', 'in_progress', 'failed'];

        foreach (array_slice($this->hrPersonIds, 0, min(10, count($this->hrPersonIds))) as $i => $hrId) {
            $courseId = $courseList[$i % count($courseList)];
            $this->ins('lms_bbee_training_spend', [
                'spend_year'     => now()->year,
                'hr_person_id'   => $hrId,
                'course_id'      => $courseId,
                'learnership_id' => null,
                'enrollment_id'  => null,
                'race'           => $races[$i % 4],
                'gender'         => $genders[$i % 2],
                'has_disability' => $i % 8 === 0,
                'is_sa_citizen'  => true,
                'training_type'  => ['skills_programme', 'learnership', 'bursary'][$i % 3],
                'cost'           => rand(2000, 9000),
                'currency'       => 'ZAR',
                'nqf_level'      => DB::table('lms_courses')->where('id', $courseId)->value('nqf_level'),
                'credits'        => DB::table('lms_courses')->where('id', $courseId)->value('credits'),
                'is_accredited'  => (bool) rand(0, 1),
                'is_learnership' => $i % 3 === 0,
                'is_bursary'     => $i % 5 === 0,
                'is_absorbed'    => (bool) rand(0, 1),
                'training_date'  => now()->subMonths(rand(1, 11))->toDateString(),
                'result'         => $results[$i % 3],
            ]);
        }

        // One payroll snapshot for the levy/skills-spend calculation
        $this->ins('lms_payroll_snapshots', [
            'plan_year'              => now()->year,
            'annual_leviable_payroll' => 25000000,
            'levy_percentage'        => 1.0,
            'levy_amount'            => 250000,
            'notes'                  => 'Annual leviable payroll snapshot for SDL.',
            'captured_by'            => $this->userIds[0],
        ]);
        $this->command->info('  ✓ B-BBEE training spend & payroll snapshot');
    }

    // ── Helper ──────────────────────────────────────────────────────────────────

    private function lessonHtml(string $title): string
    {
        return "<h2>{$title}</h2>\n"
            . "<p>This lesson provides a comprehensive overview of <strong>{$title}</strong> and its practical application in the South African workplace context.</p>\n"
            . "<h3>Learning Objectives</h3>\n<ul>\n"
            . "<li>Understand the key concepts and principles covered in this lesson</li>\n"
            . "<li>Apply best practices in your daily work environment</li>\n"
            . "<li>Identify common challenges and how to address them</li>\n"
            . "<li>Demonstrate compliance with relevant legislation and policies</li>\n</ul>\n"
            . "<h3>Summary</h3>\n<p>Complete the assessment to confirm your understanding before proceeding.</p>";
    }
}
