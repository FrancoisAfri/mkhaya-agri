<?php

namespace App\Core\AI\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class ERPContextBuilder
{
    /**
     * Build a comprehensive context snapshot from the database
     * that the AI can use to answer questions accurately.
     */
    public function buildContext(string $question): array
    {
        $intent = $this->classifyIntent($question);
        $accessible = $this->accessibleModuleCodes();
        $data = $this->gatherData($intent, $question, $accessible);

        return [
            'intent'     => $intent,
            'data'       => $data,
            'schema'     => $this->getRelevantSchema($intent),
            'timestamp'  => now()->toISOString(),
            'user_role'  => optional(Auth::user())->roles?->first()?->name ?? 'employee',
            // Modules this user may be told about (active for the company + granted to
            // the user's role). The system prompt uses this so the AI never answers
            // about a module the user can't access.
            'accessible_modules' => $accessible,
        ];
    }

    /**
     * The module code_names the current user may be shown data for: everything active
     * for platform/master admins, otherwise the modules granted to this user (which,
     * under RLS, are already the company's active modules + the user's role/permission
     * grants). Drives both intent-gating and the system prompt's guardrail.
     *
     * @return array<int, string>
     */
    private function accessibleModuleCodes(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        if (($user->is_platform_admin ?? false) || ($user->is_master_admin ?? false)) {
            return DB::table('security_modules')->where('active', 1)->pluck('code_name')->all();
        }

        return DB::table('security_module_access')
            ->join('security_modules', 'security_module_access.module_id', '=', 'security_modules.id')
            ->where('security_module_access.user_id', $user->id)
            ->where('security_module_access.active', 1)
            ->where('security_modules.active', 1)
            ->pluck('security_modules.code_name')
            ->all();
    }

    /** Intent → the module code_name that must be accessible to gather its data. */
    private function intentModuleMap(): array
    {
        return [
            'employee'    => 'user_management',
            'leave'       => 'leave_management',
            'attendance'  => 'time_attendance',
            'payroll'     => 'payroll',
            'recruitment' => 'recruitment',
            'fleet'       => 'fleet_management',
            'asset'       => 'asset_management',
            'policy'      => 'popia_compliance',
            'performance' => 'recognition',
            'manufacturing' => 'manufacturing',
            // 'general' has no module gate — it's a basic company overview.
        ];
    }

    /**
     * Classify the user's question into ERP domains
     */
    private function classifyIntent(string $question): array
    {
        $q = strtolower($question);
        $intents = [];

        $intentMap = [
            'employee' => [
                'employee', 'staff', 'worker', 'people', 'headcount',
                'team', 'department', 'hired', 'joined', 'resigned',
                'terminated', 'workforce', 'personnel',
            ],
            'leave' => [
                'leave', 'absence', 'absent', 'vacation', 'sick',
                'annual leave', 'time off', 'pto', 'maternity',
                'paternity', 'off work',
            ],
            'attendance' => [
                'attendance', 'late', 'lateness', 'clock in', 'clock out',
                'present', 'absent', 'punctual', 'overtime', 'hours worked',
                'time sheet', 'shift',
            ],
            'payroll' => [
                'payroll', 'salary', 'wage', 'pay', 'compensation',
                'bonus', 'deduction', 'payslip', 'net pay', 'gross',
                'tax', 'cost to company', 'ctc', 'earnings',
            ],
            'recruitment' => [
                'recruit', 'hiring', 'candidate', 'applicant', 'vacancy',
                'job opening', 'interview', 'application', 'cv', 'resume',
                'position', 'offer',
            ],
            'fleet' => [
                'fleet', 'vehicle', 'car', 'truck', 'driver',
                'fuel', 'mileage', 'route', 'maintenance', 'accident',
                'incident', 'license',
            ],
            'asset' => [
                'asset', 'equipment', 'laptop', 'device', 'inventory',
                'assigned', 'returned', 'warranty', 'hardware',
            ],
            'policy' => [
                'policy', 'policies', 'clause', 'clauses', 'compliance',
                'governance', 'guideline', 'procedure', 'rule', 'exception',
                'violation',
            ],
            'performance' => [
                'performance', 'review', 'rating', 'kpi', 'goal',
                'appraisal', 'evaluation', 'feedback',
            ],
            'manufacturing' => [
                'production order', 'manufactur', 'scrap', 'rework', 'downtime', 'material variance',
                'material cost variance', 'due dates', 'running out', 'run out', 'quality failure', 'margins',
                'production cost', 'work centre', 'machines',
            ],
            'general' => [
                'how many', 'total', 'count', 'list', 'show',
                'what is', 'who', 'summary', 'overview', 'report',
                'dashboard', 'status',
            ],
        ];

        foreach ($intentMap as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($q, $keyword)) {
                    $intents[] = $intent;
                    break;
                }
            }
        }

        return array_unique($intents) ?: ['general'];
    }

    /**
     * Gather real data from the database based on detected intents
     */
    private function gatherData(array $intents, string $question, array $accessibleModules = []): array
{
    $data = [];
    $map = $this->intentModuleMap();
    $skipped = [];

    foreach ($intents as $intent) {
        // Scope by access: skip an intent when its module isn't available to the user
        // (company doesn't have it, or the role can't reach it). 'general' is ungated.
        $module = $map[$intent] ?? null;
        if ($module !== null && ! in_array($module, $accessibleModules, true)) {
            $skipped[] = $intent;
            continue;
        }

        // Never let one module's broken/absent query crash the whole assistant — a
        // failing intent degrades to "no data" and the AI answers from what remains.
        try {
            $data[$intent] = match ($intent) {
                'employee'    => $this->getEmployeeData($question),
                'leave'       => $this->getLeaveData($question),
                'attendance'  => $this->getAttendanceData($question),
                'payroll'     => $this->getPayrollData($question),
                'recruitment' => $this->getRecruitmentData($question),
                'fleet'       => $this->getFleetData($question),
                'asset'       => $this->getAssetData($question),
                'policy'      => $this->getPolicyData($question),
                'performance' => $this->getPerformanceData($question),
                'manufacturing' => $this->getManufacturingData($question),
                'general'     => $this->getGeneralOverview(),
                default       => [],
            };
        } catch (\Throwable $e) {
            Log::warning("AI context gather failed for intent [{$intent}]: {$e->getMessage()}");
            $data[$intent] = [];
        }
    }

    // Tell the model which requested topics were withheld for access reasons, so it can
    // say so plainly instead of inventing an answer.
    if ($skipped) {
        $data['_access_denied'] = array_values($skipped);
    }

    // Add analytics for comprehensive asset questions (only if asset data was gathered).
    if (isset($data['asset']) && (
        str_contains(strtolower($question), 'analytics') ||
        str_contains(strtolower($question), 'overview') ||
        str_contains(strtolower($question), 'summary')
    )) {
        try {
            $data['asset_analytics'] = $this->getAssetAnalytics();
        } catch (\Throwable $e) {
            Log::warning("AI asset analytics failed: {$e->getMessage()}");
        }
    }

    return $data;
}

    // ═══════════════════════════════════════
    // DATA GATHERING METHODS
    // ═══════════════════════════════════════
private function getEmployeeData(string $question): array
{
    $question = strtolower($question);

    $data = [
        'total_active' => DB::table('hr_people')->where('status', 1)->count(),
        'total_inactive' => DB::table('hr_people')->where('status', 0)->count(),

        'recent_hires' => DB::table('hr_people')
            ->where('status', 1)
            ->where('created_at', '>=', now()->subDays(30))
            ->count(),

        'recent_terminations' => DB::table('hr_people')
            ->where('status', 0)
            ->where('updated_at', '>=', now()->subDays(30))
            ->count(),
    ];

    // Gender breakdown (if 'gender' column exists)
    if (Schema::hasColumn('hr_people', 'gender')) {
        $data['by_gender'] = DB::table('hr_people')
            ->where('status', 1)
            ->select('gender', DB::raw('COUNT(*) as count'))
            ->groupBy('gender')
            ->get()
            ->toArray();
    }

    // Department breakdown – requires 'department_id' column and 'departments' table
    if (Schema::hasColumn('hr_people', 'department_id') && Schema::hasTable('departments')) {
        $data['by_department'] = DB::table('hr_people')
            ->join('departments', 'hr_people.department_id', '=', 'departments.id')
            ->where('hr_people.status', 1)
            ->select('departments.name', DB::raw('COUNT(*) as count'))
            ->groupBy('departments.name')
            ->orderByDesc('count')
            ->get()
            ->toArray();
    }

    // Specific person lookup
    if (preg_match('/about\s+(\w+\s?\w*)/i', $question, $matches)) {
        $name = trim($matches[1]);
        $employee = DB::table('hr_people')
            ->where('first_name', 'LIKE', "%{$name}%")
            ->orWhere('surname', 'LIKE', "%{$name}%")
            ->orWhere(DB::raw("CONCAT(first_name, ' ', surname)"), 'LIKE', "%{$name}%")
            ->first();

        if ($employee) {
            $data['specific_employee'] = (array) $employee;
        }
    }

    // Specific department detail
    if (preg_match('/(department|team|division)\s*[:\-]?\s*(\w+)/i', $question, $matches)) {
        $dept = trim($matches[2]);
        if (Schema::hasColumn('hr_people', 'department_id') && Schema::hasTable('departments')) {
            $data['department_detail'] = DB::table('hr_people')
                ->join('departments', 'hr_people.department_id', '=', 'departments.id')
                ->where('departments.name', 'LIKE', "%{$dept}%")
                ->where('hr_people.status', 1)
                ->select('hr_people.first_name', 'hr_people.surname', 'hr_people.email', 'hr_people.position')
                ->limit(20)
                ->get()
                ->toArray();
        }
    }

    return $data;
}



    private function getAttendanceData(string $question): array
    {
        $today = Carbon::today();

        return [
            'clock_events_today' => DB::table('attendance_clockings')
                ->whereDate('clocked_at', $today)
                ->count(),

            'employees_clocked_today' => DB::table('attendance_clockings')
                ->whereDate('clocked_at', $today)
                ->distinct()
                ->count('employee_id'),

            'missed_clockings_today' => DB::table('attendance_clockings')
                ->whereDate('clocked_at', $today)
                ->where('is_missed', true)
                ->count(),

            'clock_events_this_month' => DB::table('attendance_clockings')
                ->whereYear('clocked_at', now()->year)
                ->whereMonth('clocked_at', now()->month)
                ->count(),

            'by_type_this_month' => DB::table('attendance_clockings')
                ->whereYear('clocked_at', now()->year)
                ->whereMonth('clocked_at', now()->month)
                ->select('type', DB::raw('COUNT(*) as count'))
                ->groupBy('type')
                ->get()
                ->toArray(),
        ];
    }

    private function getPayrollData(string $question): array
    {
        $data = [
            'last_payroll' => DB::table('payroll_runs')
                ->orderByDesc('period_end')
                ->first(['name', 'period_start', 'period_end', 'status', 'total_gross', 'total_net', 'total_deductions', 'employee_count']),

            'this_month_totals' => DB::table('payslips')
                ->where('period_end', '>=', now()->startOfMonth())
                ->select(
                    DB::raw('SUM(gross_pay) as total_gross'),
                    DB::raw('SUM(net_pay) as total_net'),
                    DB::raw('SUM(total_deductions) as total_deductions'),
                    DB::raw('COUNT(*) as payslip_count')
                )
                ->first(),

            'cost_trend_6m' => DB::table('payroll_runs')
                ->where('period_end', '>=', now()->subMonths(6))
                ->select('period_end', 'total_gross', 'total_net', 'total_deductions')
                ->orderBy('period_end')
                ->get()
                ->toArray(),
        ];

        // Highest earners on the latest run (join to hr_people for names when possible).
        $earners = DB::table('payslips')->orderByDesc('period_end')->orderByDesc('gross_pay')->limit(10);
        if (Schema::hasTable('hr_people')) {
            $data['highest_earners'] = $earners
                ->leftJoin('hr_people', 'payslips.employee_id', '=', 'hr_people.id')
                ->select('hr_people.first_name', 'hr_people.surname', 'payslips.gross_pay', 'payslips.net_pay')
                ->get()->toArray();
        } else {
            $data['highest_earners'] = $earners->select('employee_id', 'gross_pay', 'net_pay')->get()->toArray();
        }

        return $data;
    }

    private function getRecruitmentData(string $question): array
    {
        $data = [
            'open_vacancies' => DB::table('recruitment_vacancies')
                ->where('status', 'open')
                ->count(),

            'open_positions' => DB::table('recruitment_vacancies')
                ->where('status', 'open')
                ->select('title', 'department', 'headcount', 'job_type', 'created_at', 'expires_at')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->toArray(),

            'by_status' => DB::table('recruitment_vacancies')
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get()
                ->toArray(),

            'recent_vacancies' => DB::table('recruitment_vacancies')
                ->orderByDesc('created_at')
                ->limit(10)
                ->select('title', 'department', 'status', 'created_at')
                ->get()
                ->toArray(),
        ];

        // Applications pipeline — only if the table exists in this deployment.
        if (Schema::hasTable('recruitment_applications')) {
            $data['applications_this_month'] = DB::table('recruitment_applications')
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();

            if (Schema::hasColumn('recruitment_applications', 'status')) {
                $data['pipeline_summary'] = DB::table('recruitment_applications')
                    ->select('status', DB::raw('COUNT(*) as count'))
                    ->groupBy('status')
                    ->get()
                    ->toArray();
            }
        }

        return $data;
    }

    private function getFleetData(string $question): array
    {
        $data = [
            'total_vehicles' => DB::table('fleet_vehicles')->count(),

            'by_status' => DB::table('fleet_vehicles')
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get()
                ->toArray(),

            'by_type' => DB::table('fleet_vehicles')
                ->whereNotNull('vehicle_type')
                ->select('vehicle_type', DB::raw('COUNT(*) as count'))
                ->groupBy('vehicle_type')
                ->orderByDesc('count')
                ->limit(15)
                ->get()
                ->toArray(),

            'total_fleet_value' => DB::table('fleet_vehicles')->sum('current_value'),

            'disposal_due' => DB::table('fleet_vehicles')
                ->whereNotNull('expected_disposal_date')
                ->where('expected_disposal_date', '<=', now()->addDays(90)->toDateString())
                ->select('registration_number', 'make', 'model', 'expected_disposal_date')
                ->orderBy('expected_disposal_date')
                ->limit(10)
                ->get()
                ->toArray(),
        ];

        // Fuel intelligence — only if a fuel-transactions table is present.
        if (Schema::hasTable('fleet_fuel_transactions')) {
            $data['fuel_summary_month'] = DB::table('fleet_fuel_transactions')
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();
        }

        return $data;
    }



    /**
     * Manufacturing answers come from the module's own assistant (MFG-019): figures computed from
     * records, already limited to what this user's permissions cover, with their period, sources
     * and data warnings. The model is to word them, not to add to them.
     */
    private function getManufacturingData(string $question): array
    {
        $user = auth()->user();
        if (! $user || ! class_exists(\App\Modules\Operations\Manufacturing\Services\ManufacturingAssistant::class)) {
            return [];
        }
        $a = app(\App\Modules\Operations\Manufacturing\Services\ManufacturingAssistant::class)->answer($question, $user);

        return [
            'instruction' => 'Use only these figures. Keep actual, forecast, estimate and recommendation apart, state the period, and repeat every data warning.',
            'question_answered' => $a['title'], 'permitted' => ! $a['refused'], 'period' => $a['period'], 'summary' => $a['summary'],
            'rows' => array_map(fn ($r) => array_diff_key($r, array_flip(['url', 'item_id', 'order_id', 'centre_id', 'supplier_id', 'stock_item_id', 'short'])), array_slice($a['rows'], 0, 10)),
            'data_warnings' => $a['flags'], 'sources' => $a['sources'], 'recommendations' => array_column($a['recommendations'], 'text'),
            'more' => 'The Manufacturing assistant screen shows the records behind each figure and can draft tasks from the recommendations.',
        ];
    }

    private function getPerformanceData(string $question): array
    {
        // The live "performance"-flavoured module in this product is Recognition &
        // Rewards (pa_recognitions). Legacy performance_reviews tables aren't present.
        if (! Schema::hasTable('pa_recognitions')) {
            return [];
        }

        return [
            'recognitions_total' => DB::table('pa_recognitions')->count(),

            'recognitions_this_month' => DB::table('pa_recognitions')
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count(),

            'top_badges' => DB::table('pa_recognitions')
                ->whereNotNull('badge')
                ->select('badge', DB::raw('COUNT(*) as count'))
                ->groupBy('badge')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->toArray(),

            'top_value_tags' => DB::table('pa_recognitions')
                ->whereNotNull('value_tag')
                ->select('value_tag', DB::raw('COUNT(*) as count'))
                ->groupBy('value_tag')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->toArray(),
        ];
    }

private function getGeneralOverview(): array
{
    return [
        'total_employees' => DB::table('hr_people')->count(),
        'active'          => DB::table('hr_people')->where('status', 1)->count(),
        'inactive'        => DB::table('hr_people')->where('status', 0)->count(),
        // If you need by gender
        'by_gender'       => DB::table('hr_people')
            ->select('gender', DB::raw('count(*) as total'))
            ->whereNotNull('gender')
            ->groupBy('gender')
            ->get(),
        // Recent hires (created in last 30 days)
        'recent_hires'    => DB::table('hr_people')
            ->where('status', 1)
            ->where('created_at', '>=', now()->subDays(30))
            ->count(),
    ];
}
    private function calculateAttendanceRate(int $days): float
    {
        $totalExpected = DB::table('hr_people')->where('status', 1)->count() * $days;
        $totalPresent = DB::table('attendances')
            ->where('date', '>=', now()->subDays($days))
            ->whereNotNull('clock_in')
            ->count();

        return $totalExpected > 0 ? round(($totalPresent / $totalExpected) * 100, 1) : 0;
    }

    /**
     * Get relevant schema information for the AI to understand data structure
     */
    private function getRelevantSchema(array $intents): array
    {
        $schemas = [
            'employee' => [
                'table' => 'hr_people',
                'columns' => 'id, first_name, surname, email, department_id, position, status, gender, created_at as hire_date, updated_at as termination_date',
            ],
            'leave' => [
                'tables' => [
                    'leave_applications' => [
                        'columns' => 'id, hr_id, leave_type_id, start_date, end_date, leave_days, leave_hours, status, notes, supporting_docs, manager_id, reject_reason, created_at, updated_at',
                        'notes' => 'status: 0=Pending,1=Approved,2=Rejected,3=Cancelled; start_date/end_date are Unix timestamps',
                    ],
                    'leave_types' => [
                        'columns' => 'id, name, status, description, special_leave, doc_required, notes_required',
                    ],
                    'leave_credit' => [
                        'columns' => 'id, hr_id, leave_type_id, leave_balance',
                    ],
                    'leave_configuration' => [
                        'columns' => 'id, allow_annual_leave_credit, require_managers_approval, number_of_days_until_escalation, ...',
                    ],
                    'leave_history' => [
                        'columns' => 'id, hr_id, action, transcation, previous_balance, current_balance, action_date',
                    ],
                ],
            ],

            'asset' => [
                'tables' => [
                    'assets' => [
                        'columns' => 'id, uuid, name, user_id, description, serial_number, asset_tag, model_number, make_number, asset_type_id, license_type_id, picture, price, status, asset_status, created_at, updated_at',
                        'notes' => 'asset_status: available, assigned, maintenance, retired; status: 1=active, 0=inactive'
                    ],
                    'asset_type' => [
                        'columns' => 'id, name, description, status',
                        'notes' => 'Categories of assets like Laptops, Monitors, Software Licenses'
                    ],
                    'asset_licenses' => [
                        'columns' => 'id, uuid, name, details, serial, purchase_date, purchase_cost, total, notes, user_id, asset_type_id, expiration_date, licence_image, status, licence_status',
                        'notes' => 'licence_status: allocated, un_allocated'
                    ],
                    'asset_log' => [
                        'columns' => 'id, uuid, user_id, action_type, asset_id, checkedout_to, store_id, status, created_at',
                        'notes' => 'action_type: checkout, return, transfer, maintenance'
                    ],
                    'asset_components' => [
                        'columns' => 'id, uuid, name, description, size, asset_id, status',
                        'notes' => 'Parts or components belonging to an asset (e.g., RAM, SSD)'
                    ],
                    'license_allocation' => [
                        'columns' => 'id, uuid, name, user_id, licence_id, division_level_1-5',
                        'notes' => 'Tracks license assignments to users and departments'
                    ],
                ]
],

        ];

        $relevant = [];
        foreach ($intents as $intent) {
            if (isset($schemas[$intent])) {
                $relevant[$intent] = $schemas[$intent];
            }
        }

        return $relevant;
    }

    /**
     * Gather comprehensive leave data for AI context.
     * Merges legacy leave_applications data with new leave module intelligence,
     * gated by the user's leave.ai.* Spatie permissions.
     */
    private function getLeaveData(string $question): array
    {
        $today  = Carbon::today();
        $userId = Auth::id();
        $driver = DB::connection()->getDriverName();

        $data = [];

        // ── Legacy leave_applications (kept for backwards compatibility) ───────
        try {
            $data['on_leave_today'] = DB::table('leave_applications')
                ->where('status', 1)
                ->where('start_date', '<=', $today->timestamp)
                ->where('end_date', '>=', $today->timestamp)
                ->count();

            $data['pending_requests'] = DB::table('leave_applications')
                ->where('status', 0)
                ->count();

            $data['upcoming_leave'] = DB::table('leave_applications')
                ->join('hr_people', 'leave_applications.hr_id', '=', 'hr_people.id')
                ->where('leave_applications.status', 1)
                ->where('leave_applications.start_date', '>', $today->timestamp)
                ->where('leave_applications.start_date', '<=', $today->copy()->addDays(14)->timestamp)
                ->select('hr_people.first_name', 'hr_people.surname', 'leave_applications.start_date', 'leave_applications.end_date', 'leave_applications.leave_days')
                ->orderBy('leave_applications.start_date')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    $item->start_date = Carbon::createFromTimestamp($item->start_date)->toDateString();
                    $item->end_date   = Carbon::createFromTimestamp($item->end_date)->toDateString();
                    return $item;
                })
                ->toArray();

            if (Str::contains(strtolower($question), ['my balance', 'my leave', 'my days'])) {
                $data['my_balances_legacy'] = $this->getLeaveBalancesForUser($userId);
            }
        } catch (\Throwable) {
            // Legacy table not available — new module only
        }

        // ── New leave module intelligence (role-gated) ────────────────────────
        try {
            $intelligence = app(LeaveIntelligenceContextProvider::class)->build($question);
            if (! empty($intelligence)) {
                $data['intelligence'] = $intelligence;
            }
        } catch (\Throwable) {
            // New tables not yet migrated — graceful fallback
        }

        return $data;
    }

    /**
     * Get the leave balances for the currently authenticated user.
     *
     * @return array
     */
    public function getMyLeaveDays(): array
    {
        $userId = Auth::id(); // Assuming your users table has an 'id' that links to 'hr_people.id'
        // If the logged-in user's ID is not the same as 'hr_people.id', adjust the lookup.
        $employee = DB::table('hr_people')->where('user_id', $userId)->first();
        if (!$employee) {
            return ['error' => 'No employee record found for the logged-in user.'];
        }

        return $this->getLeaveBalancesForUser($employee->id);
    }

    /**
     * Get leave balances for a specific user (hr_id)
     */
    private function getLeaveBalancesForUser(int $hrId): array
    {
        $balances = DB::table('leave_credit')
            ->join('leave_types', 'leave_credit.leave_type_id', '=', 'leave_types.id')
            ->where('leave_credit.hr_id', $hrId)
            ->select('leave_types.name', 'leave_credit.leave_balance')
            ->get()
            ->toArray();

        // Also include pending leave that would affect balance (optional)
        $pendingDeductions = DB::table('leave_applications')
            ->where('hr_id', $hrId)
            ->where('status', 0) // Pending
            ->where('start_date', '>=', now()->timestamp)
            ->sum('leave_days');

        return [
            'current_balances' => $balances,
            'pending_days_requested' => $pendingDeductions,
        ];
    }

    private function getAssetData(string $question): array
{
    $question = strtolower($question);

    // Base asset statistics
    $data = [
        'total' => DB::table('assets')->count(),
        'assigned' => DB::table('assets')->where('asset_status', 'assigned')->count(),
        'available' => DB::table('assets')->where('asset_status', 'available')->count(),
        'under_maintenance' => DB::table('assets')->where('asset_status', 'maintenance')->count(),
        'retired' => DB::table('assets')->where('asset_status', 'retired')->count(),

        // Asset Types breakdown
        'by_asset_type' => DB::table('assets')
            ->join('asset_type', 'assets.asset_type_id', '=', 'asset_type.id')
            ->select('asset_type.name', DB::raw('COUNT(*) as count'))
            ->groupBy('asset_type.name')
            ->orderByDesc('count')
            ->get()
            ->toArray(),

        // License types breakdown
        'by_license_type' => DB::table('assets')
            ->join('asset_licenses', 'assets.license_type_id', '=', 'asset_licenses.id')
            ->select('asset_licenses.name', DB::raw('COUNT(*) as count'))
            ->groupBy('asset_licenses.name')
            ->get()
            ->toArray(),

        // Recent assets added (last 30 days)
        'recently_added' => DB::table('assets')
            ->where('created_at', '>=', now()->subDays(30))
            ->select('name', 'asset_tag', 'serial_number', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->toArray(),

        // Expiring licenses (next 30 days)
        'licenses_expiring_soon' => DB::table('asset_licenses')
            ->where('expiration_date', '>=', now()->toDateString())
            ->where('expiration_date', '<=', now()->addDays(30)->toDateString())
            ->where('status', 1)
            ->select('name', 'serial', 'expiration_date', 'total', 'licence_status')
            ->orderBy('expiration_date')
            ->limit(10)
            ->get()
            ->toArray(),

        // Assets with expired licenses
        'assets_with_expired_licenses' => DB::table('assets')
            ->join('asset_licenses', 'assets.license_type_id', '=', 'asset_licenses.id')
            ->where('asset_licenses.expiration_date', '<', now()->toDateString())
            ->where('asset_licenses.status', 1)
            ->select('assets.name', 'assets.asset_tag', 'asset_licenses.name as license_name', 'asset_licenses.expiration_date')
            ->limit(20)
            ->get()
            ->toArray(),
    ];

    // Asset value analysis
    $data['total_value'] = DB::table('assets')->sum('price');
    $data['average_asset_value'] = DB::table('assets')->avg('price');

    // High-value assets
    $data['high_value_assets'] = DB::table('assets')
        ->where('price', '>', 5000)
        ->select('name', 'asset_tag', 'price', 'asset_status')
        ->orderByDesc('price')
        ->limit(10)
        ->get()
        ->toArray();

    // Assets by status with user assignments
    if (Schema::hasTable('hr_people')) {
        $data['assigned_to_users'] = DB::table('assets')
            ->join('hr_people', 'assets.user_id', '=', 'hr_people.id')
            ->whereNotNull('assets.user_id')
            ->where('assets.asset_status', 'assigned')
            ->select('assets.name', 'assets.asset_tag', 'hr_people.first_name', 'hr_people.surname')
            ->limit(10)
            ->get()
            ->toArray();
    }

    // Check for specific asset queries
    if (preg_match('/asset\s+(?:tag\s*[:\-]?\s*)?([A-Z0-9-]+)/i', $question, $matches)) {
        $assetTag = trim($matches[1]);
        $asset = DB::table('assets')
            ->leftJoin('asset_type', 'assets.asset_type_id', '=', 'asset_type.id')
            ->leftJoin('hr_people', 'assets.user_id', '=', 'hr_people.id')
            ->where('assets.asset_tag', $assetTag)
            ->select(
                'assets.*',
                'asset_type.name as asset_type_name',
                'hr_people.first_name as assigned_first',
                'hr_people.surname as assigned_last'
            )
            ->first();

        if ($asset) {
            $data['specific_asset'] = (array) $asset;

            // Get license info if applicable
            if ($asset->license_type_id) {
                $data['specific_asset']['license'] = DB::table('asset_licenses')
                    ->where('id', $asset->license_type_id)
                    ->first();
            }

            // Get asset log history
            $data['specific_asset']['history'] = DB::table('asset_log')
                ->where('asset_id', $asset->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->toArray();
        }
    }

    // Check for assets by type question
    if (preg_match('/(?:all|the)?\s*assets?\s+in\s+(?:type\s*)?[\'"]?(\w+)[\'"]?/i', $question, $matches)) {
        $typeName = trim($matches[1]);
        $data['assets_by_type'] = DB::table('assets')
            ->join('asset_type', 'assets.asset_type_id', '=', 'asset_type.id')
            ->where('asset_type.name', 'LIKE', "%{$typeName}%")
            ->select('assets.name', 'assets.asset_tag', 'assets.serial_number', 'assets.asset_status')
            ->limit(20)
            ->get()
            ->toArray();
    }

    // Check for unassigned assets
    if (str_contains($question, 'unassigned') || str_contains($question, 'not assigned')) {
        $data['unassigned_assets'] = DB::table('assets')
            ->whereNull('user_id')
            ->where('asset_status', 'available')
            ->select('name', 'asset_tag', 'serial_number', 'model_number')
            ->limit(20)
            ->get()
            ->toArray();
    }

    // Asset log activity (last 7 days)
    $data['recent_activity'] = DB::table('asset_log')
        ->where('created_at', '>=', now()->subDays(7))
        ->select('action_type', 'asset_id', 'user_id', 'status', 'created_at')
        ->orderByDesc('created_at')
        ->limit(20)
        ->get()
        ->map(function ($log) {
            $log->asset_name = DB::table('assets')->where('id', $log->asset_id)->value('name');
            return $log;
        })
        ->toArray();

    // Components summary
    if (Schema::hasTable('asset_components')) {
        // ✅ Fixed for PostgreSQL
        $data['components_summary'] = [
            'total_components' => DB::table('asset_components')->count(),
            'average_per_asset' => DB::table(DB::raw('(
        SELECT asset_id, COUNT(*) as component_count
        FROM asset_components
        GROUP BY asset_id
    ) as sub'))
                ->avg('component_count'),
        ];
    }

    // Files/documents attached to assets
    if (Schema::hasTable('asset_files')) {
        $data['files_count'] = DB::table('asset_files')->count();
        $data['assets_with_files'] = DB::table('asset_files')
            ->whereNotNull('document')
            ->count();
    }

    return $data;
}

/**
 * Get detailed asset analytics for complex questions
 */
public function getAssetAnalytics(): array
{
    return [
        'by_status' => DB::table('assets')
            ->select('asset_status', DB::raw('COUNT(*) as count'))
            ->groupBy('asset_status')
            ->get()
            ->toArray(),

        'by_month_added' => DB::table('assets')
            ->select(
                DB::raw('EXTRACT(YEAR FROM created_at) as year'),
                DB::raw('EXTRACT(MONTH FROM created_at) as month'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get()
            ->toArray(),

        'value_distribution' => [
            'under_1000' => DB::table('assets')->where('price', '<', 1000)->count(),
            '1000_to_5000' => DB::table('assets')->whereBetween('price', [1000, 5000])->count(),
            '5000_to_10000' => DB::table('assets')->whereBetween('price', [5000, 10000])->count(),
            'over_10000' => DB::table('assets')->where('price', '>', 10000)->count(),
        ],

        'top_assets_by_component_count' => DB::table('asset_components')
            ->join('assets', 'asset_components.asset_id', '=', 'assets.id')
            ->select('assets.name', 'assets.asset_tag', DB::raw('COUNT(*) as component_count'))
            ->groupBy('assets.id', 'assets.name', 'assets.asset_tag')
            ->orderByDesc('component_count')
            ->limit(10)
            ->get()
            ->toArray(),

        'license_utilization' => [
            'total_licenses' => DB::table('asset_licenses')->sum('total'),
            'allocated_licenses' => DB::table('license_allocation')->count(),
            'available_licenses' => DB::table('asset_licenses')->sum('total') - DB::table('license_allocation')->count(),
        ],

        'depreciating_assets' => DB::table('asset_depreciations')
            ->join('assets', 'asset_depreciations.asset_id', '=', 'assets.id')
            ->where('asset_depreciations.balance_amount', '>', 0)
            ->select('assets.name', 'assets.asset_tag', 'asset_depreciations.initial_amount', 'asset_depreciations.balance_amount', 'asset_depreciations.years')
            ->limit(15)
            ->get()
            ->toArray(),
    ];
}

private function getPolicyData(string $question): array
{
    $questionLower = strtolower($question);

    $data = [
        'counts' => [
            'total_policies' => DB::table('policy')->count(),
            'active_policies' => DB::table('policy')->where('status', 'active')->count(),
            'policy_documents' => Schema::hasTable('policy_documents') ? DB::table('policy_documents')->count() : 0,
            'policy_clauses' => Schema::hasTable('policy_clauses') ? DB::table('policy_clauses')->count() : 0,
            'policy_rules' => Schema::hasTable('policy_rules') ? DB::table('policy_rules')->count() : 0,
        ],
        'latest_policies' => DB::table('policy')
            ->select('id', 'uuid', 'name', 'status', 'effective_date', 'review_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->toArray(),
    ];

    if (Schema::hasTable('policy_documents')) {
        $data['document_text_snippets'] = DB::table('policy_documents as d')
            ->join('policy as p', 'p.id', '=', 'd.policy_id')
            ->whereNotNull('d.extracted_text')
            ->select('p.uuid', 'p.name', 'd.id as document_id', 'd.filename', 'd.extracted_at', 'd.extracted_text')
            ->orderByDesc('d.id')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'policy_uuid' => $row->uuid,
                'policy_name' => $row->name,
                'document_id' => $row->document_id,
                'filename' => $row->filename,
                'extracted_at' => $row->extracted_at,
                'text_excerpt' => Str::limit((string) ($row->extracted_text ?? ''), 1600),
            ])
            ->toArray();
    }

    if (Schema::hasTable('policy_clauses')) {
        $data['recent_clauses'] = DB::table('policy_clauses as c')
            ->join('policy as p', 'p.id', '=', 'c.policy_id')
            ->select('p.uuid', 'p.name as policy_name', 'c.clause_reference', 'c.type', 'c.content')
            ->orderByDesc('c.id')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'policy_uuid' => $row->uuid,
                'policy_name' => $row->policy_name,
                'clause_reference' => $row->clause_reference,
                'type' => $row->type,
                'content' => Str::limit((string) ($row->content ?? ''), 400),
            ])
            ->toArray();
    }

    // Lightweight targeted search if a policy name appears in the question.
    if (preg_match('/policy\s+(?:named|called)?\s*([a-z0-9\-\s]+)/i', $questionLower, $matches)) {
        $term = trim($matches[1]);
        if ($term !== '') {
            $data['policy_matches'] = DB::table('policy')
                ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($term) . '%'])
                ->select('id', 'uuid', 'name', 'description', 'status', 'effective_date', 'review_date')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->toArray();
        }
    }

    return $data;
}

    /**
     * Helper: Calculate attendance rate (already in your class, but ensure it's present)
     * Keep your existing calculateAttendanceRate() method.
     */
}
