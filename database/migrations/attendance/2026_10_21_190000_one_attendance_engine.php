<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One time-and-attendance engine for everybody.
 *
 * The workforce engine built for the farm — workers with categories, crews and labour rule sets;
 * shifts rostered by template or rotating pattern, with swaps and change history; clocking from
 * phones, kiosks, badges and devices, offline and idempotent; several sessions a day; corrections
 * that keep the original; overtime requests; piecework; and the payroll handoff — becomes Time &
 * Attendance's, for every employee. A farm is then just the site some of it happens on (farm_id).
 *
 *  - The farm's tables become T&A's (renamed, data kept).
 *  - Shift templates are T&A's shifts (attendance_shifts), which gain the farm's fields; the farm's
 *    templates are copied in and the rostered shifts re-pointed.
 *  - Clock events are T&A's clockings (attendance_clockings), which gain what the engine needs
 *    (worker, offline client id, verification, supersession); the farm's events are copied in and
 *    every timesheet and correction re-pointed. One clock log, which T&A's reports already read.
 *  - T&A's own roster entries (roster_assignments) become rostered shifts under their schedule.
 *  - The daily attendance record stays: it is what payroll, leave and the analytics read, and the
 *    engine writes it from approved timesheets.
 *
 * shift_templates, farm_clock_events and roster_assignments are left in place and no longer written.
 */
return new class extends Migration
{
    private const RENAMES = [
        'labour_rule_sets' => 'attendance_rule_sets',
        'farm_crews' => 'attendance_crews',
        'farm_workers' => 'attendance_workers',
        'worker_unavailability' => 'attendance_unavailability',
        'farm_shifts' => 'attendance_rostered_shifts',
        'farm_shift_assignments' => 'attendance_shift_assignments',
        'farm_shift_changes' => 'attendance_shift_changes',
        'farm_shift_swaps' => 'attendance_shift_swaps',
        'roster_patterns' => 'attendance_roster_patterns',
        'farm_clock_devices' => 'attendance_clock_devices',
        'farm_timesheets' => 'attendance_timesheets',
        'farm_attendance_corrections' => 'attendance_corrections',
        'farm_overtime_requests' => 'attendance_overtime_requests',
        'piece_rates' => 'attendance_piece_rates',
        'piecework_entries' => 'attendance_piecework_entries',
        'piecework_shares' => 'attendance_piecework_shares',
        'payroll_handoffs' => 'attendance_payroll_handoffs',
        'payroll_handoff_lines' => 'attendance_payroll_handoff_lines',
    ];

    /** Tables where the farm becomes an optional site. */
    private const SITED = ['attendance_crews', 'attendance_workers', 'attendance_rostered_shifts', 'attendance_shift_assignments', 'attendance_shift_changes',
        'attendance_shift_swaps', 'attendance_roster_patterns', 'attendance_clock_devices', 'attendance_timesheets', 'attendance_corrections',
        'attendance_overtime_requests', 'attendance_piecework_entries', 'attendance_piecework_shares', 'attendance_unavailability'];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
        // A rostered shift's template is now a T&A shift.
        DB::statement('ALTER TABLE attendance_rostered_shifts DROP CONSTRAINT IF EXISTS farm_shifts_template_id_foreign');
        foreach (self::SITED as $t) {
            if (Schema::hasColumn($t, 'farm_id')) {
                DB::statement("ALTER TABLE {$t} ALTER COLUMN farm_id DROP NOT NULL");
            }
        }

        // Shifts: T&A's shift definitions gain what a roster template needs.
        Schema::table('attendance_shifts', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
            $t->string('kind', 16)->default('day');
            $t->boolean('paid_break')->default(false);
            $t->jsonb('required_certifications')->nullable();
            $t->unsignedSmallInteger('min_staff')->nullable();
            $t->unsignedBigInteger('farm_id')->nullable()->index();             // a site's own template
            $t->unsignedBigInteger('legacy_template_id')->nullable();
        });
        foreach (DB::table('attendance_shifts')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('attendance_shifts')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }
        Schema::table('attendance_rostered_shifts', function (Blueprint $t) {
            $t->unsignedBigInteger('roster_schedule_id')->nullable()->index();  // the schedule (period) it was published in
        });

        // Clockings: T&A's clock log gains what the engine needs.
        Schema::table('attendance_clockings', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
            $t->unsignedBigInteger('worker_id')->nullable()->index();           // attendance_workers
            $t->unsignedBigInteger('farm_id')->nullable()->index();
            $t->string('device_ref', 120)->nullable();
            $t->boolean('gps_verified')->default(false);
            $t->string('verification', 12)->nullable();                         // verified | unverified | suspicious
            $t->string('verification_note', 300)->nullable();
            $t->uuid('client_id')->nullable();                                   // a phone's own id for an offline tap
            $t->boolean('captured_offline')->default(false);
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->boolean('supervisor_confirmed')->default(false);
            $t->unsignedBigInteger('superseded_by_id')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX attendance_clockings_worker_client_uq ON attendance_clockings (worker_id, client_id) WHERE client_id IS NOT NULL');

        $this->copyTemplates();
        $this->copyClockEvents();
        $this->copyRoster();
    }

    private function copyTemplates(): void
    {
        if (! Schema::hasTable('shift_templates')) {
            return;
        }
        foreach (DB::table('shift_templates')->orderBy('id')->get() as $tpl) {
            if (DB::table('attendance_shifts')->where('legacy_template_id', $tpl->id)->exists()) {
                continue;
            }
            $code = $tpl->code;
            if (DB::table('attendance_shifts')->where('company_id', $tpl->company_id)->where('code', $code)->exists()) {
                $code = Str::limit("{$tpl->code}-{$tpl->id}", 30, '');
            }
            $id = DB::table('attendance_shifts')->insertGetId([
                'uuid' => $tpl->uuid, 'company_id' => $tpl->company_id, 'name' => $tpl->name, 'code' => $code, 'start_time' => $tpl->starts, 'end_time' => $tpl->ends,
                'break_duration_minutes' => (int) $tpl->break_minutes, 'is_overnight' => $tpl->ends < $tpl->starts, 'is_flexible' => false,
                'flexible_window_minutes' => 0, 'status' => $tpl->is_active ? 1 : 0, 'kind' => $tpl->kind, 'paid_break' => (bool) $tpl->paid_break,
                'required_certifications' => $tpl->required_certifications, 'min_staff' => $tpl->min_staff, 'legacy_template_id' => $tpl->id,
                'created_at' => $tpl->created_at, 'updated_at' => $tpl->updated_at,
            ]);
            DB::table('attendance_rostered_shifts')->where('company_id', $tpl->company_id)->where('template_id', $tpl->id)->update(['template_id' => $id]);
            if ($code !== $tpl->code) {
                foreach (DB::table('attendance_roster_patterns')->where('company_id', $tpl->company_id)->get(['id', 'cycle']) as $p) {
                    $cycle = array_map(fn ($c) => $c === $tpl->code ? $code : $c, json_decode((string) $p->cycle, true) ?: []);
                    DB::table('attendance_roster_patterns')->where('id', $p->id)->update(['cycle' => json_encode($cycle)]);
                }
            }
        }
    }

    private function copyClockEvents(): void
    {
        if (! Schema::hasTable('farm_clock_events')) {
            return;
        }
        $person = DB::table('attendance_workers')->pluck('hr_person_id', 'id');
        $map = [];
        foreach (DB::table('farm_clock_events')->orderBy('id')->get() as $e) {
            if ($done = DB::table('attendance_clockings')->where('uuid', $e->uuid)->value('id')) {
                $map[$e->id] = $done;

                continue;
            }
            if (! isset($person[$e->worker_id])) {
                continue;
            }
            $map[$e->id] = DB::table('attendance_clockings')->insertGetId([
                'uuid' => $e->uuid, 'company_id' => $e->company_id, 'employee_id' => $person[$e->worker_id], 'worker_id' => $e->worker_id, 'farm_id' => $e->farm_id,
                'type' => $e->kind === 'out' ? 'clock_out' : 'clock_in', 'method' => $e->method, 'work_type' => 'site', 'clocked_at' => $e->occurred_at,
                'latitude' => $e->latitude, 'longitude' => $e->longitude, 'device_ref' => $e->device_ref, 'gps_verified' => (bool) $e->gps_verified,
                'verification' => $e->verification, 'verification_note' => $e->verification_note, 'is_verified' => $e->verification === 'verified',
                'client_id' => $e->client_id, 'captured_offline' => (bool) $e->captured_offline, 'recorded_by' => $e->recorded_by,
                'supervisor_confirmed' => (bool) $e->supervisor_confirmed, 'is_missed' => false, 'is_corrected' => $e->method === 'correction',
                'created_at' => $e->created_at, 'updated_at' => $e->updated_at,
            ]);
        }
        foreach (DB::table('farm_clock_events')->whereNotNull('superseded_by_id')->get(['id', 'superseded_by_id']) as $e) {
            if (isset($map[$e->id], $map[$e->superseded_by_id])) {
                DB::table('attendance_clockings')->where('id', $map[$e->id])->update(['superseded_by_id' => $map[$e->superseded_by_id]]);
            }
        }
        foreach ([['attendance_timesheets', ['in_event_id', 'out_event_id']], ['attendance_corrections', ['original_event_id', 'correction_event_id']]] as [$table, $cols]) {
            foreach (DB::table($table)->get(array_merge(['id'], $cols)) as $row) {
                $set = [];
                foreach ($cols as $c) {
                    if ($row->{$c} && isset($map[$row->{$c}])) {
                        $set[$c] = $map[$row->{$c}];
                    }
                }
                if ($set) {
                    DB::table($table)->where('id', $row->id)->update($set);
                }
            }
        }
    }

    /** T&A's roster entries become rostered shifts with one assignment each, under their schedule. */
    private function copyRoster(): void
    {
        $tz = config('app.business_timezone', 'Africa/Johannesburg');
        foreach (DB::table('roster_assignments')->where('is_rest_day', false)->whereNotNull('shift_id')->orderBy('id')->get() as $ra) {
            $shift = DB::table('attendance_shifts')->find($ra->shift_id);
            if (! $shift) {
                continue;
            }
            $worker = DB::table('attendance_workers')->where('company_id', $ra->company_id)->where('hr_person_id', $ra->employee_id)->value('id')
                ?? DB::table('attendance_workers')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $ra->company_id, 'hr_person_id' => $ra->employee_id,
                    'category' => 'permanent', 'status' => 'active', 'gps_allowed' => false, 'biometric_allowed' => false, 'created_at' => now(), 'updated_at' => now()]);
            $date = substr((string) $ra->assigned_date, 0, 10);
            $start = \Illuminate\Support\Carbon::parse("{$date} {$shift->start_time}", $tz);
            $end = \Illuminate\Support\Carbon::parse("{$date} {$shift->end_time}", $tz);
            if ($end->lte($start)) {
                $end->addDay();
            }
            $status = DB::table('roster_schedules')->where('id', $ra->roster_schedule_id)->value('status') === 'published' ? 'published' : 'draft';
            $rs = DB::table('attendance_rostered_shifts')->where('roster_schedule_id', $ra->roster_schedule_id)->where('template_id', $shift->id)
                ->where('starts_at', $start->copy()->utc())->value('id')
                ?? DB::table('attendance_rostered_shifts')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $ra->company_id, 'template_id' => $shift->id,
                    'roster_schedule_id' => $ra->roster_schedule_id, 'title' => $shift->name, 'starts_at' => $start->copy()->utc(), 'ends_at' => $end->copy()->utc(),
                    'break_minutes' => (int) $shift->break_duration_minutes, 'paid_break' => false, 'status' => $status, 'revision' => 1,
                    'created_at' => $ra->created_at, 'updated_at' => $ra->updated_at]);
            if (! DB::table('attendance_shift_assignments')->where('shift_id', $rs)->where('worker_id', $worker)->exists()) {
                DB::table('attendance_shift_assignments')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $ra->company_id, 'shift_id' => $rs, 'worker_id' => $worker,
                    'status' => $ra->status === 'swapped' ? 'swapped' : 'assigned', 'created_at' => $ra->created_at, 'updated_at' => $ra->updated_at]);
            }
        }
    }

    public function down(): void
    {
        // Data moved into T&A's tables stays; the renamed tables keep their new names.
    }
};
