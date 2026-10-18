<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Seeds realistic test data for the Performance Management module.
 * Run after PerformanceAppraisalSeeder: php artisan db:seed --class=PerformanceTestDataSeeder
 */
class PerformanceTestDataSeeder extends Seeder
{
    private array $userIds      = [];
    private array $deptIds      = [];
    private array $managers     = [];
    private array $employees    = [];
    private int   $adminId      = 1;

    public function run(): void
    {
        $this->userIds  = DB::table('users')->pluck('id')->toArray();
        $this->deptIds  = DB::table('company_units')->where('type', 'department')->pluck('id')->toArray();

        if (empty($this->userIds)) {
            $this->command->warn('No users found — aborting.');
            return;
        }

        // Split users: first 6 are managers, rest are employees
        $this->managers  = array_slice($this->userIds, 0, min(6, count($this->userIds)));
        $this->employees = array_slice($this->userIds, 0); // all users can be employees
        $this->adminId   = $this->userIds[0];

        DB::transaction(function () {
            $this->cleanup();
            $this->seedDepartmentPivots();
            $this->seedRatingScales();
            $this->seedSettings();
            $this->seedSkills();
            $this->seedTemplates();
            $this->seedCycles();
            $this->seedGoals();
            $this->seedKpis();
            $this->seedFeedback();
            $this->seedCheckins();
            $this->seedCoachingSessions();
            $this->seed360Reviews();
            $this->seedProbation();
            $this->seedWellness();
            $this->seedDevelopmentPlans();
            $this->seedSuccession();
            $this->seedTalentProfiles();
            $this->seedPolicies();
            $this->seedPips();
            $this->seedCalibration();
            $this->seedApprovalWorkflows();
            $this->seedScheduledReports();

            // ── New world-class features ──
            $this->seedCompetencies();
            $this->seedNineBox();
            $this->seedRecognition();
            $this->seedCompensation();
            $this->seedOneOnOneDetails();
            $this->seedNudges();
        });

        $this->command->info('Performance test data seeded successfully.');
    }

    // ── 0. Cleanup (idempotent) ──────────────────────────────────────────────────

    private function cleanup(): void
    {
        $tables = [
            // ── new feature areas (children first) ──
            'pa_recognition_reactions','pa_recognition_comments','pa_recognitions',
            'pa_nine_box_placements',
            'pa_comp_recommendations','pa_comp_reviews',
            'pa_nudges',
            'pa_checkin_action_items','pa_checkin_talking_points',
            'pa_employee_competencies','pa_role_competencies','pa_competency_anchors','pa_competencies',
            // ── original areas ──
            'pa_scheduled_reports','pa_approval_actions','pa_approval_requests',
            'pa_approval_workflow_steps','pa_approval_workflows',
            'pa_calibration_participants','pa_calibration_sessions',
            'pa_pip_reviews','pa_pip_progress_logs','pa_pip_objectives','pa_pip_plans',
            'pa_compliance_scores','pa_escalations','pa_policy_violations','pa_policy_exceptions','pa_policies',
            'pa_talent_profiles','pa_high_potentials','pa_succession_candidates','pa_succession_plans',
            'pa_learning_recommendations','pa_employee_skills','pa_development_activities','pa_development_plans',
            'pa_behavior_signals','pa_wellness_scores',
            'pa_probation_risk_scores','pa_probation_feedback','pa_probation_checkins',
            'pa_probation_objectives','pa_probation_reviews',
            'pa_360_responses','pa_360_raters','pa_360_reviews','pa_360_questions',
            'pa_coaching_sessions','pa_checkins','pa_feedback',
            'pa_ai_insights','pa_performance_snapshots',
            'pa_kpi_evidence','pa_kpi_scores','pa_kpi_progress_logs','pa_employee_kpis',
            'pa_goals','pa_cycles',
            'pa_template_questions','pa_template_sections','pa_templates',
            'pa_skills','pa_settings','pa_rating_values','pa_rating_scales',
        ];

        // Delete in reverse FK dependency order (children before parents)
        foreach ($tables as $t) {
            DB::table($t)->delete();
        }

        $this->command->info('  ✓ Existing PA test data cleared');
    }

    // ── 0b. Department pivots (needed for dept charts) ──────────────────────────

    private function seedDepartmentPivots(): void
    {
        if (empty($this->deptIds)) return;

        foreach ($this->employees as $i => $userId) {
            $deptId = $this->deptIds[$i % count($this->deptIds)];
            DB::table('company_unit_user_pivots')->insertOrIgnore([
                'user_id'    => $userId,
                'company_id' => $deptId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('  ✓ Department pivots ensured');
    }

    // ── 1. Rating Scales ────────────────────────────────────────────────────────

    private function seedRatingScales(): void
    {
        $scaleId = DB::table('pa_rating_scales')->insertGetId([
            'name'        => '5-Point Performance Scale',
            'description' => 'Standard 5-point rating scale used for all annual reviews.',
            'min_score'   => 1,
            'max_score'   => 5,
            'is_default'  => true,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $values = [
            [1, 'Below Expectations',  '#ef5350', 'Performance is consistently below required standards.'],
            [2, 'Needs Improvement',   '#ff7043', 'Some aspects meet expectations but key areas need development.'],
            [3, 'Meets Expectations',  '#42a5f5', 'Consistently meets all role expectations and standards.'],
            [4, 'Exceeds Expectations','#66bb6a', 'Regularly exceeds expectations and contributes beyond the role.'],
            [5, 'Outstanding',         '#ab47bc', 'Exceptional performance — a role model for the organisation.'],
        ];

        foreach ($values as $i => [$val, $label, $color, $desc]) {
            DB::table('pa_rating_values')->insert([
                'rating_scale_id' => $scaleId,
                'label'           => $label,
                'value'           => $val,
                'description'     => $desc,
                'color'           => $color,
                'sort_order'      => $i + 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        // Second scale: 3-point mid-year check-in scale
        $scale2Id = DB::table('pa_rating_scales')->insertGetId([
            'name'        => '3-Point Check-in Scale',
            'description' => 'Simplified 3-point scale for mid-year check-ins.',
            'min_score'   => 1,
            'max_score'   => 3,
            'is_default'  => false,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        foreach ([[1,'On Track','#66bb6a'],[2,'At Risk','#ff7043'],[3,'Off Track','#ef5350']] as $i => [$val, $label, $color]) {
            DB::table('pa_rating_values')->insert([
                'rating_scale_id' => $scale2Id,
                'label'           => $label,
                'value'           => $val,
                'description'     => $label . ' — mid-year check-in rating.',
                'color'           => $color,
                'sort_order'      => $i + 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        $this->command->info('  ✓ Rating scales seeded');
    }

    // ── 2. Settings ─────────────────────────────────────────────────────────────

    private function seedSettings(): void
    {
        $settings = [
            ['self_review_enabled',         'true'],
            ['peer_review_enabled',          'true'],
            ['manager_review_required',      'true'],
            ['360_review_enabled',           'true'],
            ['probation_period_days',        '90'],
            ['kpi_target_completion_rate',   '80'],
            ['wellness_alert_threshold',     '60'],
            ['ai_insights_enabled',          'true'],
            ['calibration_required',         'false'],
            ['default_rating_scale',         '1'],
            ['pip_warning_days',             '30'],
            ['max_peers_per_review',         '5'],
        ];

        foreach ($settings as [$key, $value]) {
            DB::table('pa_settings')->updateOrInsert(['key' => $key], [
                'value'      => $value,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        $this->command->info('  ✓ Settings seeded');
    }

    // ── 3. Skills ───────────────────────────────────────────────────────────────

    private function seedSkills(): void
    {
        $skills = [
            // Technical
            ['Data Analysis',           'technical',      'Ability to interpret and derive insights from data.'],
            ['Project Management',      'technical',      'Planning, executing and closing projects on time and budget.'],
            ['Software Development',    'technical',      'Writing, testing and maintaining software code.'],
            ['Financial Reporting',     'technical',      'Preparing and interpreting financial statements.'],
            ['Process Improvement',     'technical',      'Identifying and implementing process optimisations.'],
            ['Risk Management',         'technical',      'Identifying, assessing and mitigating organisational risk.'],
            // Leadership
            ['Team Leadership',         'leadership',     'Guiding, motivating and developing a team.'],
            ['Strategic Thinking',      'leadership',     'Developing long-term plans aligned with organisational goals.'],
            ['Decision Making',         'leadership',     'Making timely, sound decisions under uncertainty.'],
            ['Change Management',       'leadership',     'Leading teams through organisational change effectively.'],
            // Interpersonal
            ['Communication',           'interpersonal',  'Clearly conveying information verbally and in writing.'],
            ['Conflict Resolution',     'interpersonal',  'Resolving disputes constructively and professionally.'],
            ['Stakeholder Management',  'interpersonal',  'Building and maintaining productive stakeholder relationships.'],
            ['Negotiation',             'interpersonal',  'Reaching mutually beneficial agreements.'],
            // Behavioural
            ['Accountability',          'behavioural',    'Taking ownership of outcomes and responsibilities.'],
            ['Adaptability',            'behavioural',    'Adjusting effectively to new conditions and priorities.'],
            ['Innovation',              'behavioural',    'Generating creative solutions to problems.'],
            ['Time Management',         'behavioural',    'Prioritising and completing tasks within required timeframes.'],
        ];

        foreach ($skills as $i => [$name, $category, $desc]) {
            DB::table('pa_skills')->insertOrIgnore([
                'name'        => $name,
                'category'    => $category,
                'description' => $desc,
                'is_active'   => true,
                'sort_order'  => $i + 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        $this->command->info('  ✓ Skills seeded');
    }

    // ── 4. Templates ────────────────────────────────────────────────────────────

    private function seedTemplates(): void
    {
        $tplId = DB::table('pa_templates')->insertGetId([
            'name'        => 'Annual Performance Review 2025',
            'description' => 'Comprehensive annual performance appraisal template covering KPIs, competencies and development.',
            'is_default'  => true,
            'is_active'   => true,
            'created_by'  => $this->adminId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $sections = [
            ['Goal Achievement',       'Assessment of key objectives and targets achieved during the review period.', 40, 1],
            ['Core Competencies',      'Evaluation of role-specific competencies and behaviours.',                    30, 2],
            ['Leadership & Teamwork',  'Assessment of leadership behaviours and contribution to team success.',       15, 3],
            ['Development & Growth',   'Progress on personal development goals and new skills acquired.',             15, 4],
        ];

        foreach ($sections as [$name, $desc, $weight, $sort]) {
            $sectionId = DB::table('pa_template_sections')->insertGetId([
                'template_id' => $tplId,
                'name'        => $name,
                'description' => $desc,
                'weight'      => $weight,
                'sort_order'  => $sort,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $questions = match ($sort) {
                1 => [
                    ['Did the employee achieve their agreed KPIs and targets?', 'rating', 40],
                    ['How effectively were goals communicated and tracked?',     'text',   0],
                    ['Rate the overall quality of outputs delivered.',           'rating', 60],
                ],
                2 => [
                    ['Rate the employee\'s problem-solving ability.',    'rating', 30],
                    ['Rate the employee\'s communication effectiveness.','rating', 30],
                    ['Rate the employee\'s adherence to company values.','rating', 40],
                ],
                3 => [
                    ['Rate the employee\'s ability to lead and influence others.', 'rating', 50],
                    ['Rate the employee\'s contribution to team morale.',          'rating', 30],
                    ['Describe a situation where this employee showed leadership.','text',   0],
                    ['Rate this employee\'s mentoring of junior staff.',           'rating', 20],
                ],
                default => [
                    ['Has the employee completed their planned development activities?','rating', 50],
                    ['Rate progress on individual development goals.',                 'rating', 50],
                    ['What development would you recommend for next year?',            'text',   0],
                ],
            };

            foreach ($questions as $j => [$question, $type, $weight2]) {
                DB::table('pa_template_questions')->insert([
                    'section_id'    => $sectionId,
                    'question'      => $question,
                    'description'   => null,
                    'question_type' => $type,
                    'options'       => null,
                    'is_required'   => $type === 'rating',
                    'weight'        => $weight2,
                    'sort_order'    => $j + 1,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }

        // Mid-year template
        $tpl2Id = DB::table('pa_templates')->insertGetId([
            'name'        => 'Mid-Year Check-in 2025',
            'description' => 'Lightweight mid-year review focused on progress and adjustments.',
            'is_default'  => false,
            'is_active'   => true,
            'created_by'  => $this->adminId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $midSectionId = DB::table('pa_template_sections')->insertGetId([
            'template_id' => $tpl2Id,
            'name'        => 'Progress Review',
            'description' => 'Mid-year progress check against annual goals.',
            'weight'      => 100,
            'sort_order'  => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        foreach (['How is progress toward annual goals?', 'What support does the employee need?', 'Rate overall mid-year performance.'] as $i => $q) {
            DB::table('pa_template_questions')->insert([
                'section_id'    => $midSectionId,
                'question'      => $q,
                'description'   => null,
                'question_type' => $i === 2 ? 'rating' : 'text',
                'options'       => null,
                'is_required'   => true,
                'weight'        => $i === 2 ? 100 : 0,
                'sort_order'    => $i + 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        $this->command->info('  ✓ Templates seeded');
    }

    // ── 5. Cycles ───────────────────────────────────────────────────────────────

    private function seedCycles(): void
    {
        $scaleId    = DB::table('pa_rating_scales')->value('id');
        $tplId      = DB::table('pa_templates')->where('is_default', true)->value('id');
        $midTplId   = DB::table('pa_templates')->where('is_default', false)->value('id');

        // Completed past cycle
        DB::table('pa_cycles')->insertGetId([
            'name'                    => 'Annual Performance Cycle 2024',
            'description'             => 'Full-year performance appraisal cycle for FY2024.',
            'cycle_type'              => 'annual',
            'start_date'              => '2024-01-01',
            'end_date'                => '2024-12-31',
            'self_review_deadline'    => '2025-01-15',
            'manager_review_deadline' => '2025-01-31',
            'status'                  => 'closed',
            'rating_scale_id'         => $scaleId,
            'template_id'             => $tplId,
            'allow_self_review'       => true,
            'allow_peer_review'       => true,
            'require_manager_approval'=> true,
            'created_by'              => $this->adminId,
            'created_at'              => '2024-01-01',
            'updated_at'              => now(),
        ]);

        // Current active cycle
        DB::table('pa_cycles')->insertGetId([
            'name'                    => 'Annual Performance Cycle 2026',
            'description'             => 'Full-year performance appraisal cycle for FY2026.',
            'cycle_type'              => 'annual',
            'start_date'              => '2026-01-01',
            'end_date'                => '2026-12-31',
            'self_review_deadline'    => '2027-01-15',
            'manager_review_deadline' => '2027-01-31',
            'status'                  => 'active',
            'rating_scale_id'         => $scaleId,
            'template_id'             => $tplId,
            'allow_self_review'       => true,
            'allow_peer_review'       => true,
            'require_manager_approval'=> true,
            'created_by'              => $this->adminId,
            'created_at'              => '2026-01-01',
            'updated_at'              => now(),
        ]);

        // Mid-year cycle
        DB::table('pa_cycles')->insertGetId([
            'name'                    => 'Mid-Year Check-in 2026',
            'description'             => 'Mid-year progress review for H1 2026.',
            'cycle_type'              => 'semi_annual',
            'start_date'              => '2026-01-01',
            'end_date'                => '2026-06-30',
            'self_review_deadline'    => '2026-07-10',
            'manager_review_deadline' => '2026-07-20',
            'status'                  => 'active',
            'rating_scale_id'         => $scaleId,
            'template_id'             => $midTplId,
            'allow_self_review'       => true,
            'allow_peer_review'       => false,
            'require_manager_approval'=> false,
            'created_by'              => $this->adminId,
            'created_at'              => '2026-01-01',
            'updated_at'              => now(),
        ]);

        $this->command->info('  ✓ Cycles seeded');
    }

    // ── 6. Goals ────────────────────────────────────────────────────────────────

    private function seedGoals(): void
    {
        $cycleId    = DB::table('pa_cycles')->where('status', 'active')->value('id');
        $oldCycleId = DB::table('pa_cycles')->where('status', 'closed')->where('cycle_type', 'annual')->value('id');
        $deptIds    = $this->deptIds ?: [1];

        $orgGoals = [
            ['Increase Revenue by 20%',              'Grow company revenue to R50M by end of FY2025.',                                    'organizational', 20,  'R million',  15],
            ['Achieve 95% Customer Satisfaction',    'Maintain CSAT score of 95% across all service touchpoints.',                        'organizational', 95,  '%',          80],
            ['Reduce Operational Costs by 15%',      'Implement process improvements to cut operational expenditure by 15%.',             'organizational', 15,  '%',           8],
            ['Digital Transformation Milestone',     'Complete migration of 3 legacy systems to cloud-based solutions.',                  'department',   3,   'systems',     2],
            ['Employee Retention Rate 90%+',         'Maintain voluntary turnover below 10% through engagement and development.',         'organizational', 90,  '%',          88],
            ['Launch 2 New Product Lines',           'Develop and launch 2 new product lines targeting the SME market.',                 'department',   2,   'products',    1],
            ['ISO 9001 Certification',               'Achieve ISO 9001 quality management certification by Q3 2025.',                     'department',   1,   'certifications', 0],
            ['Training Hours — 40hrs per Employee',  'Ensure every employee completes minimum 40 hours of training in 2025.',            'team',         40,  'hours',       28],
        ];

        foreach ($orgGoals as [$title, $desc, $level, $target, $unit, $current]) {
            DB::table('pa_goals')->insert([
                'cycle_id'      => $cycleId,
                'title'         => $title,
                'description'   => $desc,
                'level'         => $level,
                'owner_id'      => $this->managers[array_rand($this->managers)],
                'owner_unit_id' => !empty($deptIds) ? $deptIds[array_rand($deptIds)] : null,
                'start_date'    => '2026-01-01',
                'end_date'      => '2026-12-31',
                'status'        => $current >= $target * 0.8 ? 'achieved' : ($current > 0 ? 'active' : 'draft'),
                'target_value'  => $target,
                'unit'          => $unit,
                'current_value' => $current,
                'created_by'    => $this->adminId,
                'created_at'    => '2026-01-15',
                'updated_at'    => now(),
            ]);
        }

        // 2024 closed goals
        $oldGoals = [
            ['Increase Revenue by 18%',         'Grow revenue by 18% vs 2023.',               'organizational', 18,  '%',       20],
            ['Reduce Support Tickets by 30%',   'Cut support ticket volume through self-service.',  'department', 30, '%',    32],
        ];

        foreach ($oldGoals as [$title, $desc, $level, $target, $unit, $current]) {
            DB::table('pa_goals')->insert([
                'cycle_id'      => $oldCycleId,
                'title'         => $title,
                'description'   => $desc,
                'level'         => $level,
                'owner_id'      => $this->managers[0],
                'owner_unit_id' => !empty($deptIds) ? $deptIds[0] : null,
                'start_date'    => '2024-01-01',
                'end_date'      => '2024-12-31',
                'status'        => 'achieved',
                'target_value'  => $target,
                'unit'          => $unit,
                'current_value' => $current,
                'created_by'    => $this->adminId,
                'created_at'    => '2024-01-15',
                'updated_at'    => '2025-01-10',
            ]);
        }

        $this->command->info('  ✓ Goals seeded');
    }

    // ── 7. KPIs ─────────────────────────────────────────────────────────────────

    private function seedKpis(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');
        $goalId  = DB::table('pa_goals')->where('cycle_id', $cycleId)->value('id');

        $kpiTemplates = [
            ['Achieve Sales Revenue Target',          'Achieve individual sales target of R2.5M for the year.',        25, 80,  100, 4.2],
            ['Customer Satisfaction Score (CSAT)',    'Maintain personal CSAT above 90% across all interactions.',      20, 75,  100, 3.8],
            ['Project Delivery On-Time Rate',         'Deliver 95% of assigned projects within agreed timelines.',      20, 60,  95,  3.5],
            ['Complete 40 Training Hours',            'Attend and complete minimum 40 hours of approved training.',     15, 32,  40,  4.0],
            ['Report Submission Accuracy',            'Submit all monthly reports with less than 2% error rate.',       10, 95,  98,  4.5],
            ['Reduction in Processing Errors',        'Reduce data processing errors by 25% vs prior year.',           10, 18,  25,  3.2],
        ];

        foreach ($this->employees as $empIdx => $empId) {
            foreach ($kpiTemplates as $i => [$title, $desc, $weight, $current, $target]) {
                // Vary values slightly per employee
                $variance  = (($empIdx + $i) % 3 - 1) * 5;
                $curVal    = max(0, $current + $variance);
                $achieved  = $curVal / $target;
                $scorePct  = min(100, round($achieved * 100, 1));
                $isScored  = $empIdx < count($this->employees) * 0.7; // 70% have scores

                $kpiId = DB::table('pa_employee_kpis')->insertGetId([
                    'cycle_id'             => $cycleId,
                    'employee_id'          => $empId,
                    'goal_id'              => $i === 0 ? $goalId : null,
                    'title'                => $title,
                    'description'          => $desc,
                    'target_value'         => $target,
                    'unit'                 => match(true) { str_contains($title, 'Revenue') => 'R million', str_contains($title, 'Hours') => 'hours', default => '%' },
                    'weighting'            => $weight,
                    'self_update_allowed'  => true,
                    'manager_update_allowed' => true,
                    'status'               => $isScored ? 'completed' : 'in_progress',
                    'created_by'           => $this->managers[$empIdx % count($this->managers)],
                    'created_at'           => '2026-01-20',
                    'updated_at'           => now(),
                ]);

                // Progress logs — monthly Jan–May 2026
                for ($month = 1; $month <= 5; $month++) {
                    $logVal = round($curVal * ($month / 5), 1);
                    DB::table('pa_kpi_progress_logs')->insert([
                        'employee_kpi_id' => $kpiId,
                        'value'           => $logVal,
                        'notes'           => "Monthly progress update for " . Carbon::create(2026, $month)->format('F Y') . ".",
                        'updated_by'      => $empId,
                        'log_date'        => Carbon::create(2026, $month, 28),
                        'created_at'      => Carbon::create(2026, $month, 28),
                        'updated_at'      => Carbon::create(2026, $month, 28),
                    ]);
                }

                // Score for scored KPIs
                if ($isScored) {
                    DB::table('pa_kpi_scores')->insertOrIgnore([
                        'employee_kpi_id' => $kpiId,
                        'achieved_value'  => $curVal,
                        'score_pct'       => $scorePct,
                        'weighted_score'  => round($scorePct * $weight / 100, 2),
                        'notes'           => 'Scored based on actual achievement vs target.',
                        'scored_by'       => $this->managers[$empIdx % count($this->managers)],
                        'scored_at'       => '2026-05-01',
                        'created_at'      => '2026-05-01',
                        'updated_at'      => '2026-05-01',
                    ]);
                }
            }

            // AI Insight per employee
            $insights = [
                ['kpi_at_risk',              'warning', 'KPI At Risk',                'Customer Satisfaction KPI is trending below target. Recommend coaching intervention.'],
                ['exceptional_performance',  'info',    'High Performer Signal',      'Employee consistently exceeds targets across all KPI categories. Consider for succession.'],
                ['trend_analysis',           'info',    'Training Completion Lag',    'Employee has not completed required training hours. Ensure enrolment in planned programmes.'],
            ];
            $insight = $insights[$empIdx % count($insights)];
            DB::table('pa_ai_insights')->insert([
                'employee_id'  => $empId,
                'cycle_id'     => $cycleId,
                'insight_type' => $insight[0],
                'severity'     => $insight[1],
                'title'        => $insight[2],
                'body'         => $insight[3],
                'data'         => json_encode(['source' => 'kpi_engine', 'confidence' => rand(70, 95) / 100]),
                'created_at'   => '2026-05-15',
                'updated_at'   => '2026-05-15',
            ]);

            // Performance snapshots — one per month Jan–May 2026 for trend chart
            // overall_score is on 1–5 scale (matching analytics high_performers >= 4 threshold)
            $baseScore = round(2.5 + ($empIdx % 5) * 0.4, 1); // 2.5 to 4.1
            for ($month = 1; $month <= 5; $month++) {
                $monthScore = min(5.0, round($baseScore + ($month * 0.08), 2)); // gradual improvement
                $snapshotDate = Carbon::create(2026, $month, 1)->format('Y-m-d');
                DB::table('pa_performance_snapshots')->insertOrIgnore([
                    'employee_id'          => $empId,
                    'cycle_id'             => $cycleId,
                    'snapshot_date'        => $snapshotDate,
                    'overall_score'        => $monthScore,
                    'kpi_completion_rate'  => round(55 + ($empIdx % 6) * 7 + $month, 1),
                    'data'                 => json_encode(['kpis' => 6, 'scored' => min(6, $month + 1), 'avg_score' => $monthScore]),
                    'created_at'           => $snapshotDate,
                    'updated_at'           => $snapshotDate,
                ]);
            }
        }

        $this->command->info('  ✓ KPIs, progress logs, scores, AI insights and snapshots seeded');
    }

    // ── 8. Feedback ─────────────────────────────────────────────────────────────

    private function seedFeedback(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');
        $types   = ['manager', 'peer', 'self', 'upward'];
        $contents = [
            'Demonstrates strong analytical skills and consistently delivers accurate reports. Areas for growth include stakeholder communication and proactive escalation.',
            'A reliable team member who supports colleagues without being asked. Would benefit from building confidence in presenting to senior leadership.',
            'Shows initiative and creativity in solving problems. Recommend exposure to cross-functional projects to broaden business understanding.',
            'Technically strong with a growth mindset. Needs to work on time management — some deadlines were missed without prior escalation.',
            'Excellent at managing relationships with clients. Internal collaboration could improve. Recommend participating in team-building initiatives.',
            'High-potential employee who is ready for greater responsibility. Consider for a stretch assignment or acting role in the next quarter.',
        ];

        foreach ($this->employees as $i => $empId) {
            $giverId = $this->managers[$i % count($this->managers)];
            DB::table('pa_feedback')->insert([
                'cycle_id'        => $cycleId,
                'employee_id'     => $empId,
                'given_by'        => $giverId,
                'feedback_type'   => $types[$i % count($types)],
                'content'         => $contents[$i % count($contents)],
                'rating'          => rand(3, 5),
                'is_anonymous'    => $types[$i % count($types)] === 'peer',
                'is_draft'        => false,
                'acknowledged_at' => $i % 3 === 0 ? now()->subDays(rand(1, 30)) : null,
                'created_at'      => now()->subDays(rand(10, 60)),
                'updated_at'      => now()->subDays(rand(1, 9)),
            ]);

            // Self-feedback for half the employees
            if ($i % 2 === 0) {
                DB::table('pa_feedback')->insert([
                    'cycle_id'        => $cycleId,
                    'employee_id'     => $empId,
                    'given_by'        => $empId,
                    'feedback_type'   => 'self',
                    'content'         => 'I believe I have met most of my KPIs this year. I want to improve on delegation and stakeholder management in the next cycle.',
                    'rating'          => rand(3, 4),
                    'is_anonymous'    => false,
                    'is_draft'        => false,
                    'acknowledged_at' => null,
                    'created_at'      => now()->subDays(rand(5, 20)),
                    'updated_at'      => now()->subDays(rand(1, 4)),
                ]);
            }
        }

        $this->command->info('  ✓ Feedback seeded');
    }

    // ── 9. Check-ins ────────────────────────────────────────────────────────────

    private function seedCheckins(): void
    {
        $cycleId  = DB::table('pa_cycles')->where('status', 'active')->value('id');
        $statuses = ['completed', 'completed', 'completed', 'scheduled', 'cancelled'];
        $agendas  = [
            'Review Q1 KPI progress and identify blockers. Discuss development plan activities.',
            'Mid-year performance discussion. Adjust KPI targets where needed. Career conversation.',
            'Q3 progress review and preparation for year-end appraisal. 360 feedback discussion.',
            'Annual performance wrap-up. Discuss ratings, development for next year.',
        ];

        foreach ($this->employees as $i => $empId) {
            $managerId = $this->managers[$i % count($this->managers)];
            for ($q = 0; $q < 3; $q++) {
                $scheduledAt = Carbon::create(2026, ($q + 1) * 3, 15, 10, 0);
                $completed   = $statuses[$q % count($statuses)] === 'completed';
                DB::table('pa_checkins')->insert([
                    'employee_id'      => $empId,
                    'manager_id'       => $managerId,
                    'cycle_id'         => $cycleId,
                    'title'            => 'Q' . ($q + 1) . ' Performance Check-in',
                    'scheduled_at'     => $scheduledAt,
                    'completed_at'     => $completed ? $scheduledAt->copy()->addHours(1) : null,
                    'duration_minutes' => 60,
                    'location'         => ['Boardroom A', 'MS Teams', 'Manager\'s Office'][$q % 3],
                    'agenda'           => $agendas[$q % count($agendas)],
                    'notes'            => $completed ? 'Productive session. Employee is on track for most KPIs. Agreed on coaching plan for CSAT improvement.' : null,
                    'action_items'     => json_encode([
                        ['task' => 'Complete training enrolment by end of month', 'owner' => 'Employee', 'due' => $scheduledAt->copy()->addDays(14)->format('Y-m-d')],
                        ['task' => 'Update KPI progress log weekly',              'owner' => 'Employee', 'due' => $scheduledAt->copy()->addDays(7)->format('Y-m-d')],
                    ]),
                    'status'     => $completed ? 'completed' : 'scheduled',
                    'created_by' => $managerId,
                    'created_at' => $scheduledAt->copy()->subDays(7),
                    'updated_at' => $completed ? $scheduledAt->copy()->addHours(1) : $scheduledAt->copy()->subDays(7),
                ]);
            }
        }

        $this->command->info('  ✓ Check-ins seeded');
    }

    // ── 10. Coaching Sessions ───────────────────────────────────────────────────

    private function seedCoachingSessions(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');
        $objectives = [
            'Improve stakeholder communication and executive presence.',
            'Develop strategic thinking and ability to influence without authority.',
            'Build confidence in presenting complex data to non-technical audiences.',
        ];

        foreach (array_slice($this->employees, 0, 8) as $i => $empId) {
            $coachId = $this->managers[$i % count($this->managers)];
            DB::table('pa_coaching_sessions')->insert([
                'employee_id'      => $empId,
                'coach_id'         => $coachId,
                'cycle_id'         => $cycleId,
                'title'            => 'Leadership Coaching Session ' . ($i + 1),
                'session_date'     => now()->subDays(rand(10, 90)),
                'duration_minutes' => [60, 90, 45][$i % 3],
                'objectives'       => $objectives[$i % count($objectives)],
                'outcomes'         => 'Employee demonstrated improved self-awareness and has committed to two specific behavioural changes.',
                'next_steps'       => 'Schedule follow-up in 4 weeks. Employee to practise active listening techniques in next 3 team meetings.',
                'status'           => ['completed', 'completed', 'scheduled'][$i % 3],
                'created_by'       => $coachId,
                'created_at'       => now()->subDays(rand(95, 100)),
                'updated_at'       => now()->subDays(rand(1, 9)),
            ]);
        }

        $this->command->info('  ✓ Coaching sessions seeded');
    }

    // ── 11. 360 Reviews ─────────────────────────────────────────────────────────

    private function seed360Reviews(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');

        // 360 questions
        $questions360 = [
            ['communication',  'rating',   'How effectively does this person communicate with the team?'],
            ['communication',  'text',     'Describe a time this person communicated especially well or poorly.'],
            ['leadership',     'rating',   'How well does this person inspire and motivate others?'],
            ['leadership',     'rating',   'How effectively does this person give constructive feedback?'],
            ['teamwork',       'rating',   'How well does this person collaborate and support team members?'],
            ['teamwork',       'text',     'Describe this person\'s contribution to team culture.'],
            ['technical',      'rating',   'How would you rate this person\'s technical knowledge and expertise?'],
            ['culture',        'rating',   'How well does this person demonstrate company values?'],
        ];

        foreach ($questions360 as $i => [$category, $type, $question]) {
            DB::table('pa_360_questions')->insertOrIgnore([
                'question'      => $question,
                'category'      => $category,
                'question_type' => $type,
                'is_active'     => true,
                'sort_order'    => $i + 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        $questionIds = DB::table('pa_360_questions')->pluck('id')->toArray();

        // 360 reviews for first 6 employees
        foreach (array_slice($this->employees, 0, 6) as $i => $empId) {
            $reviewId = DB::table('pa_360_reviews')->insertGetId([
                'employee_id'       => $empId,
                'cycle_id'          => $cycleId,
                'title'             => '360° Development Review — 2026',
                'instructions'      => 'Please provide honest, constructive feedback. Ratings are on a 1–5 scale.',
                'due_date'          => '2026-08-31',
                'require_anonymity' => true,
                'min_raters'        => 3,
                'max_raters'        => 6,
                'status'            => ['collecting', 'reported', 'draft'][$i % 3],
                'question_ids'      => json_encode($questionIds),
                'ai_summary'        => $i % 2 === 0
                    ? 'AI analysis indicates strong communication and teamwork scores. Leadership scores suggest opportunity for development.'
                    : null,
                'created_by'        => $this->managers[$i % count($this->managers)],
                'created_at'        => '2026-04-01',
                'updated_at'        => now(),
            ]);

            // Raters (3 per review)
            $raters = array_values(array_filter(
                array_slice($this->employees, 0, 8),
                fn($id) => $id !== $empId
            ));
            $relationships = ['peer', 'manager', 'direct_report', 'peer'];

            foreach (array_slice($raters, 0, 3) as $j => $raterUserId) {
                $submitted = $j < 2;
                $raterRowId = DB::table('pa_360_raters')->insertGetId([
                    'review_id'             => $reviewId,
                    'rater_id'              => $raterUserId,
                    'relationship'          => $relationships[$j % count($relationships)],
                    'status'                => $submitted ? 'submitted' : 'pending',
                    'nominated_by_employee' => $j % 2 === 0,
                    'invited_at'            => '2026-04-05',
                    'submitted_at'          => $submitted ? '2026-04-25' : null,
                    'created_at'            => '2026-04-05',
                    'updated_at'            => now(),
                ]);

                if ($submitted) {
                    foreach ($questionIds as $k => $qId) {
                        $q = DB::table('pa_360_questions')->find($qId);
                        DB::table('pa_360_responses')->insertOrIgnore([
                            'review_id'         => $reviewId,
                            'rater_id'          => $raterRowId,
                            'question_id'       => $qId,
                            'question_snapshot' => $q?->question,
                            'category'          => $q?->category,
                            'rating_value'      => $q?->question_type === 'rating' ? rand(3, 5) : null,
                            'text_value'        => $q?->question_type === 'text'   ? 'This colleague demonstrates strong collaboration skills and consistently delivers quality work.' : null,
                            'sort_order'        => $k + 1,
                            'created_at'        => '2026-04-25',
                            'updated_at'        => '2026-04-25',
                        ]);
                    }
                }
            }
        }

        $this->command->info('  ✓ 360 reviews, raters and responses seeded');
    }

    // ── 12. Probation ───────────────────────────────────────────────────────────

    private function seedProbation(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');

        $probationTypes    = ['initial', 'extended', 'initial'];
        $statuses          = ['in_progress', 'completed', 'at_risk'];
        $recommendations   = ['pass', 'pass', 'extend'];
        $riskScores        = [25, 15, 72];
        $riskLevels        = ['low', 'low', 'high'];
        $successProbs      = [85, 92, 38];

        foreach (array_slice($this->employees, 0, 6) as $i => $empId) {
            $managerId = $this->managers[$i % count($this->managers)];
            $startDate = now()->subDays(rand(60, 180));
            $endDate   = $startDate->copy()->addDays(90);

            $reviewId = DB::table('pa_probation_reviews')->insertGetId([
                'employee_id'        => $empId,
                'manager_id'         => $managerId,
                'cycle_id'           => $cycleId,
                'probation_type'     => $probationTypes[$i % count($probationTypes)],
                'start_date'         => $startDate->format('Y-m-d'),
                'end_date'           => $endDate->format('Y-m-d'),
                'status'             => $statuses[$i % count($statuses)],
                'recommendation'     => $recommendations[$i % count($recommendations)],
                'risk_score'         => $riskScores[$i % count($riskScores)],
                'risk_level'         => $riskLevels[$i % count($riskLevels)],
                'success_probability'=> $successProbs[$i % count($successProbs)],
                'ai_prediction'      => 'Based on current performance signals, this employee is likely to ' . ($successProbs[$i % count($successProbs)] > 60 ? 'successfully complete' : 'struggle with') . ' probation requirements.',
                'ai_summary'         => null,
                'notes'              => 'Standard probation review initiated on joining date.',
                'created_by'         => $managerId,
                'created_at'         => $startDate,
                'updated_at'         => now(),
            ]);

            // Probation objectives
            $objectives = [
                ['Complete onboarding programme',    'Attend and pass all onboarding modules.',        30, 100, 100, '%'],
                ['Achieve 80% KPI target',           'Deliver at least 80% of agreed KPIs.',           40, 80,  ($successProbs[$i % count($successProbs)] > 60 ? 82 : 55), '%'],
                ['Build team relationships',         'Establish positive working relationships.',       15, 1,   1,   'milestone'],
                ['Submit 3 process improvement ideas','Contribute 3 documented process improvements.', 15, 3,   ($i % 2), 'ideas'],
            ];

            foreach ($objectives as [$title, $desc, $weight, $target, $achieved, $unit]) {
                DB::table('pa_probation_objectives')->insert([
                    'review_id'      => $reviewId,
                    'title'          => $title,
                    'description'    => $desc,
                    'weight'         => $weight,
                    'target_value'   => $target,
                    'achieved_value' => $achieved,
                    'unit'           => $unit,
                    'status'         => $achieved >= $target ? 'met' : 'in_progress',
                    'manager_notes'  => $achieved >= $target ? 'Objective fully met.' : 'Needs further progress.',
                    'created_at'     => $startDate,
                    'updated_at'     => now(),
                ]);
            }

            // Probation checkins
            foreach ([30, 60] as $daysIn) {
                $checkinDate = $startDate->copy()->addDays($daysIn);
                DB::table('pa_probation_checkins')->insert([
                    'review_id'    => $reviewId,
                    'checkin_type' => "{$daysIn}-day review",
                    'scheduled_at' => $checkinDate,
                    'completed_at' => $checkinDate->copy()->addHours(1),
                    'agenda'       => "Review probation objectives progress at day {$daysIn}.",
                    'notes'        => 'Employee is progressing well. Minor concerns around documentation quality.',
                    'action_items' => json_encode([['task' => 'Improve documentation quality', 'due' => $checkinDate->copy()->addDays(14)->format('Y-m-d')]]),
                    'status'       => 'completed',
                    'created_by'   => $managerId,
                    'created_at'   => $checkinDate->copy()->subDays(3),
                    'updated_at'   => $checkinDate->copy()->addHours(1),
                ]);
            }

            // Risk score history
            DB::table('pa_probation_risk_scores')->insert([
                'review_id'          => $reviewId,
                'risk_score'         => $riskScores[$i % count($riskScores)],
                'risk_level'         => $riskLevels[$i % count($riskLevels)],
                'success_probability'=> $successProbs[$i % count($successProbs)],
                'recommendation'     => $recommendations[$i % count($recommendations)],
                'factors'            => json_encode([
                    'kpi_completion'   => $successProbs[$i % count($successProbs)] > 60 ? 'strong' : 'weak',
                    'attendance'       => 'good',
                    'onboarding'       => 'completed',
                    'manager_feedback' => 'positive',
                ]),
                'is_ai_generated' => true,
                'scored_by'       => $managerId,
                'created_at'      => now()->subDays(14),
                'updated_at'      => now()->subDays(14),
            ]);
        }

        $this->command->info('  ✓ Probation reviews, objectives and checkins seeded');
    }

    // ── 13. Wellness ────────────────────────────────────────────────────────────

    private function seedWellness(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');
        $grades  = ['A', 'B', 'B', 'C', 'A', 'B', 'C', 'D'];
        $signalTypes = ['frequent_late_logins', 'missed_checkins', 'kpi_decline', 'positive_feedback_spike'];

        foreach ($this->employees as $i => $empId) {
            $score = rand(45, 95);
            DB::table('pa_wellness_scores')->insertOrIgnore([
                'employee_id'        => $empId,
                'cycle_id'           => $cycleId,
                'wellness_score'     => $score,
                'wellness_grade'     => $grades[$i % count($grades)],
                'kpi_score'          => round($score + rand(-10, 10), 1),
                'feedback_score'     => round($score + rand(-8, 12), 1),
                'checkin_score'      => round($score + rand(-5, 15), 1),
                'engagement_score'   => round($score + rand(-12, 8), 1),
                'burnout_risk'       => $score < 60 ? 'high' : ($score < 75 ? 'medium' : 'low'),
                'disengagement_level'=> $score < 55 ? 'high' : ($score < 70 ? 'medium' : 'low'),
                'signals'            => json_encode([
                    'kpi_trend'      => $score > 70 ? 'improving' : 'declining',
                    'feedback_count' => rand(2, 8),
                    'checkins_done'  => rand(1, 4),
                ]),
                'is_ai_generated' => true,
                'generated_by'    => $this->adminId,
                'created_at'      => '2026-05-01',
                'updated_at'      => '2026-05-01',
            ]);

            // Behavior signal for at-risk employees
            if ($score < 65) {
                DB::table('pa_behavior_signals')->insert([
                    'employee_id'  => $empId,
                    'cycle_id'     => $cycleId,
                    'signal_type'  => $signalTypes[$i % count($signalTypes)],
                    'severity'     => $score < 55 ? 'high' : 'medium',
                    'title'        => 'Potential Disengagement Signal Detected',
                    'description'  => 'System detected a pattern indicating reduced engagement. Manager follow-up recommended.',
                    'data'         => json_encode(['score' => $score, 'threshold' => 65, 'weeks' => 4]),
                    'created_at'   => now()->subDays(rand(5, 20)),
                    'updated_at'   => now()->subDays(rand(1, 4)),
                ]);
            }
        }

        $this->command->info('  ✓ Wellness scores and behavior signals seeded');
    }

    // ── 14. Development Plans ───────────────────────────────────────────────────

    private function seedDevelopmentPlans(): void
    {
        $cycleId  = DB::table('pa_cycles')->where('status', 'active')->value('id');
        $skillIds = DB::table('pa_skills')->pluck('id')->toArray();

        $careerGoals = [
            'Advance to Senior Manager within 2 years through leadership development and cross-functional exposure.',
            'Transition into a Data Science role by acquiring Python and ML skills over 18 months.',
            'Build subject matter expertise in regulatory compliance to support the business development function.',
            'Develop into a project management specialist with PMP certification.',
        ];

        $activities = [
            ['Enrol in Leadership Excellence Programme',  'training',  '2026-09-30', 'Leadership Academy',   15000, 'completed'],
            ['Complete Advanced Excel & Power BI course', 'training',  '2026-07-31', 'Online Academy',        3500, 'in_progress'],
            ['Shadow CFO for quarterly reporting cycle',  'on_the_job','2026-06-30', null,                       0, 'planned'],
            ['Read: The First 90 Days by M. Watkins',     'reading',   '2026-05-31', null,                     350, 'completed'],
            ['Attend HR Tech Conference 2026',            'conference','2026-10-15', 'SARA Conference',       8500, 'planned'],
            ['Complete Project Management Fundamentals',  'training',  '2026-08-31', 'PMI Partner',           5500, 'in_progress'],
        ];

        foreach ($this->employees as $i => $empId) {
            $managerId = $this->managers[$i % count($this->managers)];

            $planId = DB::table('pa_development_plans')->insertGetId([
                'employee_id'          => $empId,
                'manager_id'           => $managerId,
                'cycle_id'             => $cycleId,
                'title'                => 'Individual Development Plan 2026',
                'career_goal'          => $careerGoals[$i % count($careerGoals)],
                'strengths'            => 'Strong analytical ability, excellent work ethic, good interpersonal skills with peers.',
                'development_areas'    => 'Stakeholder management, executive presence, strategic thinking and delegation.',
                'status'               => ['active', 'active', 'on_hold', 'completed'][$i % 4],
                'overall_progress'     => [25, 50, 10, 100][$i % 4],
                'ai_recommendations'   => 'Consider enrolment in the Leadership Pipeline programme. Recommend stretch assignment in cross-functional project.',
                'manager_notes'        => 'Employee is committed to development. Discuss career aspirations in next check-in.',
                'target_completion_date' => '2026-12-31',
                'created_by'           => $managerId,
                'created_at'           => '2026-02-01',
                'updated_at'           => now(),
            ]);

            // Activities (3 per plan)
            foreach (array_slice($activities, $i % 3, 3) as $j => [$title, $type, $targetDate, $provider, $cost, $status]) {
                $completed = $status === 'completed' ? $targetDate : null;
                DB::table('pa_development_activities')->insert([
                    'plan_id'       => $planId,
                    'title'         => $title,
                    'activity_type' => $type,
                    'description'   => "Development activity: {$title}",
                    'target_date'   => $targetDate,
                    'completed_date'=> $completed,
                    'status'        => $status,
                    'provider'      => $provider,
                    'cost'          => $cost,
                    'evidence_notes'=> $completed ? 'Certificate of completion attached.' : null,
                    'sort_order'    => $j + 1,
                    'created_at'    => '2026-02-01',
                    'updated_at'    => now(),
                ]);
            }

            // Employee skills (assign 3–4 skills per employee)
            $assignedSkills = array_slice($skillIds, $i % count($skillIds), 4);
            foreach ($assignedSkills as $skillId) {
                DB::table('pa_employee_skills')->insertOrIgnore([
                    'employee_id'   => $empId,
                    'skill_id'      => $skillId,
                    'current_level' => rand(2, 4),
                    'target_level'  => rand(3, 5),
                    'self_rated'    => true,
                    'verified_by'   => rand(0, 1) ? $managerId : null,
                    'verified_at'   => rand(0, 1) ? now()->subDays(rand(10, 60)) : null,
                    'evidence'      => 'Demonstrated through project delivery and peer acknowledgements.',
                    'created_at'    => '2026-02-01',
                    'updated_at'    => now(),
                ]);
            }

            // Learning recommendation
            if (!empty($skillIds)) {
                DB::table('pa_learning_recommendations')->insert([
                    'employee_id'       => $empId,
                    'development_plan_id' => $planId,
                    'cycle_id'          => $cycleId,
                    'skill_id'          => $skillIds[$i % count($skillIds)],
                    'recommendation_type'=> 'course',
                    'title'             => 'Recommended: Strategic Leadership Masterclass',
                    'description'       => 'A 3-day intensive masterclass covering strategic thinking, influencing skills and organisational dynamics.',
                    'provider'          => 'Gordon Institute of Business Science (GIBS)',
                    'estimated_hours'   => 24,
                    'priority'          => ['high', 'medium', 'low'][$i % 3],
                    'ai_generated'      => true,
                    'status'            => 'pending',
                    'created_at'        => now()->subDays(rand(10, 30)),
                    'updated_at'        => now()->subDays(rand(1, 9)),
                ]);
            }
        }

        $this->command->info('  ✓ Development plans, activities, skills and recommendations seeded');
    }

    // ── 15. Succession Planning ─────────────────────────────────────────────────

    private function seedSuccession(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');

        $positions = [
            ['Chief Financial Officer',        'Finance',              true],
            ['Head of Technology',             'Technology',           true],
            ['Head of Human Resources',        'Human Resources',      false],
            ['Regional Sales Manager',         'Sales',                false],
            ['Head of Operations',             'Operations',           true],
        ];

        foreach ($positions as $i => [$position, $dept, $isCritical]) {
            $incumbentId = $this->managers[$i % count($this->managers)];

            $planId = DB::table('pa_succession_plans')->insertGetId([
                'position_title' => $position,
                'department'     => $dept,
                'incumbent_id'   => $incumbentId,
                'cycle_id'       => $cycleId,
                'is_critical_role' => $isCritical,
                'status'         => 'active',
                'bench_strength' => [3, 2, 1][$i % 3],
                'notes'          => "Succession plan for {$position}. " . ($isCritical ? 'Critical role — requires minimum 2 ready successors.' : 'Standard role succession planning.'),
                'ai_analysis'    => "AI analysis identifies {$this->countReadyCandidates($i)} ready-now candidates. Recommend accelerated development for identified high-potentials.",
                'created_by'     => $this->adminId,
                'created_at'     => '2026-03-01',
                'updated_at'     => now(),
            ]);

            // Candidates (2–3 per plan)
            $candidates = array_slice($this->employees, ($i * 2) % max(1, count($this->employees) - 3), 3);
            $readiness  = ['ready_now', 'ready_1_2_years', 'ready_3_5_years'];

            foreach (array_slice($candidates, 0, 3) as $j => $candId) {
                if ($candId === $incumbentId) continue;
                DB::table('pa_succession_candidates')->insertOrIgnore([
                    'plan_id'             => $planId,
                    'employee_id'         => $candId,
                    'readiness_level'     => $readiness[$j % count($readiness)],
                    'potential_rating'    => [3, 2, 3][$j % 3],
                    'risk_of_loss'        => ['low', 'medium', 'high'][$j % 3],
                    'retention_priority'  => ['critical', 'high', 'medium'][$j % 3],
                    'ai_readiness_score'  => rand(55, 92),
                    'ai_recommendation'   => "Strong potential for " . $position . ". Recommend targeted leadership exposure and executive sponsorship.",
                    'notes'              => "Identified as a strong succession candidate based on performance trajectory and leadership competencies.",
                    'created_at'          => '2026-03-01',
                    'updated_at'          => now(),
                ]);
            }
        }

        // High potentials
        foreach (array_slice($this->employees, 0, min(8, count($this->employees))) as $i => $empId) {
            $composite = rand(72, 96);
            DB::table('pa_high_potentials')->insertOrIgnore([
                'employee_id'   => $empId,
                'cycle_id'      => $cycleId,
                'composite_score'=> $composite,
                'potential_band' => $composite >= 90 ? 'exceptional' : ($composite >= 80 ? 'high' : 'emerging'),
                'factors'       => json_encode([
                    'performance_score'   => rand(70, 95),
                    'leadership_score'    => rand(65, 90),
                    'learning_agility'    => rand(60, 95),
                    'mobility_willingness'=> rand(50, 100),
                ]),
                'is_ai_generated' => true,
                'identified_by'   => $this->adminId,
                'created_at'      => '2026-04-01',
                'updated_at'      => '2026-04-01',
            ]);
        }

        $this->command->info('  ✓ Succession plans, candidates and high potentials seeded');
    }

    private function countReadyCandidates(int $i): string
    {
        return ['2', '3', '1', '2', '1'][$i % 5];
    }

    // ── 16. Talent Profiles ─────────────────────────────────────────────────────

    private function seedTalentProfiles(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');

        foreach ($this->employees as $i => $empId) {
            $kpiScore  = rand(55, 95);
            $leadScore = rand(50, 90);
            $composite = round(($kpiScore * 0.5 + $leadScore * 0.3 + rand(60, 85) * 0.2), 1);
            $tiers     = ['top_talent', 'high_performer', 'solid_contributor', 'developing'];

            DB::table('pa_talent_profiles')->insertOrIgnore([
                'employee_id'                  => $empId,
                'cycle_id'                     => $cycleId,
                'promotion_readiness_score'    => rand(40, 95),
                'promotion_readiness_level'    => ['ready_now', 'ready_1_2_years', 'ready_3_5_years', 'not_ready'][$i % 4],
                'leadership_score'             => $leadScore,
                'is_leadership_pipeline'       => $leadScore >= 75,
                'talent_tier'                  => $tiers[$i % count($tiers)],
                'kpi_composite_score'          => $kpiScore,
                'skill_breadth'                => rand(3, 12),
                'skill_depth_avg'              => round(rand(25, 45) / 10, 1),
                'composite_score'              => $composite,
                'notes'                        => 'Talent profile generated from performance data and skills assessment.',
                'assessed_at'                  => '2026-05-01',
                'created_at'                   => '2026-05-01',
                'updated_at'                   => '2026-05-01',
            ]);
        }

        $this->command->info('  ✓ Talent profiles seeded');
    }

    // ── 17. Policies ────────────────────────────────────────────────────────────

    private function seedPolicies(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');

        $policies = [
            ['kpi_minimum',     'PA-POL-001', 'KPI Minimum Completion',    'kpi_compliance',    'threshold', 'Employees must have at least 3 active KPIs per cycle.',       'block',   ['min_kpis' => 3]],
            ['manager_review',  'PA-POL-002', 'Manager Review Mandatory',  'review_compliance', 'deadline',  'All manager reviews must be submitted before the deadline.',   'notify',  ['days_before' => 7]],
            ['development_plan','PA-POL-003', 'Development Plan Required', 'development',       'existence', 'All employees must have an active development plan.',          'warn',    []],
            ['feedback_cadence','PA-POL-004', 'Quarterly Feedback Policy', 'feedback',          'frequency', 'Managers must provide feedback at least quarterly.',           'warn',    ['frequency_days' => 90]],
            ['calibration_req', 'PA-POL-005', 'Calibration Participation', 'calibration',       'existence', 'All managers must participate in annual calibration session.', 'block',   []],
        ];

        foreach ($policies as [, $code, $name, $policyType, $condType, $desc, $enforcement, $condConfig]) {
            $policyId = DB::table('pa_policies')->insertGetId([
                'name'              => $name,
                'code'              => $code,
                'description'       => $desc,
                'policy_type'       => $policyType,
                'condition_type'    => $condType,
                'condition_config'  => json_encode($condConfig),
                'enforcement_level' => $enforcement,
                'is_active'         => true,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            // 1–2 violations per policy
            foreach (array_slice($this->employees, $policyId % max(1, count($this->employees) - 2), 2) as $empId) {
                $violationId = DB::table('pa_policy_violations')->insertGetId([
                    'policy_id'       => $policyId,
                    'employee_id'     => $empId,
                    'actor_id'        => $this->adminId,
                    'action_type'     => 'auto_detected',
                    'resource_type'   => 'pa_employee_kpi',
                    'resource_id'     => rand(1, 50),
                    'violation_data'  => json_encode(['message' => "Policy {$code} violation detected.", 'cycle_id' => $cycleId]),
                    'status'          => ['open', 'resolved'][$empId % 2],
                    'resolved_at'     => $empId % 2 === 1 ? now()->subDays(rand(1, 14)) : null,
                    'created_at'      => now()->subDays(rand(5, 30)),
                    'updated_at'      => now()->subDays(rand(1, 4)),
                ]);

                if ($violationId % 3 === 0) {
                    DB::table('pa_escalations')->insert([
                        'violation_id'     => $violationId,
                        'escalated_to'     => $this->managers[0],
                        'escalation_level' => 1,
                        'message'          => "Policy violation escalated for review and action.",
                        'status'           => 'open',
                        'created_at'       => now()->subDays(rand(3, 10)),
                        'updated_at'       => now()->subDays(rand(1, 2)),
                    ]);
                }
            }

            // Compliance scores
            foreach ($this->employees as $empId) {
                DB::table('pa_compliance_scores')->insertOrIgnore([
                    'employee_id'     => $empId,
                    'cycle_id'        => $cycleId,
                    'compliance_score'=> rand(60, 100),
                    'total_checks'    => 5,
                    'passed_checks'   => rand(3, 5),
                    'violation_count' => rand(0, 2),
                    'exception_count' => 0,
                    'computed_at'     => now(),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }

        $this->command->info('  ✓ Policies, violations, escalations and compliance scores seeded');
    }

    // ── 18. PIPs ────────────────────────────────────────────────────────────────

    private function seedPips(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');

        // PIPs for employees with low wellness scores (3 PIPs)
        $pipEmployees = array_slice($this->employees, 2, 3);

        $reasons = [
            'Employee has consistently failed to meet KPI targets over the past two quarters despite coaching and support.',
            'Attendance and punctuality concerns affecting team performance and project delivery.',
            'Communication and collaboration issues impacting team morale and stakeholder relationships.',
        ];

        foreach ($pipEmployees as $i => $empId) {
            $managerId = $this->managers[$i % count($this->managers)];
            $startDate = now()->subDays(rand(30, 60));

            $pipId = DB::table('pa_pip_plans')->insertGetId([
                'employee_id'                => $empId,
                'manager_id'                 => $managerId,
                'cycle_id'                   => $cycleId,
                'title'                      => 'Performance Improvement Plan — ' . now()->format('M Y'),
                'reason'                     => $reasons[$i % count($reasons)],
                'start_date'                 => $startDate->format('Y-m-d'),
                'target_end_date'            => $startDate->copy()->addDays(60)->format('Y-m-d'),
                'actual_end_date'            => null,
                'status'                     => ['active', 'active', 'completed'][$i % 3],
                'outcome_notes'              => $i === 2 ? 'Employee met all PIP objectives and has shown sustained improvement.' : null,
                'ai_coaching'                => 'Recommend weekly 1-on-1 coaching with focus on SMART goal setting and accountability frameworks.',
                'ai_outcome_recommendation'  => 'Based on current trajectory, 68% probability of successful PIP completion.',
                'created_at'                 => $startDate,
                'updated_at'                 => now(),
            ]);

            // PIP objectives
            $pipObjectives = [
                ['Achieve 85% KPI Target',         'Achieve a minimum of 85% on all assigned KPIs.',       40, '%',  85],
                ['Submit Weekly Status Reports',    'Submit a weekly progress report every Friday.',        30, 'reports', 4],
                ['Complete Communication Training', 'Attend and pass the communication skills workshop.',   30, 'milestones', 1],
            ];

            foreach ($pipObjectives as $j => [$title, $desc, $weight, $metric, $target]) {
                $objId = DB::table('pa_pip_objectives')->insertGetId([
                    'pip_plan_id' => $pipId,
                    'title'       => $title,
                    'description' => $desc,
                    'metric'      => $metric,
                    'target_value'=> $target,
                    'weight'      => $weight,
                    'status'      => $j === 0 ? 'in_progress' : 'not_started',
                    'due_date'    => $startDate->copy()->addDays(60)->format('Y-m-d'),
                    'created_at'  => $startDate,
                    'updated_at'  => now(),
                ]);

                // Progress logs
                for ($week = 1; $week <= 3; $week++) {
                    DB::table('pa_pip_progress_logs')->insert([
                        'pip_plan_id'  => $pipId,
                        'objective_id' => $objId,
                        'logged_by'    => $managerId,
                        'progress_pct' => $week * 25,
                        'notes'        => "Week {$week} progress review. Employee is showing " . ($week < 3 ? 'gradual improvement.' : 'consistent improvement.'),
                        'logged_at'    => $startDate->copy()->addDays($week * 7),
                        'created_at'   => $startDate->copy()->addDays($week * 7),
                        'updated_at'   => $startDate->copy()->addDays($week * 7),
                    ]);
                }
            }

            // PIP reviews
            foreach ([15, 30] as $daysIn) {
                DB::table('pa_pip_reviews')->insert([
                    'pip_plan_id'    => $pipId,
                    'conducted_by'   => $managerId,
                    'scheduled_date' => $startDate->copy()->addDays($daysIn)->format('Y-m-d'),
                    'status'         => 'completed',
                    'notes'          => "Day-{$daysIn} review completed. Employee demonstrates commitment to improvement. Specific areas: time management and report quality.",
                    'outcome'        => $daysIn === 30 ? 'on_track' : 'needs_improvement',
                    'created_at'     => $startDate->copy()->addDays($daysIn),
                    'updated_at'     => $startDate->copy()->addDays($daysIn),
                ]);
            }
        }

        $this->command->info('  ✓ PIPs, objectives, progress logs and reviews seeded');
    }

    // ── 19. Calibration ─────────────────────────────────────────────────────────

    private function seedCalibration(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');

        DB::table('pa_calibration_sessions')->insertGetId([
            'cycle_id'       => $cycleId,
            'title'          => 'Annual Performance Calibration 2026',
            'facilitator_id' => $this->adminId,
            'scheduled_date' => '2026-11-15',
            'status'         => 'planned',
            'notes'          => 'Annual cross-departmental calibration session to ensure rating consistency and fairness.',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Mid-year completed calibration
        $midSessionId = DB::table('pa_calibration_sessions')->insertGetId([
            'cycle_id'       => $cycleId,
            'title'          => 'Mid-Year Calibration Session — H1 2026',
            'facilitator_id' => $this->adminId,
            'scheduled_date' => '2026-07-20',
            'status'         => 'planned',
            'notes'          => 'Mid-year calibration scheduled. Target: 18 employees to be reviewed.',
            'created_at'     => '2026-04-01',
            'updated_at'     => now(),
        ]);

        // Calibration participants
        foreach (array_slice($this->employees, 0, min(10, count($this->employees))) as $i => $empId) {
            $originalScore  = round(rand(55, 95) / 10, 1) * 10;
            $calibratedScore = $originalScore + (($i % 3 === 0) ? -5 : ($i % 3 === 1 ? 0 : 5));

            DB::table('pa_calibration_participants')->insertOrIgnore([
                'session_id'        => $midSessionId,
                'employee_id'       => $empId,
                'calibrator_id'     => $this->managers[$i % count($this->managers)],
                'original_score'    => $originalScore,
                'calibrated_score'  => max(10, min(100, $calibratedScore)),
                'adjustment_reason' => $calibratedScore !== $originalScore ? 'Score adjusted for cross-team consistency and relative performance context.' : null,
                'finalized'         => true,
                'created_at'        => '2026-05-01',
                'updated_at'        => '2026-05-01',
            ]);
        }

        $this->command->info('  ✓ Calibration sessions and participants seeded');
    }

    // ── 20. Approval Workflows ──────────────────────────────────────────────────

    private function seedApprovalWorkflows(): void
    {
        $workflowTypes = [
            ['Annual Appraisal Sign-off',   'pa_cycle',          'Two-step approval: HR Manager then CEO.'],
            ['PIP Initiation Approval',     'pa_pip_plan',       'HR Manager approval required before PIP is activated.'],
            ['Development Plan Approval',   'pa_development_plan','Manager approval required for development plan and budget.'],
        ];

        foreach ($workflowTypes as [$name, $resourceType, $desc]) {
            $wfId = DB::table('pa_approval_workflows')->insertGetId([
                'name'          => $name,
                'resource_type' => $resourceType,
                'description'   => $desc,
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            $steps = [
                [1, 'Line Manager Review',   $this->managers[0], null,                 true],
                [2, 'HR Manager Approval',   $this->managers[1 % count($this->managers)], 'fleet_manager', true],
                [3, 'Final Sign-off',        $this->adminId,   'administrator',        false],
            ];

            foreach (array_slice($steps, 0, 2) as $step) {
                DB::table('pa_approval_workflow_steps')->insertOrIgnore([
                    'workflow_id'   => $wfId,
                    'step_order'    => $step[0],
                    'step_label'    => $step[1],
                    'approver_id'   => $step[2],
                    'approver_role' => $step[3],
                    'is_required'   => $step[4],
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }

            // Sample approval request
            $requestId = DB::table('pa_approval_requests')->insertGetId([
                'workflow_id'   => $wfId,
                'resource_type' => $resourceType,
                'resource_id'   => 1,
                'title'         => $name . ' — ' . now()->format('Y'),
                'submitted_by'  => $this->employees[0],
                'current_step'  => 2,
                'total_steps'   => 2,
                'status'        => ['pending', 'approved', 'rejected'][array_rand([0, 1, 2])],
                'final_notes'   => null,
                'created_at'    => now()->subDays(rand(3, 20)),
                'updated_at'    => now()->subDays(rand(1, 2)),
            ]);

            DB::table('pa_approval_actions')->insert([
                'request_id'  => $requestId,
                'step_number' => 1,
                'action_by'   => $this->managers[0],
                'action'      => 'approved',
                'notes'       => 'Reviewed and approved. Employee meets all criteria.',
                'actioned_at' => now()->subDays(rand(2, 5)),
                'created_at'  => now()->subDays(rand(2, 5)),
                'updated_at'  => now()->subDays(rand(2, 5)),
            ]);
        }

        $this->command->info('  ✓ Approval workflows, steps, requests and actions seeded');
    }

    // ── 21. Scheduled Reports ───────────────────────────────────────────────────

    private function seedScheduledReports(): void
    {
        $reports = [
            ['Monthly KPI Performance Summary', 'kpi_summary',          'monthly', '06:00', null, 1,  'pdf',   true],
            ['Weekly Probation Status Report',  'probation_status',     'weekly',  '07:00', 1,    null,'excel', true],
            ['Quarterly Wellness Report',       'wellness_summary',     'monthly', '08:00', null, 1,  'pdf',   true],
            ['PIP Status Dashboard',            'pip_status',           'weekly',  '07:30', 5,    null,'excel', false],
            ['Annual Succession Readiness',     'succession_readiness', 'monthly', '06:30', null, 15, 'pdf',   true],
            ['Development Plan Progress',       'development_progress', 'monthly', '07:00', null, 5,  'excel', true],
        ];

        foreach ($reports as [$name, $key, $cadence, $runTime, $dayOfWeek, $dayOfMonth, $format, $active]) {
            DB::table('pa_scheduled_reports')->insertOrIgnore([
                'name'          => $name,
                'report_key'    => $key,
                'cadence'       => $cadence,
                'run_time'      => $runTime,
                'day_of_week'   => $dayOfWeek,
                'day_of_month'  => $dayOfMonth,
                'format'        => $format,
                'recipients'    => 'hr@company.co.za,ceo@company.co.za',
                'active'        => $active,
                'next_run_at'   => now()->addDays(rand(1, 30)),
                'last_run_at'   => now()->subDays(rand(7, 30)),
                'created_by'    => $this->adminId,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        $this->command->info('  ✓ Scheduled reports seeded');
    }

    // ── 22. Competency Framework ────────────────────────────────────────────────

    private function seedCompetencies(): void
    {
        $activeCycle = DB::table('pa_cycles')->where('status', 'active')->value('id');
        $positionIds = DB::table('hr_positions')->pluck('id')->toArray();

        $defs = [
            ['Strategic Thinking', 'leadership', 'Anticipates trends and aligns work to long-term goals.'],
            ['Communication',      'core',       'Conveys ideas clearly and listens actively.'],
            ['Decision Making',    'leadership', 'Makes timely, well-reasoned decisions under uncertainty.'],
            ['Collaboration',      'core',       'Works effectively across teams to achieve shared goals.'],
            ['Technical Mastery',  'technical',  'Demonstrates deep expertise in their professional domain.'],
            ['Customer Focus',     'functional', 'Puts customer needs at the centre of decisions.'],
            ['Adaptability',       'core',       'Responds positively to change and ambiguity.'],
            ['People Leadership',  'leadership', 'Coaches, motivates and develops team members.'],
        ];

        $levelLabels = [1 => 'Foundational', 2 => 'Developing', 3 => 'Proficient', 4 => 'Advanced', 5 => 'Expert'];

        foreach ($defs as $i => [$name, $cat, $desc]) {
            $compId = DB::table('pa_competencies')->insertGetId([
                'uuid'        => (string) \Illuminate\Support\Str::uuid(),
                'name'        => $name,
                'category'    => $cat,
                'description' => $desc,
                'max_level'   => 5,
                'is_active'   => true,
                'sort_order'  => $i,
                'created_by'  => $this->adminId,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // Behavioural anchors for each level
            foreach ($levelLabels as $level => $label) {
                DB::table('pa_competency_anchors')->insert([
                    'competency_id' => $compId,
                    'level'         => $level,
                    'label'         => $label,
                    'behavior'      => "{$label}: demonstrates {$name} at a {$label} level consistently.",
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }

            // Map to a couple of roles with an expected level
            foreach (array_slice($positionIds, 0, 4) as $pi => $posId) {
                if (($pi + $i) % 2 === 0) {
                    DB::table('pa_role_competencies')->insertOrIgnore([
                        'competency_id'  => $compId,
                        'position_id'    => $posId,
                        'expected_level' => rand(2, 4),
                        'is_critical'    => $cat === 'leadership',
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }
            }

            // Employee self/manager assessments
            foreach (array_slice($this->employees, 0, 12) as $ei => $empId) {
                if (($ei + $i) % 3 !== 0) {
                    continue;
                }
                $target  = rand(3, 5);
                $current = rand(1, $target);
                DB::table('pa_employee_competencies')->insertOrIgnore([
                    'employee_id'   => $empId,
                    'competency_id' => $compId,
                    'cycle_id'      => $activeCycle,
                    'current_level' => $current,
                    'target_level'  => $target,
                    'self_rated'    => (bool) rand(0, 1),
                    'assessed_by'   => $this->managers[$ei % count($this->managers)],
                    'assessed_at'   => now()->subDays(rand(5, 40)),
                    'notes'         => null,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }

        $this->command->info('  ✓ Competencies, anchors, role maps & employee assessments seeded');
    }

    // ── 23. 9-Box Talent Grid ───────────────────────────────────────────────────

    private function seedNineBox(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');
        if (! $cycleId) {
            return;
        }

        $bands = ['low', 'medium', 'high'];
        $bandIdx = ['low' => 0, 'medium' => 1, 'high' => 2];

        // Use latest snapshot per employee + talent profile for potential.
        $snapshots = DB::table('pa_performance_snapshots')
            ->where('cycle_id', $cycleId)
            ->select('employee_id', DB::raw('MAX(overall_score) as score'))
            ->groupBy('employee_id')
            ->get();

        $profiles = DB::table('pa_talent_profiles')->where('cycle_id', $cycleId)
            ->pluck('promotion_readiness_score', 'employee_id');

        foreach ($snapshots as $snap) {
            $perfScore = (float) $snap->score;                 // 1..5
            $potScore  = (float) ($profiles[$snap->employee_id] ?? rand(40, 90)); // 0..100

            $perfBand = $perfScore >= 3.75 ? 'high' : ($perfScore >= 2.25 ? 'medium' : 'low');
            $potBand  = $potScore  >= 75   ? 'high' : ($potScore  >= 45   ? 'medium' : 'low');
            $box      = ($bandIdx[$potBand] * 3) + $bandIdx[$perfBand] + 1;

            DB::table('pa_nine_box_placements')->insertOrIgnore([
                'cycle_id'          => $cycleId,
                'employee_id'       => $snap->employee_id,
                'performance_band'  => $perfBand,
                'potential_band'    => $potBand,
                'box'               => $box,
                'performance_score' => round($perfScore, 2),
                'potential_score'   => round($potScore, 2),
                'is_manual'         => false,
                'placed_by'         => null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        $this->command->info('  ✓ 9-box placements seeded');
    }

    // ── 24. Recognition / Kudos feed ────────────────────────────────────────────

    private function seedRecognition(): void
    {
        $badges = ['team_player', 'innovation', 'customer_hero', 'above_beyond', 'leadership', 'ownership'];
        $messages = [
            'Went above and beyond to deliver the project ahead of schedule.',
            'Outstanding support to a colleague under pressure — true team spirit!',
            'Brilliant idea that saved the team hours of manual work.',
            'Handled a difficult customer with patience and professionalism.',
            'Stepped up to lead the initiative when it mattered most.',
            'Consistently reliable and owns every commitment end-to-end.',
        ];
        $emojis = ['👏', '🎉', '🙌', '❤️', '🔥', '💯'];

        $count = min(20, count($this->employees) * 2);
        for ($i = 0; $i < $count; $i++) {
            $giver    = $this->employees[$i % count($this->employees)];
            $receiver = $this->employees[($i + 3) % count($this->employees)];
            if ($giver === $receiver) {
                $receiver = $this->employees[($i + 4) % count($this->employees)];
            }

            $recId = DB::table('pa_recognitions')->insertGetId([
                'uuid'               => (string) \Illuminate\Support\Str::uuid(),
                'giver_id'           => $giver,
                'receiver_id'        => $receiver,
                'badge'              => $badges[$i % count($badges)],
                'value_tag'          => null,
                'message'            => $messages[$i % count($messages)],
                'is_public'          => true,
                'mentioned_user_ids' => json_encode([]),
                'created_at'         => now()->subDays(rand(0, 60)),
                'updated_at'         => now(),
            ]);

            // Reactions from a few colleagues
            $reactors = array_slice($this->employees, 0, rand(2, 5));
            foreach ($reactors as $ri => $reactor) {
                DB::table('pa_recognition_reactions')->insertOrIgnore([
                    'recognition_id' => $recId,
                    'user_id'        => $reactor,
                    'emoji'          => $emojis[($i + $ri) % count($emojis)],
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            // A comment on some
            if ($i % 3 === 0) {
                DB::table('pa_recognition_comments')->insert([
                    'recognition_id' => $recId,
                    'user_id'        => $this->employees[($i + 1) % count($this->employees)],
                    'body'           => 'Well deserved! 🎉',
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }

        $this->command->info('  ✓ Recognition feed (posts, reactions, comments) seeded');
    }

    // ── 25. Compensation / Merit review ─────────────────────────────────────────

    private function seedCompensation(): void
    {
        $cycle = DB::table('pa_cycles')->where('status', 'active')->first();
        if (! $cycle) {
            return;
        }

        $reviewId = DB::table('pa_comp_reviews')->insertGetId([
            'uuid'             => (string) \Illuminate\Support\Str::uuid(),
            'cycle_id'         => $cycle->id,
            'name'             => 'Annual Merit Review ' . now()->year,
            'status'           => 'in_review',
            'merit_budget_pct' => 6.0,
            'bonus_budget_pct' => 8.0,
            'notes'            => 'Merit and bonus recommendations derived from calibrated performance scores.',
            'created_by'       => $this->adminId,
            'approved_by'      => null,
            'approved_at'      => null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $matrix = [
            ['min' => 0.90, 'band' => 'Outstanding',     'merit' => 8.0, 'bonus' => 15.0],
            ['min' => 0.75, 'band' => 'Exceeds',         'merit' => 6.0, 'bonus' => 10.0],
            ['min' => 0.55, 'band' => 'Meets',           'merit' => 4.0, 'bonus' => 5.0],
            ['min' => 0.40, 'band' => 'Partially Meets', 'merit' => 2.0, 'bonus' => 0.0],
            ['min' => 0.0,  'band' => 'Below',           'merit' => 0.0, 'bonus' => 0.0],
        ];

        $snapshots = DB::table('pa_performance_snapshots')
            ->where('cycle_id', $cycle->id)
            ->select('employee_id', DB::raw('MAX(overall_score) as score'))
            ->groupBy('employee_id')->get();

        $salaries = DB::table('hr_people')->whereIn('user_id', $snapshots->pluck('employee_id'))
            ->pluck('monthly_package', 'user_id');

        foreach ($snapshots as $snap) {
            $norm = min(1, ((float) $snap->score) / 5);
            $band = collect($matrix)->first(fn ($b) => $norm >= $b['min']);
            $salary = (float) ($salaries[$snap->employee_id] ?? rand(18000, 75000));

            DB::table('pa_comp_recommendations')->insertOrIgnore([
                'comp_review_id'      => $reviewId,
                'employee_id'         => $snap->employee_id,
                'performance_score'   => round((float) $snap->score, 2),
                'rating_band'         => $band['band'],
                'current_salary'      => $salary,
                'suggested_merit_pct' => $band['merit'],
                'final_merit_pct'     => $band['merit'],
                'suggested_bonus_pct' => $band['bonus'],
                'final_bonus_pct'     => $band['bonus'],
                'new_salary'          => round($salary * (1 + $band['merit'] / 100), 2),
                'bonus_amount'        => round($salary * ($band['bonus'] / 100), 2),
                'status'              => 'pending',
                'justification'       => null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        $this->command->info('  ✓ Compensation review & recommendations seeded');
    }

    // ── 26. 1:1 talking points & action items ───────────────────────────────────

    private function seedOneOnOneDetails(): void
    {
        $checkins = DB::table('pa_checkins')->limit(15)->get();
        $tps = [
            'Review progress on Q3 objectives',
            'Discuss career growth aspirations',
            'Blockers and support needed',
            'Feedback on recent project',
            'Wellbeing and workload check',
        ];
        $actions = [
            'Share the updated project roadmap',
            'Book training course on the LMS',
            'Introduce to mentor in another team',
            'Draft development plan for next quarter',
            'Follow up with stakeholder on deliverable',
        ];

        foreach ($checkins as $ci) {
            $roles = ['employee', 'manager'];
            for ($t = 0; $t < rand(2, 4); $t++) {
                DB::table('pa_checkin_talking_points')->insert([
                    'checkin_id'  => $ci->id,
                    'created_by'  => $t % 2 === 0 ? $ci->employee_id : $ci->manager_id,
                    'author_role' => $roles[$t % 2],
                    'body'        => $tps[($ci->id + $t) % count($tps)],
                    'is_discussed' => $ci->status === 'completed' ? (bool) rand(0, 1) : false,
                    'sort_order'  => $t,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            for ($a = 0; $a < rand(1, 3); $a++) {
                $done = $ci->status === 'completed' && rand(0, 1) === 1;
                DB::table('pa_checkin_action_items')->insert([
                    'checkin_id'   => $ci->id,
                    'owner_id'     => $a % 2 === 0 ? $ci->employee_id : $ci->manager_id,
                    'description'  => $actions[($ci->id + $a) % count($actions)],
                    'due_date'     => now()->addDays(rand(3, 21))->toDateString(),
                    'status'       => $done ? 'done' : 'open',
                    'completed_at' => $done ? now()->subDays(rand(1, 5)) : null,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        }

        $this->command->info('  ✓ 1:1 talking points & action items seeded');
    }

    // ── 27. Proactive AI nudges ─────────────────────────────────────────────────

    private function seedNudges(): void
    {
        $cycleId = DB::table('pa_cycles')->where('status', 'active')->value('id');

        $templates = [
            ['type' => 'checkin_overdue', 'priority' => 'high',   'title' => 'Time for a 1:1',            'body' => 'No completed check-in in over 5 weeks. Schedule the next one.', 'route' => 'performance.checkins.index'],
            ['type' => 'review_ready',    'priority' => 'high',   'title' => 'Self-review ready',          'body' => 'A team member submitted their self-review. AI can draft a summary.', 'route' => 'performance.appraisal-review.index'],
            ['type' => 'goal_at_risk',    'priority' => 'normal', 'title' => 'Goal at risk',               'body' => '75% of the timeline elapsed but progress is below 60%.', 'route' => 'performance.goals.index'],
            ['type' => 'wellness_risk',   'priority' => 'high',   'title' => 'Wellness alert',             'body' => 'Burnout risk is high — consider a supportive check-in.', 'route' => 'performance.wellness.index'],
        ];

        foreach (array_slice($this->managers, 0, 5) as $mi => $mgr) {
            foreach ($templates as $ti => $tpl) {
                if (($mi + $ti) % 2 !== 0) {
                    continue;
                }
                $about = $this->employees[($mi + $ti) % count($this->employees)];
                DB::table('pa_nudges')->insert([
                    'user_id'           => $mgr,
                    'about_employee_id' => $about,
                    'cycle_id'          => $cycleId,
                    'type'              => $tpl['type'],
                    'priority'          => $tpl['priority'],
                    'title'             => $tpl['title'],
                    'body'              => $tpl['body'],
                    'action_route'      => $tpl['route'],
                    'data'              => null,
                    'dismissed_at'      => null,
                    'acted_at'          => null,
                    'created_at'        => now()->subDays(rand(0, 5)),
                    'updated_at'        => now(),
                ]);
            }
        }

        $this->command->info('  ✓ Proactive AI nudges seeded');
    }
}
