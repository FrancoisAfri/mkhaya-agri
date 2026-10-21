<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use ReflectionClass;

class SystemRestorationController extends Controller
{
    /**
     * Full registry of restorable models = curated entries (nice labels/columns)
     * MERGED with every other model that uses SoftDeletes, auto-discovered from
     * the codebase. This guarantees that ANY soft-deletable record can be
     * restored here, even if it was never added to the curated list by hand.
     *
     * Cached so the filesystem scan + schema introspection only runs occasionally.
     */
    private function registry(): array
    {
        return Cache::remember('restoration_registry_v2', 1800, function () {
            $curated = $this->curatedRegistry();

            $curatedClasses = array_map(
                fn ($cfg) => ltrim($cfg['class'], '\\'),
                array_values($curated)
            );

            // Map a module's namespace segment → the curated module label so that
            // auto-discovered models from the same module reuse the SAME label
            // (e.g. discovered HelpDesk/LMS models don't create a second
            // "Help Desk" / "L M S" group next to the curated "HelpDesk" / "LMS").
            $labelBySegment = [];
            foreach ($curated as $cfg) {
                $seg = $this->moduleSegment($cfg['class']);
                if ($seg !== null) {
                    $labelBySegment[mb_strtolower($seg)] ??= $cfg['module'];
                }
            }

            $discovered = [];
            foreach ($this->discoverSoftDeleteModels() as $class) {
                // Skip anything the curated list already covers (better metadata).
                if (in_array(ltrim($class, '\\'), $curatedClasses, true)) {
                    continue;
                }

                $key = $this->uniqueKey(Str::snake(class_basename($class)), $curated, $discovered);
                $seg = $this->moduleSegment($class);

                $discovered[$key] = [
                    'label'       => $this->humanizeName(class_basename($class)),
                    'class'       => $class,
                    'module'      => $labelBySegment[mb_strtolower((string) $seg)] ?? $this->humanizeName((string) $seg),
                    'module_code' => null, // null = always visible (never hidden)
                    'searchable'  => $this->searchableFor($class),
                ];
            }

            return array_merge($curated, $discovered);
        });
    }

    /**
     * Discover every concrete Eloquent model in app/Modules and app/Core that
     * uses the SoftDeletes trait. Returns a list of fully-qualified class names.
     */
    private function discoverSoftDeleteModels(): array
    {
        $classes = [];

        foreach ([app_path('Modules'), app_path('Core')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                // Only look inside Models directories.
                if (! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR)) {
                    continue;
                }

                // Statically pre-filter: only autoload files that actually
                // reference SoftDeletes. This keeps unrelated (and possibly
                // broken) model files from being loaded and fataling the scan.
                $contents = @file_get_contents($file->getPathname());
                if ($contents === false || ! preg_match('/\bSoftDeletes\b/', $contents)) {
                    continue;
                }

                $class = $this->classFromSource($contents) ?? $this->classFromPath($file->getPathname());
                if (! $class || ! class_exists($class)) {
                    continue;
                }

                try {
                    $ref = new ReflectionClass($class);
                    if ($ref->isAbstract() || ! $ref->isSubclassOf(Model::class)) {
                        continue;
                    }
                    if (! in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }

                $classes[$class] = $class;
            }
        }

        return array_values($classes);
    }

    /** Resolve the FQCN from a file's declared namespace + class name. */
    private function classFromSource(string $contents): ?string
    {
        if (! preg_match('/\bnamespace\s+([^;]+);/', $contents, $ns)) {
            return null;
        }
        if (! preg_match('/\bclass\s+(\w+)/', $contents, $cls)) {
            return null;
        }

        return trim($ns[1]).'\\'.$cls[1];
    }

    /** Map an app/ file path to its PSR-4 class name (App\ → app/). */
    private function classFromPath(string $path): ?string
    {
        $appPath = app_path();
        if (! str_starts_with($path, $appPath)) {
            return null;
        }

        $rel = ltrim(substr($path, strlen($appPath)), DIRECTORY_SEPARATOR);
        $rel = preg_replace('/\.php$/', '', $rel);

        return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $rel);
    }

    /** The namespace segment that identifies a model's module (e.g. "HelpDesk", "LMS"). */
    private function moduleSegment(string $class): ?string
    {
        $parts = explode('\\', ltrim($class, '\\'));
        array_shift($parts);          // App
        $root = array_shift($parts);  // Modules | Core

        $modelsIdx = array_search('Models', $parts, true);
        $segs = $modelsIdx === false ? $parts : array_slice($parts, 0, $modelsIdx);

        return ! empty($segs) ? (string) end($segs) : ($root ?: null);
    }

    /**
     * Humanize a PascalCase/camelCase name while preserving acronyms — splits
     * "HelpDesk" → "Help Desk" and "ContractManagement" → "Contract Management"
     * but keeps "LMS" and "HR" intact (unlike Str::headline).
     */
    private function humanizeName(string $value): string
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $value);

        return trim((string) preg_replace('/[_-]+/', ' ', (string) $spaced)) ?: $value;
    }

    /** Pick a few sensible searchable columns from a model's table. */
    private function searchableFor(string $class): array
    {
        try {
            $cols = Schema::getColumnListing((new $class)->getTable());
        } catch (\Throwable) {
            return ['id'];
        }

        $preferred = ['name', 'title', 'subject', 'reference_number', 'reference',
            'ref_number', 'code', 'number', 'first_name', 'last_name', 'email',
            'description', 'status'];

        $picked = array_values(array_intersect($preferred, $cols));

        if (empty($picked)) {
            $picked = array_values(array_filter(
                $cols,
                fn ($c) => ! in_array($c, ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'], true)
                    && ! str_ends_with($c, '_id')
            ));
        }

        return array_slice($picked, 0, 4) ?: ['id'];
    }

    /** Ensure a registry key doesn't collide with existing curated/discovered keys. */
    private function uniqueKey(string $base, array $curated, array $discovered): string
    {
        $key = $base ?: 'model';
        $i = 2;
        while (isset($curated[$key]) || isset($discovered[$key])) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }

    /**
     * Curated registry: hand-maintained entries with friendly labels and the
     * best columns for search/labels. Auto-discovery fills in everything else.
     * Key  → unique identifier used in routes
     * value→ [label, class, module, module_code, searchable columns]
     */
    private function curatedRegistry(): array
    {
        return [
            // ── Fleet ─────────────────────────────────────────────────────────
            'fleet_vehicle'         => ['label' => 'Fleet Vehicle',         'class' => \App\Modules\Fleet\Models\FleetVehicle::class,                                          'module' => 'Fleet',          'module_code' => 'fleet_management',     'searchable' => ['registration_number', 'fleet_number', 'make', 'model']],
            'fleet_driver'          => ['label' => 'Fleet Driver',          'class' => \App\Modules\Fleet\Models\FleetDriver::class,                                           'module' => 'Fleet',          'module_code' => 'fleet_management',     'searchable' => ['licence_number']],
            'fleet_booking'         => ['label' => 'Fleet Booking',         'class' => \App\Modules\Fleet\Models\FleetBooking::class,                                          'module' => 'Fleet',          'module_code' => 'fleet_management',     'searchable' => ['reference', 'purpose']],
            'fleet_document'        => ['label' => 'Fleet Document',        'class' => \App\Modules\Fleet\Models\FleetDocument::class,                                         'module' => 'Fleet',          'module_code' => 'fleet_management',     'searchable' => ['document_number', 'document_type']],
            'fleet_maintenance'     => ['label' => 'Fleet Maintenance',     'class' => \App\Modules\Fleet\Models\FleetMaintenance::class,                                      'module' => 'Fleet',          'module_code' => 'fleet_management',     'searchable' => ['category', 'description', 'invoice_number']],
            'fleet_fuel_log'        => ['label' => 'Fuel Log',              'class' => \App\Modules\Fleet\Models\FleetFuelLog::class,                                          'module' => 'Fleet',          'module_code' => 'fleet_management',     'searchable' => ['station_name', 'fuel_type']],
            'fleet_card'            => ['label' => 'Fleet Card',            'class' => \App\Modules\Fleet\Models\FleetCard::class,                                             'module' => 'Fleet',          'module_code' => 'fleet_management',     'searchable' => ['card_number_last4', 'provider']],

            // ── HR – Attendance ───────────────────────────────────────────────
            'attendance_record'        => ['label' => 'Attendance Record',   'class' => \App\Modules\HR\Hr\Models\Attendance\AttendanceRecord::class,                         'module' => 'Attendance',     'module_code' => 'time_attendance',      'searchable' => ['status']],
            'attendance_clocking'      => ['label' => 'Attendance Clocking', 'class' => \App\Modules\HR\Hr\Models\Attendance\AttendanceClocking::class,                       'module' => 'Attendance',     'module_code' => 'time_attendance',      'searchable' => ['type', 'status']],
            'attendance_shift'         => ['label' => 'Attendance Shift',    'class' => \App\Modules\HR\Hr\Models\Attendance\AttendanceShift::class,                          'module' => 'Attendance',     'module_code' => 'time_attendance',      'searchable' => ['name']],
            'attendance_status'        => ['label' => 'Attendance Status',   'class' => \App\Modules\HR\Hr\Models\Attendance\AttendanceStatus::class,                         'module' => 'Attendance',     'module_code' => 'time_attendance',      'searchable' => ['name']],
            'attendance_work_calendar' => ['label' => 'Work Calendar',       'class' => \App\Modules\HR\Hr\Models\Attendance\AttendanceWorkCalendar::class,                   'module' => 'Attendance',     'module_code' => 'time_attendance',      'searchable' => ['name']],

            // ── HR – Leave ────────────────────────────────────────────────────
            'leave_request'              => ['label' => 'Leave Request',              'class' => \App\Modules\HR\Leave\Models\LeaveRequest::class,              'module' => 'Leave', 'module_code' => 'leave_management', 'searchable' => ['status', 'leave_type_id']],
            'leave_application'          => ['label' => 'Leave Application',          'class' => \App\Modules\HR\Leave\Models\LeaveApplication::class,          'module' => 'Leave', 'module_code' => 'leave_management', 'searchable' => ['status']],
            'leave_type'                 => ['label' => 'Leave Type',                 'class' => \App\Modules\HR\Leave\Models\LeaveType::class,                 'module' => 'Leave', 'module_code' => 'leave_management', 'searchable' => ['name']],
            'leave_document'             => ['label' => 'Leave Document',             'class' => \App\Modules\HR\Leave\Models\LeaveDocument::class,             'module' => 'Leave', 'module_code' => 'leave_management', 'searchable' => ['type']],
            'leave_balance_transaction'  => ['label' => 'Leave Balance Transaction',  'class' => \App\Modules\HR\Leave\Models\LeaveBalanceTransaction::class,  'module' => 'Leave', 'module_code' => 'leave_management', 'searchable' => ['transaction_type', 'reason']],
            'leave_profile_allocation'   => ['label' => 'Leave Credit (Allocation)',  'class' => \App\Modules\HR\Leave\Models\LeaveProfileAllocation::class,   'module' => 'Leave', 'module_code' => 'leave_management', 'searchable' => ['status']],
            'leave_payout_record'        => ['label' => 'Leave Payout Record',        'class' => \App\Modules\HR\Leave\Models\LeavePayoutRecord::class,        'module' => 'Leave', 'module_code' => 'leave_management', 'searchable' => ['status']],

            // ── HR – Payroll ──────────────────────────────────────────────────
            'payroll_run'           => ['label' => 'Payroll Run',           'class' => \App\Modules\HR\Payroll\Models\PayrollRun::class,                                      'module' => 'Payroll',        'module_code' => 'payroll',              'searchable' => ['ref_number', 'name', 'status']],
            'payslip'               => ['label' => 'Payslip',               'class' => \App\Modules\HR\Payroll\Models\Payslip::class,                                         'module' => 'Payroll',        'module_code' => 'payroll',              'searchable' => ['status']],
            'payroll_correction'    => ['label' => 'Payroll Correction',    'class' => \App\Modules\HR\Payroll\Models\PayrollCorrection::class,                               'module' => 'Payroll',        'module_code' => 'payroll',              'searchable' => ['correction_type', 'status']],
            'payroll_anomaly'       => ['label' => 'Payroll Anomaly',       'class' => \App\Modules\HR\Payroll\Models\PayrollAnomaly::class,                                  'module' => 'Payroll',        'module_code' => 'payroll',              'searchable' => ['anomaly_type', 'severity', 'status']],

            // ── HR – Recruitment ──────────────────────────────────────────────
            'recruitment_vacancy'    => ['label' => 'Vacancy',               'class' => \App\Modules\HR\Recruitment\Models\Vacancy::class,                                    'module' => 'Recruitment',    'module_code' => 'recruitment',          'searchable' => ['title', 'status']],
            'recruitment_requisition'=> ['label' => 'Requisition',           'class' => \App\Modules\HR\Recruitment\Models\Requisition::class,                               'module' => 'Recruitment',    'module_code' => 'recruitment',          'searchable' => ['title', 'status']],
            'recruitment_candidate'  => ['label' => 'Candidate',             'class' => \App\Modules\HR\Recruitment\Models\Candidate::class,                                 'module' => 'Recruitment',    'module_code' => 'recruitment',          'searchable' => ['first_name', 'last_name', 'email']],
            'recruitment_application'=> ['label' => 'Application',           'class' => \App\Modules\HR\Recruitment\Models\Application::class,                               'module' => 'Recruitment',    'module_code' => 'recruitment',          'searchable' => ['status']],

            // ── HR – Onboarding ───────────────────────────────────────────────
            'onboarding_plan'       => ['label' => 'Onboarding Plan',       'class' => \App\Modules\HR\Onboarding\Models\OnboardingPlan::class,     'module' => 'Onboarding', 'module_code' => 'onboarding', 'searchable' => ['status']],
            'onboarding_task'       => ['label' => 'Onboarding Task',       'class' => \App\Modules\HR\Onboarding\Models\OnboardingTask::class,     'module' => 'Onboarding', 'module_code' => 'onboarding', 'searchable' => ['title', 'status']],
            'onboarding_document'   => ['label' => 'Onboarding Document',   'class' => \App\Modules\HR\Onboarding\Models\OnboardingDocument::class, 'module' => 'Onboarding', 'module_code' => 'onboarding', 'searchable' => ['title', 'status']],
            'onboarding_template'   => ['label' => 'Onboarding Template',   'class' => \App\Modules\HR\Onboarding\Models\OnboardingTemplate::class, 'module' => 'Onboarding', 'module_code' => 'onboarding', 'searchable' => ['name', 'status']],

            // ── Finance – Claims ──────────────────────────────────────────────
            'claim'                 => ['label' => 'Claim',                 'class' => \App\Modules\Finance\Claims\Models\Claim::class,                                       'module' => 'Claims',         'module_code' => 'claims',               'searchable' => ['reference_number', 'status']],
            'claim_item'            => ['label' => 'Claim Item',            'class' => \App\Modules\Finance\Claims\Models\ClaimItem::class,                                   'module' => 'Claims',         'module_code' => 'claims',               'searchable' => ['description', 'category']],
            'claim_category'        => ['label' => 'Claim Category',        'class' => \App\Modules\Finance\Claims\Models\ClaimCategory::class,                               'module' => 'Claims',         'module_code' => 'claims',               'searchable' => ['name']],

            // ── Finance – Petty Cash ──────────────────────────────────────────
            'petty_cash_request'    => ['label' => 'Petty Cash Request',    'class' => \App\Modules\Finance\PettyCash\Models\PettyCashRequest::class,                         'module' => 'Petty Cash',     'module_code' => 'petty_cash',           'searchable' => ['reference_number', 'status']],
            'petty_cash_float'      => ['label' => 'Petty Cash Float',      'class' => \App\Modules\Finance\PettyCash\Models\PettyCashFloat::class,                           'module' => 'Petty Cash',     'module_code' => 'petty_cash',           'searchable' => ['name', 'status']],

            // ── Finance – Staff Loan ──────────────────────────────────────────
            'staff_loan'            => ['label' => 'Staff Loan',            'class' => \App\Modules\Finance\StaffLoan\Models\StaffLoan::class,         'module' => 'Staff Loans', 'module_code' => 'staff_loans', 'searchable' => ['reference_number', 'status']],
            'salary_advance'        => ['label' => 'Salary Advance',        'class' => \App\Modules\Finance\StaffLoan\Models\SalaryAdvance::class,     'module' => 'Staff Loans', 'module_code' => 'staff_loans', 'searchable' => ['status']],
            'staff_loan_type'       => ['label' => 'Staff Loan Type',       'class' => \App\Modules\Finance\StaffLoan\Models\StaffLoanType::class,     'module' => 'Staff Loans', 'module_code' => 'staff_loans', 'searchable' => ['name']],
            'staff_loan_approval'   => ['label' => 'Staff Loan Approval',   'class' => \App\Modules\Finance\StaffLoan\Models\StaffLoanApproval::class, 'module' => 'Staff Loans', 'module_code' => 'staff_loans', 'searchable' => ['status']],

            // ── Policies ──────────────────────────────────────────────────────
            'policy'                => ['label' => 'Policy',                'class' => \App\Modules\Policies\Models\Policy::class,         'module' => 'Policies', 'module_code' => 'policy_enforcement', 'searchable' => ['title', 'status']],
            'policy_version'        => ['label' => 'Policy Version',        'class' => \App\Modules\Policies\Models\PolicyVersion::class,  'module' => 'Policies', 'module_code' => 'policy_enforcement', 'searchable' => ['version_number', 'status']],
            'policy_clause'         => ['label' => 'Policy Clause',         'class' => \App\Modules\Policies\Models\PolicyClause::class,   'module' => 'Policies', 'module_code' => 'policy_enforcement', 'searchable' => ['title', 'clause_type']],
            'policy_question'       => ['label' => 'Policy Question',       'class' => \App\Modules\Policies\Models\PolicyQuestion::class, 'module' => 'Policies', 'module_code' => 'policy_enforcement', 'searchable' => ['question']],
            'policy_exception'      => ['label' => 'Policy Exception',      'class' => \App\Modules\Policies\Models\PolicyException::class,'module' => 'Policies', 'module_code' => 'policy_enforcement', 'searchable' => ['status']],
            'policy_violation'      => ['label' => 'Policy Violation',      'class' => \App\Modules\Policies\Models\PolicyViolation::class,'module' => 'Policies', 'module_code' => 'policy_enforcement', 'searchable' => ['status']],

            // ── Disciplinary ──────────────────────────────────────────────────
            'disciplinary_case'          => ['label' => 'Disciplinary Case',          'class' => \App\Modules\Disciplinary\Models\DisciplinaryCase::class,          'module' => 'Disciplinary', 'module_code' => 'disciplinary', 'searchable' => ['case_number', 'status']],
            'disciplinary_warning'       => ['label' => 'Disciplinary Warning',       'class' => \App\Modules\Disciplinary\Models\DisciplinaryWarning::class,       'module' => 'Disciplinary', 'module_code' => 'disciplinary', 'searchable' => ['warning_type', 'status']],
            'disciplinary_hearing'       => ['label' => 'Disciplinary Hearing',       'class' => \App\Modules\Disciplinary\Models\DisciplinaryHearing::class,       'module' => 'Disciplinary', 'module_code' => 'disciplinary', 'searchable' => ['status', 'outcome']],
            'disciplinary_appeal'        => ['label' => 'Disciplinary Appeal',        'class' => \App\Modules\Disciplinary\Models\DisciplinaryAppeal::class,        'module' => 'Disciplinary', 'module_code' => 'disciplinary', 'searchable' => ['status', 'grounds']],
            'disciplinary_investigation' => ['label' => 'Disciplinary Investigation', 'class' => \App\Modules\Disciplinary\Models\DisciplinaryInvestigation::class, 'module' => 'Disciplinary', 'module_code' => 'disciplinary', 'searchable' => ['status']],

            // ── Assets ────────────────────────────────────────────────────────
            'asset'                 => ['label' => 'Asset',                 'class' => \App\Modules\Assets\Models\Assets::class,                                               'module' => 'Assets',         'module_code' => 'asset_management',     'searchable' => ['asset_number', 'name', 'serial_number']],
            'asset_assignment'      => ['label' => 'Asset Assignment',      'class' => \App\Modules\Assets\Models\AssetAssignment::class,                                      'module' => 'Assets',         'module_code' => 'asset_management',     'searchable' => ['status']],
            'asset_maintenance'     => ['label' => 'Asset Maintenance',     'class' => \App\Modules\Assets\Models\AssetMaintenance::class,                                     'module' => 'Assets',         'module_code' => 'asset_management',     'searchable' => ['description', 'status']],
            'asset_category'        => ['label' => 'Asset Category',        'class' => \App\Modules\Assets\Models\AssetCategory::class,                                        'module' => 'Assets',         'module_code' => 'asset_management',     'searchable' => ['name']],
            'asset_document'        => ['label' => 'Asset Document',        'class' => \App\Modules\Assets\Models\AssetDocument::class,                                        'module' => 'Assets',         'module_code' => 'asset_management',     'searchable' => ['title', 'document_type']],
            'asset_license'         => ['label' => 'Asset License',         'class' => \App\Modules\Assets\Models\AssetLicense::class,                                         'module' => 'Assets',         'module_code' => 'asset_management',     'searchable' => ['name', 'vendor']],

            // ── Communications ────────────────────────────────────────────────
            'content'               => ['label' => 'Content',               'class' => \App\Modules\Communication\ContentManagement\Models\Content::class,                     'module' => 'Communications', 'module_code' => 'content_management',   'searchable' => ['title', 'status']],
            'ceo_message'           => ['label' => 'CEO Message',           'class' => \App\Modules\Communication\ContentManagement\Models\CeoMessage::class,                  'module' => 'Communications', 'module_code' => 'content_management',   'searchable' => ['title']],
            'employee_spotlight'    => ['label' => 'Employee Spotlight',    'class' => \App\Modules\Communication\ContentManagement\Models\EmployeeSpotlight::class,            'module' => 'Communications', 'module_code' => 'content_management',   'searchable' => ['title']],

            // ── Learning Management System (LMS) ───────────────────────────────
            'lms_course'               => ['label' => 'Course',               'class' => \App\Modules\HR\LMS\Models\LmsCourse::class,               'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['title', 'status']],
            'lms_category'             => ['label' => 'Course Category',      'class' => \App\Modules\HR\LMS\Models\LmsCategory::class,             'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['name']],
            'lms_enrollment'           => ['label' => 'Enrollment',           'class' => \App\Modules\HR\LMS\Models\LmsEnrollment::class,           'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['status']],
            'lms_certificate'          => ['label' => 'Certificate',          'class' => \App\Modules\HR\LMS\Models\LmsCertificate::class,          'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['certificate_number']],
            'lms_learning_path'        => ['label' => 'Learning Path',        'class' => \App\Modules\HR\LMS\Models\LmsLearningPath::class,         'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['title']],
            'lms_questionnaire'        => ['label' => 'Questionnaire',        'class' => \App\Modules\HR\LMS\Models\LmsQuestionnaire::class,        'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['title', 'type']],
            'lms_question_bank'        => ['label' => 'Question Bank',        'class' => \App\Modules\HR\LMS\Models\LmsQuestionBank::class,         'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['title', 'category']],
            'lms_compliance_rule'      => ['label' => 'Compliance Rule',      'class' => \App\Modules\HR\LMS\Models\LmsComplianceRule::class,       'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['name', 'scope_type']],
            'lms_certification'        => ['label' => 'Certification',        'class' => \App\Modules\HR\LMS\Models\LmsCertification::class,        'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['name']],
            'lms_certificate_template' => ['label' => 'Certificate Template', 'class' => \App\Modules\HR\LMS\Models\LmsCertificateTemplate::class,  'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['name']],
            'lms_skill'                => ['label' => 'Skill',                'class' => \App\Modules\HR\LMS\Models\LmsSkill::class,                'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['name', 'category']],
            'lms_learnership'          => ['label' => 'Learnership',          'class' => \App\Modules\HR\LMS\Models\Qcto\LmsLearnership::class,     'module' => 'LMS', 'module_code' => 'learning_management', 'searchable' => ['title', 'saqa_id']],
        ];
    }

    private function activeModuleCodes(): array
    {
        return Cache::remember('active_module_codes', 300, fn () =>
            DB::table('security_modules')->where('active', 1)->pluck('code_name')->all()
        );
    }

    private function activeRegistry(): array
    {
        $active = $this->activeModuleCodes();
        // Curated entries respect their module's active state; auto-discovered
        // entries (module_code === null) are always shown so nothing is hidden.
        return array_filter(
            $this->registry(),
            fn ($cfg) => empty($cfg['module_code']) || in_array($cfg['module_code'], $active, true)
        );
    }

    public function index(Request $request): Response
    {
        $registry  = $this->activeRegistry();
        $filterKey = $request->get('model');
        $search    = $request->get('search');

        $summary = collect($registry)->map(function ($cfg, $key) {
            $count = 0;
            try { $count = $cfg['class']::onlyTrashed()->count(); } catch (\Throwable) {}
            return ['key' => $key, 'label' => $cfg['label'], 'module' => $cfg['module'], 'count' => $count];
        })->values()->toArray();

        $totalDeleted = collect($summary)->sum('count');
        $records  = [];
        $meta     = ['total' => 0, 'per_page' => 20, 'current_page' => 1, 'last_page' => 1];
        $columns  = [];

        if ($filterKey && isset($registry[$filterKey])) {
            $cfg   = $registry[$filterKey];
            $query = $cfg['class']::onlyTrashed();

            if ($search) {
                $query->where(function ($q) use ($cfg, $search) {
                    foreach ($cfg['searchable'] as $col) {
                        $q->orWhere($col, 'ILIKE', "%{$search}%");
                    }
                });
            }

            $paginator = $query->orderByDesc('deleted_at')->paginate(20);

            $records = collect($paginator->items())->map(function ($m) use ($cfg) {
                $row = $m->toArray();
                $row['_id']         = $m->getKey();
                $row['_deleted_at'] = $m->deleted_at?->diffForHumans();
                $row['_label']      = $this->labelFor($m, $cfg['searchable']);
                return $row;
            })->toArray();

            $meta = [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ];

            if (!empty($records)) {
                $exclude = ['id', 'uuid', 'created_by', 'updated_by', 'deleted_at', '_id', '_deleted_at', '_label'];
                $columns = array_values(array_filter(
                    array_keys($records[0]),
                    fn($k) => !in_array($k, $exclude) && !str_ends_with($k, '_id') && !str_ends_with($k, '_data') && !str_ends_with($k, '_path')
                ));
                $columns = array_slice($columns, 0, 6);
            }
        }

        $modules = collect($registry)->pluck('module')->unique()->sort()->values()->toArray();

        return Inertia::render('Core/SystemAdmin/Restoration/Index', [
            'summary'      => $summary,
            'totalDeleted' => $totalDeleted,
            'records'      => $records,
            'meta'         => $meta,
            'columns'      => $columns,
            'registryKeys' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v['label'], 'module' => $v['module']], array_keys($registry), $registry),
            'modules'      => $modules,
            'filters'      => $request->only(['model', 'search', 'module']),
        ]);
    }

    public function restore(Request $request, string $modelKey, int $id): RedirectResponse
    {
        $cfg    = $this->resolveModel($modelKey);
        $record = $cfg['class']::onlyTrashed()->findOrFail($id);
        $record->restore();

        activity()->causedBy(auth()->user())->performedOn($record)
            ->withProperties(['model' => $modelKey, 'id' => $id, 'label' => $this->labelFor($record, $cfg['searchable'])])
            ->log('system_restoration.restored');

        return redirect()->back()->with('success', "{$cfg['label']} restored successfully.");
    }

    public function forceDelete(Request $request, string $modelKey, int $id): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|min:5|max:500']);
        $cfg    = $this->resolveModel($modelKey);
        $record = $cfg['class']::onlyTrashed()->findOrFail($id);
        $label  = $this->labelFor($record, $cfg['searchable']);

        activity()->causedBy(auth()->user())
            ->withProperties(['model' => $modelKey, 'id' => $id, 'label' => $label, 'reason' => $request->reason, 'data' => $record->toArray()])
            ->log('system_restoration.force_deleted');

        $record->forceDelete();
        return redirect()->back()->with('success', "{$cfg['label']} permanently deleted.");
    }

    public function bulkRestore(Request $request): RedirectResponse
    {
        $request->validate(['model_key' => 'required|string', 'ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        $cfg     = $this->resolveModel($request->model_key);
        $records = $cfg['class']::onlyTrashed()->whereIn('id', $request->ids)->get();
        $count   = 0;
        foreach ($records as $record) { $record->restore(); $count++; }

        activity()->causedBy(auth()->user())
            ->withProperties(['model' => $request->model_key, 'ids' => $request->ids, 'count' => $count])
            ->log('system_restoration.bulk_restored');

        return redirect()->back()->with('success', "{$count} records restored successfully.");
    }

    public function bulkForceDelete(Request $request): RedirectResponse
    {
        $request->validate(['model_key' => 'required|string', 'ids' => 'required|array|min:1', 'ids.*' => 'integer', 'reason' => 'required|string|min:5|max:500']);
        $cfg      = $this->resolveModel($request->model_key);
        $records  = $cfg['class']::onlyTrashed()->whereIn('id', $request->ids)->get();
        $count    = 0;
        $snapshot = [];
        foreach ($records as $record) {
            $snapshot[] = ['id' => $record->getKey(), 'label' => $this->labelFor($record, $cfg['searchable'])];
            $record->forceDelete();
            $count++;
        }

        activity()->causedBy(auth()->user())
            ->withProperties(['model' => $request->model_key, 'count' => $count, 'reason' => $request->reason, 'records' => $snapshot])
            ->log('system_restoration.bulk_force_deleted');

        return redirect()->back()->with('success', "{$count} records permanently deleted.");
    }

    private function resolveModel(string $key): array
    {
        $registry = $this->activeRegistry();
        if (!isset($registry[$key])) abort(422, "Unknown model key: {$key}");
        return $registry[$key];
    }

    private function labelFor(mixed $record, array $searchable): string
    {
        foreach ($searchable as $col) {
            $val = $record->$col ?? null;
            // A searchable column may be cast to an enum (e.g. PayslipStatus) — resolve it
            // to a scalar rather than letting a (string) cast on the object blow up.
            if ($val instanceof \BackedEnum) {
                $val = $val->value;
            } elseif ($val instanceof \UnitEnum) {
                $val = $val->name;
            }
            if (is_array($val) || is_object($val)) {
                continue;
            }
            if (! empty($val)) {
                return (string) $val;
            }
        }
        return 'ID ' . $record->getKey();
    }
}
