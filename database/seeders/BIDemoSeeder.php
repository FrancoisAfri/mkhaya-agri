<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Generates 12 months of realistic demo data for all BI Analytics modules:
 * Assets, HelpDesk, Recruitment, Performance Appraisal,
 * Leave Management, Time & Attendance, Policies.
 *
 * Run: php artisan db:seed --class=BIDemoSeeder
 */
class BIDemoSeeder extends Seeder
{
    private array $userIds     = [];
    private array $personIds   = [];
    private array $deptIds     = [];
    private array $people      = [];   // [{id, user_id, first_name, surname}]

    public function run(): void
    {
        $this->loadPeople();
        $this->seedDepartments();
        $this->seedPivots();

        $this->seedLeave();
        $this->seedAttendance();
        $this->seedPolicies();
        $this->seedAssets();
        $this->seedHelpDesk();
        $this->seedRecruitment();
        $this->seedPerformanceAppraisal();

        $this->command->info('BIDemoSeeder complete — all modules seeded.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Bootstrap
    // ──────────────────────────────────────────────────────────────────────────

    private function loadPeople(): void
    {
        $this->userIds  = DB::table('users')->pluck('id')->toArray();
        $this->people   = DB::table('hr_people')
            ->select('id', 'user_id', 'first_name', 'surname')
            ->get()->toArray();
        $this->personIds = array_column($this->people, 'id');
        $this->command->info('Loaded ' . count($this->personIds) . ' people.');
    }

    private function seedDepartments(): void
    {
        if (DB::table('company_units')->where('type', 'department')->exists()) {
            $this->deptIds = DB::table('company_units')->where('type', 'department')->pluck('id')->toArray();
            $this->command->info('Using ' . count($this->deptIds) . ' existing departments.');
            return;
        }

        $depts = ['Finance', 'Human Resources', 'IT & Systems', 'Operations', 'Marketing', 'Legal & Compliance', 'Executive'];
        foreach ($depts as $name) {
            $this->deptIds[] = DB::table('company_units')->insertGetId([
                'name'       => $name,
                'type'       => 'department',
                'company_id' => 1,
                'active'     => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->command->info('Created ' . count($this->deptIds) . ' departments.');
    }

    private function seedPivots(): void
    {
        if (DB::table('company_unit_user_pivots')->exists()) {
            $this->command->info('Department pivots already exist — skipping.');
            return;
        }
        foreach ($this->userIds as $i => $uid) {
            $deptId = $this->deptIds[$i % count($this->deptIds)];
            // company_id in this pivot = company_units.id (the department)
            DB::table('company_unit_user_pivots')->insert([
                'user_id'    => $uid,
                'company_id' => $deptId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->command->info('Created ' . count($this->userIds) . ' department pivots.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Leave Management
    // ──────────────────────────────────────────────────────────────────────────

    private function seedLeave(): void
    {
        if (DB::table('leave_applications')->exists()) {
            $this->command->info('Leave applications already exist — skipping.');
            return;
        }
        $leaveTypeIds = DB::table('leave_types')->pluck('id')->toArray();
        if (empty($leaveTypeIds)) {
            $this->command->warn('No leave_types found — skipping leave data.');
            return;
        }

        $statuses = [0, 0, 1, 1, 1, 1, 2, 3]; // weighted: mostly approved
        $count    = 0;

        foreach ($this->people as $person) {
            // leave_applications.hr_id is a foreign key onto users(id), not onto hr_people — the
            // column name says otherwise and that is exactly how this came to insert the wrong one,
            // killing the whole seed on a foreign key violation. Somebody with no login has no id to
            // put here, so they are skipped rather than guessed at.
            if (blank($person->user_id)) {
                continue;
            }

            for ($m = 11; $m >= 0; $m--) {
                $applications = rand(0, 2);
                for ($a = 0; $a < $applications; $a++) {
                    $monthStart = now()->subMonths($m)->startOfMonth();
                    $startDate  = $monthStart->copy()->addDays(rand(0, 25));
                    $endDate    = $startDate->copy()->addDays(rand(1, 5));
                    $days       = $startDate->diffInDays($endDate);
                    $status     = collect($statuses)->random();
                    $typeId     = collect($leaveTypeIds)->random();

                    DB::table('leave_applications')->insert([
                        'hr_id'         => $person->user_id,
                        'leave_type_id' => $typeId,
                        'start_date'    => $startDate->timestamp,
                        'end_date'      => $endDate->timestamp,
                        'leave_days'    => $days,
                        'leave_hours'   => $days * 8,
                        'leave_taken'   => $status === 1,
                        'status'        => $status,
                        'manager_id'    => collect($this->userIds)->random(),
                        'notes'         => $this->leaveReason(),
                        'created_at'    => $startDate->copy()->subDays(rand(3, 14)),
                        'updated_at'    => $startDate->copy()->subDays(rand(0, 2)),
                    ]);
                    $count++;
                }
            }
        }

        $this->command->info("Seeded {$count} leave applications.");
    }

    private function leaveReason(): string
    {
        return collect([
            'Family commitment', 'Medical appointment', 'Personal matters',
            'Study leave', 'Bereavement', 'Annual leave', 'Sick leave',
            'Parental duties', 'Religious observance', 'Home emergency',
        ])->random();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Time & Attendance
    // ──────────────────────────────────────────────────────────────────────────

    private function seedAttendance(): void
    {
        if (DB::table('attendance_records')->exists()) {
            $this->command->info('Attendance records already exist — skipping.');
            return;
        }
        // Ensure lookup data
        if (!DB::table('attendance_shifts')->exists()) {
            $shifts = [
                ['name' => 'Day Shift',     'code' => 'DAY',  'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_period_minutes' => 10],
                ['name' => 'Morning Shift', 'code' => 'MRN',  'start_time' => '06:00:00', 'end_time' => '14:00:00', 'grace_period_minutes' => 5],
                ['name' => 'Night Shift',   'code' => 'NGT',  'start_time' => '22:00:00', 'end_time' => '06:00:00', 'grace_period_minutes' => 10],
                ['name' => 'Flexi',         'code' => 'FLEX', 'start_time' => '07:00:00', 'end_time' => '16:00:00', 'grace_period_minutes' => 30],
            ];
            foreach ($shifts as $s) {
                DB::table('attendance_shifts')->insert(array_merge($s, [
                    'break_duration_minutes' => 60,
                    'is_flexible'            => $s['code'] === 'FLEX',
                    'is_overnight'           => $s['code'] === 'NGT',
                    'flexible_window_minutes'=> $s['code'] === 'FLEX' ? 60 : 0,
                    'status'                 => 1,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]));
            }
        }
        $shiftIds = DB::table('attendance_shifts')->pluck('id')->toArray();

        if (!DB::table('attendance_statuses')->exists()) {
            $statuses = [
                ['name' => 'Present',        'code' => 'PRESENT',   'color' => '#4CAF50', 'is_present' => true, 'is_paid' => true],
                ['name' => 'Absent',         'code' => 'ABSENT',    'color' => '#F44336', 'is_absent'  => true, 'is_paid' => false],
                ['name' => 'Late',           'code' => 'LATE',      'color' => '#FF9800', 'is_late'    => true, 'is_paid' => true, 'is_present' => true],
                ['name' => 'Half Day',       'code' => 'HALF',      'color' => '#2196F3', 'is_present' => true, 'is_paid' => true],
                ['name' => 'On Leave',       'code' => 'LEAVE',     'color' => '#9C27B0', 'is_leave'   => true, 'is_paid' => true],
                ['name' => 'Public Holiday', 'code' => 'PH',        'color' => '#607D8B', 'is_public_holiday' => true, 'is_paid' => true],
            ];
            foreach ($statuses as $s) {
                DB::table('attendance_statuses')->insert(array_merge([
                    'is_present' => false, 'is_absent' => false, 'is_late' => false,
                    'is_leave'   => false, 'is_public_holiday' => false, 'is_paid' => true,
                    'status'     => 1, 'created_at' => now(), 'updated_at' => now(),
                ], $s));
            }
        }
        $statusIds = DB::table('attendance_statuses')->pluck('id')->toArray();
        $presentStatusId = DB::table('attendance_statuses')->where('code', 'PRESENT')->value('id') ?? $statusIds[0];
        $lateStatusId    = DB::table('attendance_statuses')->where('code', 'LATE')->value('id')    ?? $statusIds[0];

        $count = 0;
        foreach ($this->personIds as $personId) {
            $shiftId = collect($shiftIds)->random();
            for ($m = 11; $m >= 0; $m--) {
                $monthStart = now()->subMonths($m)->startOfMonth();
                $daysInMonth = $monthStart->daysInMonth;

                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $date = $monthStart->copy()->day($d);
                    // skip weekends (Mon=1 … Sun=7)
                    if ($date->dayOfWeek === Carbon::SATURDAY || $date->dayOfWeek === Carbon::SUNDAY) continue;
                    // ~5% absent, ~15% late, rest present
                    $r = rand(1, 100);
                    if ($r <= 5) continue; // absent — no record

                    $isLate          = $r <= 20;
                    $lateMinutes     = $isLate ? rand(5, 90) : 0;
                    $hoursWorked     = $isLate ? round(8 - ($lateMinutes / 60), 2) : round(8 + (rand(-30, 60) / 60), 2);
                    $isEarlyDepart   = !$isLate && rand(1, 10) === 1;
                    $earlyMinutes    = $isEarlyDepart ? rand(10, 60) : 0;
                    $overtimeHours   = !$isLate && !$isEarlyDepart && rand(1, 5) === 1 ? round(rand(1, 4) + rand(0, 99) / 100, 2) : 0;
                    $managerApproved = rand(1, 10) > 2;

                    DB::table('attendance_records')->insert([
                        'employee_id'             => $personId,
                        'shift_id'                => $shiftId,
                        'status_id'               => $isLate ? $lateStatusId : $presentStatusId,
                        'date'                    => $date->toDateString(),
                        'clock_in'                => $date->format('Y-m-d') . ' ' . (sprintf('%02d', 8 + ($isLate ? 0 : 0)) . ':' . sprintf('%02d', $lateMinutes % 60) . ':00'),
                        'clock_out'               => $date->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', 16 + floor($overtimeHours), ($overtimeHours - floor($overtimeHours)) * 60),
                        'hours_worked'            => max(0, $hoursWorked),
                        'overtime_hours'          => $overtimeHours,
                        'is_late'                 => $isLate,
                        'late_minutes'            => $lateMinutes,
                        'is_early_departure'      => $isEarlyDepart,
                        'early_departure_minutes' => $earlyMinutes,
                        'source'                  => collect(['biometric', 'manual', 'mobile'])->random(),
                        'manager_approved'        => $managerApproved,
                        'approved_by'             => $managerApproved ? collect($this->userIds)->random() : null,
                        'approved_at'             => $managerApproved ? $date->copy()->addDay() : null,
                        'created_at'              => $date,
                        'updated_at'              => $date,
                    ]);
                    $count++;
                }
            }
        }

        $this->command->info("Seeded {$count} attendance records.");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Policies
    // ──────────────────────────────────────────────────────────────────────────

    private function seedPolicies(): void
    {
        if (DB::table('policy_violations')->exists()) {
            $this->command->info('Policy violations already exist — skipping.');
            return;
        }
        $catIds = DB::table('policy_category')->pluck('id')->toArray();
        if (empty($catIds)) {
            $this->command->warn('No policy categories found — skipping policies.');
            return;
        }

        $severities = ['low', 'medium', 'medium', 'high', 'critical'];
        $outcomes   = ['blocked', 'warned', 'warned', 'escalated', 'logged'];
        $vStatuses  = ['open', 'open', 'under_investigation', 'resolved', 'dismissed'];
        $aStatuses  = ['pending', 'pending', 'completed', 'completed', 'completed', 'overdue'];

        // Reuse existing policies if present, otherwise create demo ones
        $policyIds = DB::table('policy')->pluck('id')->toArray();
        if (empty($policyIds)) {
            $statuses    = ['active', 'active', 'active', 'draft', 'under_review', 'archived'];
            $policyNames = [
                'Remote Work Policy', 'Data Protection & Privacy Policy', 'Code of Conduct',
                'Anti-Bribery & Corruption Policy', 'Information Security Policy',
                'Health & Safety Policy', 'Equal Opportunity Policy', 'Whistleblower Policy',
                'Travel & Expense Policy', 'Fleet Usage Policy', 'Leave Policy',
                'Disciplinary Policy', 'Social Media Policy', 'Procurement Policy',
            ];
            foreach ($policyNames as $i => $name) {
                $catId   = $catIds[$i % count($catIds)];
                $deptId  = $this->deptIds ? $this->deptIds[$i % count($this->deptIds)] : null;
                $effDate = now()->subMonths(rand(2, 24));

                $policyId = DB::table('policy')->insertGetId([
                    'uuid'                => Str::uuid(),
                    'name'                => $name,
                    'description'         => 'Governing policy for ' . $name,
                    'status'              => collect($statuses)->random(),
                    'category_id'         => $catId,
                    'department_id'       => $deptId,
                    'effective_date'      => $effDate->toDateString(),
                    'review_date'         => $effDate->copy()->addYear()->toDateString(),
                    'requires_attestation'=> rand(0, 1) === 1,
                    'refresh_month'       => rand(1, 12),
                    'slug'                => Str::slug($name . '-' . rand(1000, 9999)),
                    'created_at'          => $effDate,
                    'updated_at'          => $effDate,
                ]);
                $policyIds[] = $policyId;

                DB::table('policy_versions')->insert([
                    'policy_id'      => $policyId,
                    'version_number' => '1.0',
                    'change_summary' => 'Initial version',
                    'snapshot'       => json_encode(['name' => $name]),
                    'created_by'     => collect($this->userIds)->random(),
                    'published_at'   => $effDate,
                    'created_at'     => $effDate,
                    'updated_at'     => $effDate,
                ]);
            }
            // Link policy_version back
            foreach ($policyIds as $pid) {
                $vId = DB::table('policy_versions')->where('policy_id', $pid)->value('id');
                if ($vId) DB::table('policy')->where('id', $pid)->update(['current_version_id' => $vId]);
            }
        }

        // Seed policy_rules (required for violations)
        $ruleIds = [];
        $ruleNames = ['Confidentiality Breach', 'Unauthorised Access', 'Expense Misuse', 'Safety Non-compliance', 'Data Handling Violation'];
        foreach ($policyIds as $pid) {
            $ruleId = DB::table('policy_rules')->insertGetId([
                'policy_id'        => $pid,
                'name'             => collect($ruleNames)->random(),
                'description'      => 'Mandatory rule under this policy.',
                'severity'         => collect($severities)->random(),
                'action_on_breach' => collect(['block', 'warn', 'escalate', 'log_only'])->random(),
                'active'           => true,
                'created_by'       => collect($this->userIds)->random(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            $ruleIds[$pid] = $ruleId;
        }

        // Violations (12 months)
        $vCount = 0;
        for ($m = 11; $m >= 0; $m--) {
            $monthStart = now()->subMonths($m)->startOfMonth();
            $numViolations = rand(3, 12);
            for ($v = 0; $v < $numViolations; $v++) {
                $userId   = collect($this->userIds)->random();
                $deptId   = $this->deptIds ? collect($this->deptIds)->random() : null;
                $date     = $monthStart->copy()->addDays(rand(0, 27));
                $status   = collect($vStatuses)->random();
                $policyId = collect($policyIds)->random();

                DB::table('policy_violations')->insert([
                    'policy_id'      => $policyId,
                    'rule_id'        => $ruleIds[$policyId],
                    'user_id'        => $userId,
                    'department_id'  => $deptId,
                    'severity'       => collect($severities)->random(),
                    'outcome'        => collect($outcomes)->random(),
                    'status'         => $status,
                    'event_snapshot' => json_encode(['date' => $date->toDateString(), 'user' => $userId]),
                    'explanation'    => 'Violation reported during routine audit.',
                    'resolved_at'    => $status === 'resolved' ? $date->copy()->addDays(rand(3, 30)) : null,
                    'created_at'     => $date,
                    'updated_at'     => $date,
                ]);
                $vCount++;
            }
        }

        // Attestations — one per user per policy (unique constraint: policy_id, user_id, policy_version_id)
        $aCount = 0;
        foreach ($this->userIds as $uid) {
            foreach ($policyIds as $pid) {
                $versionId = DB::table('policy_versions')->where('policy_id', $pid)->value('id');
                if (!$versionId) continue; // skip if no version exists for this policy

                $for_month = now()->subMonths(rand(0, 11));
                $status    = collect($aStatuses)->random();
                $deadline  = $for_month->copy()->addDays(rand(10, 30));

                DB::table('policy_attestations')->insert([
                    'uuid'              => Str::uuid(),
                    'policy_id'         => $pid,
                    'user_id'           => $uid,
                    'policy_version_id' => $versionId,
                    'deadline'          => $deadline->toDateString(),
                    'status'            => $status,
                    'viewed_at'         => in_array($status, ['completed', 'overdue']) ? $for_month->copy()->addDays(rand(1, 5)) : null,
                    'acknowledged_at'   => $status === 'completed' ? $for_month->copy()->addDays(rand(5, 10)) : null,
                    'signed_at'         => $status === 'completed' ? $for_month->copy()->addDays(rand(6, 12)) : null,
                    'created_at'        => $for_month,
                    'updated_at'        => $for_month,
                ]);
                $aCount++;
            }
        }

        $this->command->info("Seeded " . count($policyIds) . " policies, {$vCount} violations, {$aCount} attestations.");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Assets
    // ──────────────────────────────────────────────────────────────────────────

    private function seedAssets(): void
    {
        if (DB::table('assets')->exists()) {
            $this->command->info('Assets already exist — skipping.');
            return;
        }
        // Ensure locations
        $locIds = DB::table('asset_locations')->pluck('id')->toArray();
        if (empty($locIds)) {
            $locs = [
                ['name' => 'Head Office JHB',  'code' => 'JHB-HQ'],
                ['name' => 'Cape Town Branch',  'code' => 'CPT-BR'],
                ['name' => 'Durban Office',     'code' => 'DBN-OF'],
                ['name' => 'Pretoria HQ',       'code' => 'PTA-HQ'],
                ['name' => 'Sandton Hub',       'code' => 'SAN-HB'],
            ];
            foreach ($locs as $l) {
                $locIds[] = DB::table('asset_locations')->insertGetId([
                    'uuid'       => Str::uuid(),
                    'name'       => $l['name'],
                    'code'       => $l['code'],
                    'status'     => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Ensure categories
        $catIds = DB::table('asset_categories')->pluck('id')->toArray();
        if (empty($catIds)) {
            $cats = [
                ['name' => 'IT Equipment',   'sars_wear_rate' => 33.33, 'default_useful_life_years' => 3],
                ['name' => 'Furniture',       'sars_wear_rate' => 16.67, 'default_useful_life_years' => 6],
                ['name' => 'Office Equipment','sars_wear_rate' => 20.00, 'default_useful_life_years' => 5],
                ['name' => 'Vehicles',        'sars_wear_rate' => 20.00, 'default_useful_life_years' => 5],
                ['name' => 'Machinery',       'sars_wear_rate' => 10.00, 'default_useful_life_years' => 10],
            ];
            foreach ($cats as $c) {
                $catIds[] = DB::table('asset_categories')->insertGetId(array_merge($c, [
                    'uuid'   => Str::uuid(), 'status' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            }
        }

        $assetData = [
            ['name' => 'Dell Latitude 5520 Laptop',       'cat' => 0, 'cost' => 18500,  'status' => 'active'],
            ['name' => 'HP EliteBook 840 G9',             'cat' => 0, 'cost' => 22000,  'status' => 'active'],
            ['name' => 'Apple MacBook Pro 14"',           'cat' => 0, 'cost' => 35000,  'status' => 'active'],
            ['name' => 'Dell OptiPlex 7090 Desktop',      'cat' => 0, 'cost' => 12000,  'status' => 'active'],
            ['name' => 'HP LaserJet Pro M404dn Printer',  'cat' => 2, 'cost' => 8500,   'status' => 'active'],
            ['name' => 'Canon imageRUNNER 2530',          'cat' => 2, 'cost' => 45000,  'status' => 'active'],
            ['name' => 'Samsung 27" Monitor',             'cat' => 0, 'cost' => 4500,   'status' => 'active'],
            ['name' => 'Executive Desk Oak',              'cat' => 1, 'cost' => 6500,   'status' => 'active'],
            ['name' => 'Ergonomic Office Chair',          'cat' => 1, 'cost' => 3200,   'status' => 'active'],
            ['name' => 'Conference Table 12-seater',      'cat' => 1, 'cost' => 28000,  'status' => 'active'],
            ['name' => 'UPS APC Smart-UPS 1500',         'cat' => 2, 'cost' => 7800,   'status' => 'active'],
            ['name' => 'Cisco Catalyst 2960 Switch',     'cat' => 0, 'cost' => 32000,  'status' => 'active'],
            ['name' => 'Toyota Hilux GD6 – GP12DEMO',    'cat' => 3, 'cost' => 350000, 'status' => 'active'],
            ['name' => 'Ford Ranger XLT – WC11DEMO',     'cat' => 3, 'cost' => 380000, 'status' => 'active'],
            ['name' => 'Industrial Generator 50kVA',     'cat' => 4, 'cost' => 120000, 'status' => 'active'],
            ['name' => 'Sony Projector VPL-EX435',       'cat' => 2, 'cost' => 9500,   'status' => 'maintenance'],
            ['name' => 'iPad Pro 12.9"',                 'cat' => 0, 'cost' => 18000,  'status' => 'active'],
            ['name' => 'Logitech Video Conferencing Kit','cat' => 0, 'cost' => 12000,  'status' => 'active'],
            ['name' => 'HP ProLiant DL380 Server',       'cat' => 0, 'cost' => 95000,  'status' => 'active'],
            ['name' => 'Standing Desk Motorised',        'cat' => 1, 'cost' => 8900,   'status' => 'disposed'],
        ];

        $assetIds  = [];
        $conditions = ['excellent', 'good', 'good', 'fair', 'poor'];

        foreach ($assetData as $i => $a) {
            $purchaseDate = now()->subMonths(rand(6, 36));
            $purchaseCost = $a['cost'];
            $catId        = $catIds[$a['cat'] % count($catIds)];
            $locId        = collect($locIds)->random();
            $userId       = collect($this->userIds)->random();

            $assetId = DB::table('assets')->insertGetId([
                'uuid'           => Str::uuid(),
                'name'           => $a['name'],
                'asset_tag'      => 'AST-' . str_pad($i + 1, 5, '0', STR_PAD_LEFT),
                'asset_number'   => 'AN' . str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                'serial_number'  => strtoupper(Str::random(10)),
                'asset_category_id' => $catId,
                'location_id'    => $locId,
                'user_id'        => $userId,
                'status'         => 1,
                'asset_status'   => $a['status'],
                'condition'      => collect($conditions)->random(),
                'purchase_cost'  => $purchaseCost,
                'purchase_date'  => $purchaseDate->toDateString(),
                'warranty_expiry'=> $purchaseDate->copy()->addYears(rand(1, 3))->toDateString(),
                'created_at'     => $purchaseDate,
                'updated_at'     => $purchaseDate,
            ]);
            $assetIds[] = $assetId;

            // Assignment
            DB::table('asset_assignments')->insert([
                'uuid'            => Str::uuid(),
                'asset_id'        => $assetId,
                'assigned_to'     => $userId,
                'assigned_by'     => collect($this->userIds)->random(),
                'location_id'     => $locId,
                'assignment_type' => collect(['permanent', 'temporary', 'project'])->random(),
                'effective_date'  => $purchaseDate->toDateString(),
                'is_current'      => true,
                'created_at'      => $purchaseDate,
                'updated_at'      => $purchaseDate,
            ]);
        }

        // Maintenance records (12 months spread)
        $maintTypes   = ['preventive', 'corrective', 'corrective', 'predictive', 'breakdown'];
        $maintStatuses= ['completed', 'completed', 'completed', 'scheduled', 'in_progress'];
        $mCount = 0;
        foreach ($assetIds as $aId) {
            $records = rand(1, 5);
            for ($r = 0; $r < $records; $r++) {
                $date = now()->subMonths(rand(0, 11))->subDays(rand(0, 25));
                $cost = rand(500, 25000);
                $mStatus = collect($maintStatuses)->random();

                DB::table('asset_maintenances')->insert([
                    'uuid'             => Str::uuid(),
                    'asset_id'         => $aId,
                    'maintenance_type' => collect($maintTypes)->random(),
                    'maintenance_date' => $date->toDateString(),
                    'completed_date'   => $mStatus === 'completed' ? $date->copy()->addDays(rand(1, 5))->toDateString() : null,
                    'description'      => 'Scheduled maintenance and inspection',
                    'cost'             => $cost,
                    'status'           => $mStatus,
                    'next_due_date'    => $date->copy()->addMonths(6)->toDateString(),
                    'performed_by_user_id' => collect($this->userIds)->random(),
                    'created_at'       => $date,
                    'updated_at'       => $date,
                ]);
                $mCount++;
            }
        }

        // Incidents
        $incSeverities = ['low', 'medium', 'medium', 'high', 'critical'];
        $iCount = 0;
        foreach (array_slice($assetIds, 0, 10) as $aId) {
            $incidents = rand(0, 3);
            for ($i = 0; $i < $incidents; $i++) {
                $occurredAt = now()->subMonths(rand(0, 11))->subDays(rand(0, 28));
                $isResolved = rand(0, 1) === 1;

                DB::table('asset_incidents')->insert([
                    'uuid'             => Str::uuid(),
                    'asset_id'         => $aId,
                    'title'            => collect(['Physical damage', 'Theft attempt', 'Malfunction', 'Water damage', 'Power surge'])->random(),
                    'description'      => 'Incident reported and logged for investigation.',
                    'severity'         => collect($incSeverities)->random(),
                    'repair_cost'      => rand(0, 15000),
                    'is_resolved'      => $isResolved,
                    'resolved_at'      => $isResolved ? $occurredAt->copy()->addDays(rand(3, 20)) : null,
                    'occurred_at'      => $occurredAt,
                    'reported_by'      => collect($this->userIds)->random(),
                    'created_at'       => $occurredAt,
                    'updated_at'       => $occurredAt,
                ]);
                $iCount++;
            }
        }

        $this->command->info("Seeded " . count($assetIds) . " assets, {$mCount} maintenance records, {$iCount} incidents.");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HelpDesk
    // ──────────────────────────────────────────────────────────────────────────

    private function seedHelpDesk(): void
    {
        if (DB::table('hd_tickets')->exists()) {
            $this->command->info('HelpDesk tickets already exist — skipping.');
            return;
        }
        // Statuses
        if (!DB::table('hd_statuses')->exists()) {
            $statuses = [
                ['name' => 'Open',              'slug' => 'open',              'color' => '#2196F3', 'is_open' => true,  'is_default' => true,  'is_closed' => false, 'sort_order' => 1],
                ['name' => 'In Progress',       'slug' => 'in-progress',       'color' => '#FF9800', 'is_open' => true,  'is_default' => false, 'is_closed' => false, 'sort_order' => 2],
                ['name' => 'Waiting on User',   'slug' => 'waiting-on-user',   'color' => '#9C27B0', 'is_open' => true,  'is_default' => false, 'is_closed' => false, 'sort_order' => 3],
                ['name' => 'Resolved',          'slug' => 'resolved',          'color' => '#4CAF50', 'is_open' => false, 'is_default' => false, 'is_closed' => true,  'sort_order' => 4],
                ['name' => 'Closed',            'slug' => 'closed',            'color' => '#607D8B', 'is_open' => false, 'is_default' => false, 'is_closed' => true,  'sort_order' => 5],
                ['name' => 'Cancelled',         'slug' => 'cancelled',         'color' => '#F44336', 'is_open' => false, 'is_default' => false, 'is_closed' => true,  'sort_order' => 6],
            ];
            foreach ($statuses as $s) {
                DB::table('hd_statuses')->insert(array_merge($s, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]));
            }
        }

        // Priorities
        if (!DB::table('hd_priorities')->exists()) {
            $priorities = [
                ['name' => 'Critical', 'slug' => 'critical', 'color' => '#F44336', 'level' => 1, 'sla_hours' => 4,  'resolution_hours' => 8],
                ['name' => 'High',     'slug' => 'high',     'color' => '#FF5722', 'level' => 2, 'sla_hours' => 8,  'resolution_hours' => 24],
                ['name' => 'Medium',   'slug' => 'medium',   'color' => '#FF9800', 'level' => 3, 'sla_hours' => 24, 'resolution_hours' => 72],
                ['name' => 'Low',      'slug' => 'low',      'color' => '#4CAF50', 'level' => 4, 'sla_hours' => 72, 'resolution_hours' => 168],
            ];
            foreach ($priorities as $p) {
                DB::table('hd_priorities')->insert(array_merge($p, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]));
            }
        }

        // Categories
        if (!DB::table('hd_categories')->exists()) {
            $cats = [
                'IT Support', 'HR & Payroll', 'Facilities', 'Finance',
                'Fleet & Vehicles', 'Software & Licensing', 'Network & Connectivity', 'Security',
            ];
            foreach ($cats as $i => $cat) {
                DB::table('hd_categories')->insert([
                    'name'      => $cat,
                    'slug'      => Str::slug($cat),
                    'color'     => ['#2196F3','#4CAF50','#FF9800','#9C27B0','#F44336','#00BCD4','#795548','#607D8B'][$i],
                    'is_active' => true,
                    'sort_order'=> $i + 1,
                    'created_at'=> now(),
                    'updated_at'=> now(),
                ]);
            }
        }

        // Inboxes
        if (!DB::table('hd_inboxes')->exists()) {
            $inboxes = ['General Support', 'IT Helpdesk', 'HR Queries', 'Facilities'];
            foreach ($inboxes as $inbox) {
                DB::table('hd_inboxes')->insert([
                    'name'        => $inbox,
                    'email'       => Str::slug($inbox) . '@company.co.za',
                    'description' => $inbox . ' inbox',
                    'active'      => true,
                    'created_by'  => $this->userIds[0] ?? 1,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }

        $statusIds   = DB::table('hd_statuses')->pluck('id')->toArray();
        $priorityIds = DB::table('hd_priorities')->pluck('id')->toArray();
        $categoryIds = DB::table('hd_categories')->pluck('id')->toArray();
        $inboxIds    = DB::table('hd_inboxes')->pluck('id')->toArray();

        $openStatusId     = DB::table('hd_statuses')->where('slug', 'open')->value('id')     ?? $statusIds[0];
        $resolvedStatusId = DB::table('hd_statuses')->where('slug', 'resolved')->value('id') ?? $statusIds[3];
        $closedStatusId   = DB::table('hd_statuses')->where('slug', 'closed')->value('id')   ?? $statusIds[4];

        $subjects = [
            'Cannot login to system', 'Email not working', 'Printer offline',
            'Payslip not received', 'Leave balance incorrect', 'VPN connection issue',
            'Software licence expired', 'New employee onboarding setup', 'Office access card not working',
            'Budget report access request', 'Fleet booking issue', 'Network slow in office',
            'Password reset request', 'System upgrade request', 'Security incident report',
            'CCTV camera not recording', 'Air conditioning broken', 'Office furniture request',
        ];
        $sources = ['email', 'web', 'phone', 'chat'];

        $count = 0;
        for ($m = 11; $m >= 0; $m--) {
            $monthStart = now()->subMonths($m)->startOfMonth();
            $numTickets = rand(15, 40);

            for ($t = 0; $t < $numTickets; $t++) {
                $createdAt  = $monthStart->copy()->addDays(rand(0, 27))->addHours(rand(8, 17));
                $priorityId = collect($priorityIds)->random();
                $priority   = DB::table('hd_priorities')->where('id', $priorityId)->first();
                $slaHours   = $priority ? $priority->sla_hours : 24;

                // Determine resolution
                $r = rand(1, 10);
                if ($r <= 6) {
                    // resolved
                    $resolvedAt  = $createdAt->copy()->addHours(rand(1, $slaHours * 2));
                    $slaBreached = $resolvedAt->diffInHours($createdAt) > $slaHours;
                    $statusId    = $resolvedStatusId;
                    $firstResp   = $createdAt->copy()->addMinutes(rand(5, 180));
                } elseif ($r <= 8) {
                    // closed
                    $resolvedAt  = $createdAt->copy()->addHours(rand($slaHours, $slaHours * 3));
                    $slaBreached = true;
                    $statusId    = $closedStatusId;
                    $firstResp   = $createdAt->copy()->addMinutes(rand(30, 360));
                } else {
                    // open
                    $resolvedAt  = null;
                    $slaBreached = now()->diffInHours($createdAt) > $slaHours;
                    $statusId    = $openStatusId;
                    $firstResp   = $createdAt->copy()->addMinutes(rand(15, 240));
                }

                $assignedTo = collect($this->userIds)->random();

                DB::table('hd_tickets')->insert([
                    'ticket_number'   => 'TKT-' . str_pad($count + 1, 6, '0', STR_PAD_LEFT),
                    'subject'         => collect($subjects)->random(),
                    'description'     => 'Demo ticket raised by user. Requires investigation and resolution.',
                    'status_id'       => $statusId,
                    'priority_id'     => $priorityId,
                    'category_id'     => collect($categoryIds)->random(),
                    'inbox_id'        => collect($inboxIds)->random(),
                    'requester_id'    => collect($this->userIds)->random(),
                    'assigned_to'     => $assignedTo,
                    'source'          => collect($sources)->random(),
                    'sla_breached'    => $slaBreached,
                    'impact_level'    => collect(['low', 'medium', 'high'])->random(),
                    'urgency_level'   => collect(['low', 'medium', 'high'])->random(),
                    'first_response_at'=> $firstResp,
                    'resolved_at'     => $resolvedAt,
                    'closed_at'       => $statusId === $closedStatusId ? $resolvedAt?->copy()->addHours(rand(1, 24)) : null,
                    'created_by'      => collect($this->userIds)->random(),
                    'created_at'      => $createdAt,
                    'updated_at'      => $resolvedAt ?? $createdAt,
                ]);
                $count++;
            }
        }

        $this->command->info("Seeded {$count} helpdesk tickets.");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Recruitment
    // ──────────────────────────────────────────────────────────────────────────

    private function seedRecruitment(): void
    {
        if (DB::table('recruitment_jobs')->exists()) {
            $this->command->info('Recruitment jobs already exist — skipping.');
            return;
        }
        $deptIds = $this->deptIds ?: [null];
        $empTypes  = ['full_time', 'part_time', 'contract', 'internship'];
        $jobTitles = [
            'Senior Software Developer', 'HR Business Partner', 'Financial Analyst',
            'Project Manager', 'Operations Supervisor', 'Marketing Coordinator',
            'IT Systems Administrator', 'Legal Counsel', 'Supply Chain Analyst',
            'Customer Service Manager', 'Data Scientist', 'Fleet Coordinator',
            'Internal Auditor', 'UX Designer', 'Business Development Executive',
        ];
        $sources   = ['linkedin', 'job_board', 'referral', 'direct', 'agency', 'direct', 'internal'];
        $appStatuses = ['applied', 'applied', 'screening', 'interview', 'offer_sent', 'hired', 'rejected', 'withdrawn'];

        $jobIds = [];
        foreach ($jobTitles as $i => $title) {
            $postedAt  = now()->subMonths(rand(1, 11));
            $closesAt  = $postedAt->copy()->addMonths(rand(1, 3));
            $deptId    = $deptIds[$i % count($deptIds)];
            $isActive  = rand(0, 1) === 1 || $closesAt->isFuture();

            $jobId = DB::table('recruitment_jobs')->insertGetId([
                'title'           => $title,
                'reference_number'=> 'VAC-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'company_unit_id' => $deptId,
                'location'        => collect(['Johannesburg', 'Cape Town', 'Durban', 'Pretoria', 'Remote'])->random(),
                'employment_type' => collect($empTypes)->random(),
                'description'     => 'We are looking for a qualified ' . $title . ' to join our team.',
                'requirements'    => '3+ years experience, relevant qualification, strong communication skills.',
                'responsibilities'=> 'Lead team efforts, report to management, drive results.',
                'salary_min'      => rand(25000, 50000),
                'salary_max'      => rand(55000, 120000),
                'currency'        => 'ZAR',
                'is_active'       => $isActive,
                'posted_at'       => $postedAt,
                'closes_at'       => $closesAt,
                'created_by'      => collect($this->userIds)->random(),
                'updated_by'      => collect($this->userIds)->random(),
                'created_at'      => $postedAt,
                'updated_at'      => $postedAt,
            ]);
            $jobIds[] = $jobId;

            // Pipeline stages (type must match constraint)
            $stages = [
                ['name' => 'Screening',   'type' => 'screening'],
                ['name' => 'Interview',   'type' => 'interview'],
                ['name' => 'Assessment',  'type' => 'assessment'],
                ['name' => 'Offer',       'type' => 'offer'],
                ['name' => 'Onboarding',  'type' => 'onboarding'],
            ];
            foreach ($stages as $si => $stage) {
                DB::table('recruitment_pipeline_stages')->insert([
                    'job_id'      => $jobId,
                    'name'        => $stage['name'],
                    'type'        => $stage['type'],
                    'order_index' => $si + 1,
                    'is_active'   => true,
                    'created_at'  => $postedAt,
                    'updated_at'  => $postedAt,
                ]);
            }
        }

        // Candidates and applications
        $firstNames = ['Thabo', 'Nomsa', 'Sipho', 'Lerato', 'Kagiso', 'Zanele', 'Bongani', 'Ayanda', 'Nkosi', 'Refilwe', 'Lebo', 'Tshepiso', 'Mpho', 'Siyanda', 'Palesa'];
        $lastNames  = ['Mokoena', 'Dlamini', 'Khumalo', 'Nkosi', 'Sithole', 'Mthembu', 'Mahlangu', 'Vilakazi', 'Ntuli', 'Ndlovu', 'Zulu', 'Mkhize', 'Buthelezi', 'Cele', 'Gumede'];
        $candidateIds = [];

        for ($c = 0; $c < 60; $c++) {
            $candidateId = DB::table('recruitment_candidates')->insertGetId([
                'uuid'       => Str::uuid(),
                'first_name' => collect($firstNames)->random(),
                'last_name'  => collect($lastNames)->random(),
                'email'      => 'candidate' . $c . '@demo.co.za',
                'phone'      => '0' . rand(60, 82) . rand(1000000, 9999999),
                'gender'     => collect(['male', 'female'])->random(),
                'nationality'=> 'South African',
                'created_at' => now()->subMonths(rand(0, 11)),
                'updated_at' => now(),
            ]);
            $candidateIds[] = $candidateId;
        }

        // Applications — unique per (candidate_id, job_id)
        $aCount    = 0;
        $usedPairs = [];
        foreach ($jobIds as $jobId) {
            $shuffled = collect($candidateIds)->shuffle()->take(rand(3, 12))->values();
            foreach ($shuffled as $candidateId) {
                $key = $candidateId . '_' . $jobId;
                if (isset($usedPairs[$key])) continue;
                $usedPairs[$key] = true;

                $appliedAt = now()->subMonths(rand(0, 10))->subDays(rand(0, 20));
                $status    = collect($appStatuses)->random();

                DB::table('recruitment_applications')->insert([
                    'candidate_id'   => $candidateId,
                    'job_id'         => $jobId,
                    'status'         => $status,
                    'source'         => collect($sources)->random(),
                    'cover_letter'   => 'I am applying for this position and believe my skills are a strong match.',
                    'applied_at'     => $appliedAt,
                    'rejected_at'    => $status === 'rejected'  ? $appliedAt->copy()->addDays(rand(5, 30)) : null,
                    'withdrawn_at'   => $status === 'withdrawn' ? $appliedAt->copy()->addDays(rand(3, 15)) : null,
                    'stage_moved_at' => in_array($status, ['interview', 'offer_sent', 'hired']) ? $appliedAt->copy()->addDays(rand(3, 14)) : null,
                    'recruiter_id'   => collect($this->userIds)->random(),
                    'created_at'     => $appliedAt,
                    'updated_at'     => $appliedAt,
                ]);
                $aCount++;
            }
        }

        $this->command->info("Seeded " . count($jobIds) . " jobs, " . count($candidateIds) . " candidates, {$aCount} applications.");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Performance Appraisal
    // ──────────────────────────────────────────────────────────────────────────

    private function seedPerformanceAppraisal(): void
    {
        if (DB::table('pa_cycles')->exists()) {
            $this->command->info('PA cycles already exist — skipping.');
            return;
        }
        // Rating scale
        $scaleId = DB::table('pa_rating_scales')->where('is_default', true)->value('id');
        if (!$scaleId) {
            $scaleId = DB::table('pa_rating_scales')->insertGetId([
                'name'        => '1–5 Performance Scale',
                'description' => 'Standard 5-point rating scale',
                'min_score'   => 1,
                'max_score'   => 5,
                'is_default'  => true,
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            // Rating values
            $ratingValues = [
                [1, 'Unsatisfactory',      '#F44336', 'Performance significantly below expectations'],
                [2, 'Needs Improvement',   '#FF9800', 'Performance partially meets expectations'],
                [3, 'Meets Expectations',  '#2196F3', 'Performance fully meets expectations'],
                [4, 'Exceeds Expectations','#4CAF50', 'Performance consistently exceeds expectations'],
                [5, 'Outstanding',         '#9C27B0', 'Performance is exceptional in all areas'],
            ];
            foreach ($ratingValues as [$val, $label, $color, $desc]) {
                DB::table('pa_rating_values')->insert([
                    'rating_scale_id' => $scaleId,
                    'value'           => $val,
                    'label'           => $label,
                    'color'           => $color,
                    'description'     => $desc,
                    'sort_order'      => $val,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }

        // Template
        $templateId = DB::table('pa_templates')->where('is_default', true)->value('id');
        if (!$templateId) {
            $templateId = DB::table('pa_templates')->insertGetId([
                'name'        => 'Annual Performance Review',
                'description' => 'Standard annual performance review template',
                'is_default'  => true,
                'is_active'   => true,
                'created_by'  => $this->userIds[0] ?? 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // Cycles (3 years of annual cycles) — status must be: draft|active|closed|archived
        $cycleStatuses = ['closed', 'closed', 'active'];
        $cycleIds = [];

        for ($y = 2; $y >= 0; $y--) {
            $year    = now()->year - $y;
            $cStatus = $cycleStatuses[$y] ?? 'closed';

            $cycleId = DB::table('pa_cycles')->insertGetId([
                'name'                    => "Annual Review {$year}",
                'description'             => "Annual performance appraisal cycle for {$year}",
                'cycle_type'              => 'annual',
                'start_date'              => "{$year}-01-01",
                'end_date'                => "{$year}-12-31",
                'self_review_deadline'    => "{$year}-11-30",
                'manager_review_deadline' => "{$year}-12-15",
                'status'                  => $cStatus,
                'rating_scale_id'         => $scaleId,
                'template_id'             => $templateId,
                'allow_self_review'       => true,
                'allow_peer_review'       => true,
                'require_manager_approval'=> true,
                'created_by'              => $this->userIds[0] ?? 1,
                'created_at'              => "{$year}-01-01",
                'updated_at'              => now(),
            ]);
            $cycleIds[] = $cycleId;
        }

        $latestCycleId = end($cycleIds);

        // Goals per cycle
        $goalTitles = [
            'Increase revenue by 15%', 'Improve customer satisfaction score to 4.5+',
            'Complete digital transformation project', 'Reduce operational costs by 10%',
            'Achieve 95% SLA compliance', 'Launch new product line by Q3',
            'Hire and onboard 5 new team members', 'Implement ERP upgrade',
        ];
        $deptIds = $this->deptIds ?: [null];

        foreach ($cycleIds as $cId) {
            foreach (array_slice($goalTitles, 0, 5) as $i => $title) {
                $deptId = $deptIds[$i % count($deptIds)];
                $ownerId = collect($this->userIds)->random();
                DB::table('pa_goals')->insert([
                    'cycle_id'     => $cId,
                    'title'        => $title,
                    'description'  => 'Strategic goal aligned to company objectives.',
                    'level'        => collect(['organizational', 'department', 'team'])->random(),
                    'owner_id'     => $ownerId,
                    'owner_unit_id'=> $deptId,
                    'start_date'   => DB::table('pa_cycles')->where('id', $cId)->value('start_date'),
                    'end_date'     => DB::table('pa_cycles')->where('id', $cId)->value('end_date'),
                    'status'       => collect(['active', 'achieved', 'missed', 'draft'])->random(),
                    'target_value' => rand(80, 100),
                    'current_value'=> rand(40, 100),
                    'unit'         => collect(['%', 'units', 'ZAR', 'score'])->random(),
                    'created_by'   => $ownerId,
                    'created_at'   => now()->subMonths(rand(1, 6)),
                    'updated_at'   => now(),
                ]);
            }
        }

        // Employee KPIs and scores
        $kCount = 0;
        $kpiTitles = [
            'Sales Target Achievement', 'Customer Satisfaction', 'Project Delivery On-Time',
            'Budget Adherence', 'Team Development', 'Process Improvement',
        ];
        foreach ($this->people as $person) {
            foreach ($cycleIds as $cId) {
                foreach (array_slice($kpiTitles, 0, rand(3, 5)) as $kTitle) {
                    $kpiId = DB::table('pa_employee_kpis')->insertGetId([
                        'cycle_id'            => $cId,
                        'employee_id'         => $person->id,
                        'title'               => $kTitle,
                        'description'         => 'Key performance indicator for ' . $person->first_name,
                        'target_value'        => 100,
                        'unit'                => '%',
                        'weighting'           => rand(10, 30),
                        'self_update_allowed' => true,
                        'manager_update_allowed' => true,
                        'status'              => collect(['in_progress', 'completed', 'pending'])->random(),
                        'created_by'          => collect($this->userIds)->random(),
                        'created_at'          => now()->subMonths(rand(1, 12)),
                        'updated_at'          => now(),
                    ]);

                    // Score
                    $achieved = rand(60, 100);
                    DB::table('pa_kpi_scores')->insert([
                        'employee_kpi_id' => $kpiId,
                        'achieved_value'  => $achieved,
                        'score_pct'       => $achieved,
                        'weighted_score'  => round($achieved * 0.2, 2),
                        'notes'           => 'Assessed by manager.',
                        'scored_by'       => collect($this->userIds)->random(),
                        'scored_at'       => now()->subMonths(rand(0, 6)),
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                    $kCount++;
                }
            }
        }

        // Feedback
        $feedbackTypes = ['manager', 'peer', 'upward', 'self'];
        $feedbackContent = [
            'Consistently delivers high-quality work and meets all deadlines.',
            'Shows great initiative and takes ownership of challenges.',
            'Needs to improve communication with cross-functional teams.',
            'Excellent technical skills; should mentor junior team members.',
            'Strong leadership potential observed during project delivery.',
            'Time management could be improved to meet competing priorities.',
            'Demonstrates a growth mindset and eagerness to learn.',
        ];
        $fbCount = 0;
        foreach ($this->people as $person) {
            $feedbackItems = rand(2, 6);
            for ($f = 0; $f < $feedbackItems; $f++) {
                $givenBy = collect($this->personIds)->random();
                DB::table('pa_feedback')->insert([
                    'cycle_id'      => collect($cycleIds)->random(),
                    'employee_id'   => $person->id,
                    'given_by'      => $givenBy,
                    'feedback_type' => collect($feedbackTypes)->random(),
                    'content'       => collect($feedbackContent)->random(),
                    'rating'        => rand(2, 5),
                    'is_anonymous'  => rand(0, 3) === 0,
                    'is_draft'      => rand(0, 5) === 0,
                    'created_at'    => now()->subMonths(rand(0, 11)),
                    'updated_at'    => now(),
                ]);
                $fbCount++;
            }
        }

        // PIP Plans (1–2 people)
        $pipPeople = array_slice($this->people, 0, 2);
        foreach ($pipPeople as $person) {
            $startDate = now()->subMonths(rand(1, 6));
            DB::table('pa_pip_plans')->insert([
                'employee_id'   => $person->id,
                'manager_id'    => collect($this->userIds)->random(),
                'cycle_id'      => $latestCycleId,
                'title'         => 'Performance Improvement Plan – ' . $person->first_name,
                'reason'        => 'Performance consistently below department expectations.',
                'start_date'    => $startDate->toDateString(),
                'target_end_date' => $startDate->copy()->addMonths(3)->toDateString(),
                'status'        => collect(['active', 'completed', 'extended'])->random(),
                'created_at'    => $startDate,
                'updated_at'    => now(),
            ]);
        }

        // Probation reviews (2–3 people)
        $probPeople = array_slice($this->people, 0, 3);
        foreach ($probPeople as $person) {
            $startDate = now()->subMonths(rand(2, 8));
            DB::table('pa_probation_reviews')->insert([
                'employee_id'    => $person->id,
                'manager_id'     => collect($this->userIds)->random(),
                'cycle_id'       => $latestCycleId,
                'probation_type' => collect(['new_hire', 'disciplinary'])->random(),
                'start_date'     => $startDate->toDateString(),
                'end_date'       => $startDate->copy()->addMonths(rand(3, 6))->toDateString(),
                'status'         => collect(['active', 'passed', 'extended', 'failed'])->random(),
                'recommendation' => collect(['confirm', 'extend', 'terminate'])->random(),
                'risk_score'     => rand(10, 90),
                'risk_level'     => collect(['low', 'medium', 'high'])->random(),
                'success_probability' => rand(40, 95),
                'created_by'     => collect($this->userIds)->random(),
                'created_at'     => $startDate,
                'updated_at'     => now(),
            ]);
        }

        $this->command->info("Seeded " . count($cycleIds) . " PA cycles, {$kCount} KPIs, {$fbCount} feedback items, " . count($pipPeople) . " PIPs, " . count($probPeople) . " probation reviews.");
    }
}
