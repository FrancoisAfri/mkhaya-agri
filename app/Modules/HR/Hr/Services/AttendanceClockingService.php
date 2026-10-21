<?php

namespace App\Modules\HR\Hr\Services;

use App\Modules\HR\Hr\Events\ClockingRecorded;
use App\Modules\HR\Hr\Models\Attendance\AttendanceBiometricLog;
use App\Modules\HR\Hr\Models\Attendance\AttendanceBreak;
use App\Modules\HR\Hr\Models\Attendance\AttendanceClocking;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDeviceLog;
use App\Modules\HR\Hr\Models\Attendance\AttendanceGpsLog;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\AttendanceSetting;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Hr\Services\AttendanceNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceClockingService
{
    private AttendanceSetting $settings;

    public function __construct()
    {
        $this->settings = AttendanceSetting::current();
    }

    // ── Clock In ─────────────────────────────────────────────────────────────

    public function clockIn(
        int $employeeId,
        string $method = 'manual',
        string $workType = 'office',
        array $meta = []
    ): AttendanceClocking {
        return DB::transaction(function () use ($employeeId, $method, $workType, $meta) {
            $now = Carbon::now($this->settings->timezone ?? config('app.timezone'));

            $clocking = AttendanceClocking::create([
                'employee_id'  => $employeeId,
                'type'         => 'clock_in',
                'method'       => $method,
                'work_type'    => $workType,
                'clocked_at'   => $now,
                'location'     => $meta['location'] ?? null,
                'latitude'     => $meta['latitude'] ?? null,
                'longitude'    => $meta['longitude'] ?? null,
                'device_id'    => $meta['device_id'] ?? null,
                'ip_address'   => $meta['ip_address'] ?? null,
                'notes'        => $meta['notes'] ?? null,
                'is_verified'  => in_array($method, ['biometric', 'facial', 'qr_code']),
            ]);

            $record = $this->resolveOrCreateRecord($employeeId, $now, $clocking);
            $clocking->update(['attendance_record_id' => $record->id]);
            // One engine: the clocking opens a timesheet session, measured and approved like everyone's.
            app(\App\Modules\HR\Hr\Attendance\Services\Attendance::class)->adopt($clocking->id);

            if (isset($meta['latitude'], $meta['longitude'])) {
                $this->saveGpsLog($employeeId, $clocking->id, $meta);
            }

            $this->broadcastClocking($clocking, $record);

            return $clocking;
        });
    }

    // ── Clock Out ────────────────────────────────────────────────────────────

    public function clockOut(
        int $employeeId,
        string $method = 'manual',
        array $meta = []
    ): AttendanceClocking {
        return DB::transaction(function () use ($employeeId, $method, $meta) {
            $now = Carbon::now($this->settings->timezone ?? config('app.timezone'));

            // Auto-close any open break
            $this->closeOpenBreak($employeeId, $now);

            $lastClockIn = AttendanceClocking::forEmployee($employeeId)
                ->clockIns()
                ->whereDate('clocked_at', $now->toDateString())
                ->latest('clocked_at')
                ->first();

            $clocking = AttendanceClocking::create([
                'employee_id'          => $employeeId,
                'attendance_record_id' => $lastClockIn?->attendance_record_id,
                'type'                 => 'clock_out',
                'method'               => $method,
                'work_type'            => $lastClockIn?->work_type ?? 'office',
                'clocked_at'           => $now,
                'location'             => $meta['location'] ?? null,
                'latitude'             => $meta['latitude'] ?? null,
                'longitude'            => $meta['longitude'] ?? null,
                'device_id'            => $meta['device_id'] ?? null,
                'ip_address'           => $meta['ip_address'] ?? null,
                'notes'                => $meta['notes'] ?? null,
                'is_verified'          => in_array($method, ['biometric', 'facial', 'qr_code']),
            ]);

            if ($lastClockIn?->attendance_record_id) {
                $this->updateRecordHours($lastClockIn->attendance_record_id, $lastClockIn->clocked_at, $now);
            }
            app(\App\Modules\HR\Hr\Attendance\Services\Attendance::class)->adopt($clocking->id);

            if (isset($meta['latitude'], $meta['longitude'])) {
                $this->saveGpsLog($employeeId, $clocking->id, $meta);
            }

            $this->broadcastClocking($clocking);

            return $clocking;
        });
    }

    // ── Biometric Clocking ───────────────────────────────────────────────────

    public function captureBiometricAttendance(
        int $employeeId,
        string $deviceId,
        array $payload
    ): AttendanceClocking {
        $log = AttendanceBiometricLog::create([
            'employee_id'       => $employeeId,
            'biometric_type'    => 'fingerprint',
            'device_id'         => $deviceId,
            'confidence_score'  => $payload['confidence'] ?? null,
            'template_hash'     => $payload['template_hash'] ?? null,
            'raw_response'      => $payload,
            'is_matched'        => ($payload['confidence'] ?? 0) >= 70,
            'failure_reason'    => ($payload['confidence'] ?? 0) < 70 ? 'Low confidence score' : null,
            'captured_at'       => now(),
        ]);

        $this->logDevice($employeeId, $deviceId, 'biometric', $payload);

        $type = $this->resolveClockType($employeeId);
        if ($type === 'clock_in') {
            $clocking = $this->clockIn($employeeId, 'biometric', 'office', ['device_id' => $deviceId]);
        } else {
            $clocking = $this->clockOut($employeeId, 'biometric', ['device_id' => $deviceId]);
        }

        $log->update(['clocking_id' => $clocking->id]);

        return $clocking;
    }

    // ── Facial Recognition Clocking ──────────────────────────────────────────

    public function captureFacialRecognitionAttendance(
        int $employeeId,
        string $deviceId,
        array $payload
    ): AttendanceClocking {
        $confidence = $payload['confidence'] ?? 0;
        $isMatched  = $confidence >= 80;
        $isLiveness = $payload['liveness_verified'] ?? false;

        $log = AttendanceBiometricLog::create([
            'employee_id'           => $employeeId,
            'biometric_type'        => 'facial',
            'device_id'             => $deviceId,
            'confidence_score'      => $confidence,
            'raw_response'          => $payload,
            'is_matched'            => $isMatched,
            'is_liveness_verified'  => $isLiveness,
            'failure_reason'        => !$isMatched ? 'Face not recognised' : (!$isLiveness ? 'Liveness check failed' : null),
            'captured_at'           => now(),
        ]);

        $this->logDevice($employeeId, $deviceId, 'facial_recognition', $payload);

        if (!$isMatched || !$isLiveness) {
            throw new \RuntimeException('Facial recognition failed: ' . ($log->failure_reason ?? 'Unknown error'));
        }

        $type = $this->resolveClockType($employeeId);
        if ($type === 'clock_in') {
            $clocking = $this->clockIn($employeeId, 'facial', 'office', ['device_id' => $deviceId]);
        } else {
            $clocking = $this->clockOut($employeeId, 'facial', ['device_id' => $deviceId]);
        }

        $log->update(['clocking_id' => $clocking->id]);

        return $clocking;
    }

    // ── GPS/Mobile Clocking ──────────────────────────────────────────────────

    public function captureGPSAttendance(
        int $employeeId,
        float $latitude,
        float $longitude,
        string $workType = 'remote',
        array $meta = []
    ): AttendanceClocking {
        $isGeofenced = $this->checkGeofence($latitude, $longitude);

        $type = $this->resolveClockType($employeeId);
        $gpsMeta = array_merge($meta, ['latitude' => $latitude, 'longitude' => $longitude]);
        if ($type === 'clock_in') {
            $clocking = $this->clockIn($employeeId, 'gps', $workType, $gpsMeta);
        } else {
            $clocking = $this->clockOut($employeeId, 'gps', $gpsMeta);
        }

        AttendanceGpsLog::create([
            'employee_id'       => $employeeId,
            'clocking_id'       => $clocking->id,
            'latitude'          => $latitude,
            'longitude'         => $longitude,
            'accuracy_meters'   => $meta['accuracy'] ?? null,
            'address'           => $meta['address'] ?? null,
            'is_within_geofence' => $isGeofenced['within'],
            'geofence_name'     => $isGeofenced['name'] ?? null,
            'work_site'         => $isGeofenced['site'] ?? null,
            'captured_at'       => now(),
        ]);

        return $clocking;
    }

    // ── Work-From-Home Clocking ──────────────────────────────────────────────

    public function captureWorkFromHomeAttendance(int $employeeId, array $meta = []): AttendanceClocking
    {
        $type = $this->resolveClockType($employeeId);
        $wfhMeta = array_merge($meta, [
            'location'  => $meta['location'] ?? 'Work From Home',
            'latitude'  => $meta['latitude']  ?? null,
            'longitude' => $meta['longitude'] ?? null,
        ]);

        if ($type === 'clock_in') {
            return $this->clockIn($employeeId, 'web', 'remote', $wfhMeta);
        }
        return $this->clockOut($employeeId, 'web', $wfhMeta);
    }

    // ── Break Time ───────────────────────────────────────────────────────────

    public function captureBreakTime(
        int $employeeId,
        string $action,
        string $breakType = 'other',
        ?string $notes = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $location = null
    ): AttendanceBreak {
        $now = Carbon::now($this->settings->timezone ?? config('app.timezone'));

        if ($action === 'start') {
            $openBreak = AttendanceBreak::forEmployee($employeeId)->open()->latest('break_start')->first();
            if ($openBreak) {
                throw new \RuntimeException('You already have an open break. End it before starting a new one.');
            }

            $clockIn = AttendanceClocking::forEmployee($employeeId)
                ->clockIns()
                ->whereDate('clocked_at', $now->toDateString())
                ->latest('clocked_at')
                ->first();

            $clockingBreakStart = AttendanceClocking::create([
                'employee_id'          => $employeeId,
                'attendance_record_id' => $clockIn?->attendance_record_id,
                'type'                 => 'break_start',
                'method'               => 'manual',
                'work_type'            => $clockIn?->work_type ?? 'office',
                'clocked_at'           => $now,
                'is_verified'          => true,
            ]);

            return AttendanceBreak::create([
                'employee_id'          => $employeeId,
                'attendance_record_id' => $clockIn?->attendance_record_id,
                'clock_in_clocking_id' => $clockingBreakStart->id,
                'break_type'           => $breakType,
                'break_start'          => $now,
                'is_open'              => true,
                'notes'                => $notes,
                'latitude'             => $latitude,
                'longitude'            => $longitude,
                'location'             => $location,
            ]);
        }

        // End break
        $break = AttendanceBreak::forEmployee($employeeId)->open()->latest('break_start')->firstOrFail();
        return $this->closeBreakRecord($break, $now);
    }

    // ── Detect Missed Clockings ──────────────────────────────────────────────

    public function detectMissedClocking(string $date): array
    {
        $settings  = $this->settings;
        $threshold = Carbon::parse($date . ' ' . $settings->work_end_time)->addHour();

        if (now()->lt($threshold)) {
            return ['skipped' => true, 'reason' => 'Day not yet complete'];
        }

        $employeesWithClockIn = AttendanceClocking::clockIns()
            ->whereDate('clocked_at', $date)
            ->pluck('employee_id')
            ->unique();

        $missedOut = [];

        foreach ($employeesWithClockIn as $employeeId) {
            $hasClockOut = AttendanceClocking::forEmployee($employeeId)
                ->clockOuts()
                ->whereDate('clocked_at', $date)
                ->exists();

            if (!$hasClockOut) {
                AttendanceClocking::create([
                    'employee_id' => $employeeId,
                    'type'        => 'clock_out',
                    'method'      => 'manual',
                    'work_type'   => 'office',
                    'clocked_at'  => Carbon::parse($date . ' ' . $settings->work_end_time),
                    'is_missed'   => true,
                    'notes'       => 'Auto-detected missing clock-out',
                ]);
                $missedOut[] = $employeeId;
                rescue(fn() => app(AttendanceNotificationService::class)->missingClockOut($employeeId, $date));
            }

            // Detect missing clock-in (record exists but no clocking)
            $record = AttendanceRecord::forEmployee($employeeId)->forDate($date)->first();
            if ($record && !$record->clock_in) {
                AttendanceClocking::create([
                    'employee_id'          => $employeeId,
                    'attendance_record_id' => $record->id,
                    'type'                 => 'clock_in',
                    'method'               => 'manual',
                    'work_type'            => 'office',
                    'clocked_at'           => Carbon::parse($date . ' ' . $settings->work_start_time),
                    'is_missed'            => true,
                    'notes'                => 'Auto-detected missing clock-in',
                ]);
            }
        }

        return ['detected' => count($missedOut), 'employee_ids' => $missedOut];
    }

    public function resolveClockTypePublic(int $employeeId): string
    {
        return $this->resolveClockType($employeeId);
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    private function broadcastClocking(AttendanceClocking $clocking, ?AttendanceRecord $record = null): void
    {
        rescue(function () use ($clocking, $record) {
            $person = HRPerson::find($clocking->employee_id);
            $name   = $person?->full_name ?? "Employee #{$clocking->employee_id}";
            $isLate = $record?->is_late ?? false;

            event(new ClockingRecorded(
                employeeId:   $clocking->employee_id,
                employeeName: $name,
                type:         $clocking->type,
                method:       $clocking->method,
                clockedAt:    $clocking->clocked_at->toIso8601String(),
                isLate:       $isLate,
                latitude:     $clocking->latitude,
                longitude:    $clocking->longitude,
            ));
        });
    }

    private function resolveOrCreateRecord(int $employeeId, Carbon $now, AttendanceClocking $clocking): AttendanceRecord
    {
        $settings = $this->settings;
        $startTime = Carbon::parse($now->toDateString() . ' ' . $settings->work_start_time);
        $graceCutoff = $startTime->copy()->addMinutes($settings->grace_period_minutes);
        $isLate = $now->gt($graceCutoff);

        $record = AttendanceRecord::firstOrCreate(
            ['employee_id' => $employeeId, 'date' => $now->toDateString()],
            [
                'source'    => $clocking->method,
                'clock_in'  => $now,
                'is_late'   => $isLate,
                'late_minutes' => $isLate ? (int) $startTime->diffInMinutes($now) : 0,
            ]
        );

        if (!$record->wasRecentlyCreated && !$record->clock_in) {
            $record->update(['clock_in' => $now, 'is_late' => $isLate]);
        }

        if ($isLate && $record->wasRecentlyCreated) {
            rescue(fn() => app(AttendanceNotificationService::class)
                ->lateArrival($employeeId, $now->toDateString(), (int) $startTime->diffInMinutes($now)));
        }

        return $record;
    }

    private function updateRecordHours(int $recordId, Carbon $clockIn, Carbon $clockOut): void
    {
        $record = AttendanceRecord::find($recordId);
        if (!$record) {
            return;
        }

        $totalBreakMinutes = AttendanceBreak::where('attendance_record_id', $recordId)
            ->where('is_open', false)
            ->sum('duration_minutes');

        $grossMinutes  = $clockIn->diffInMinutes($clockOut);
        $netMinutes    = max(0, $grossMinutes - $totalBreakMinutes);
        $hoursWorked   = round($netMinutes / 60, 2);

        $threshold = $this->settings->overtime_threshold_minutes;
        $standardMinutes = $this->settings->working_hours_per_day * 60;
        $overtimeMinutes = max(0, $netMinutes - $standardMinutes - $threshold);

        $overtimeHours = round($overtimeMinutes / 60, 2);

        // Early departure: left before work_end_time minus early_departure threshold
        $endTime       = Carbon::parse($clockOut->toDateString() . ' ' . $this->settings->work_end_time);
        $earlyThreshold = $this->settings->early_departure_threshold_minutes ?? 15;
        $earlyMinutes   = max(0, (int) $clockOut->diffInMinutes($endTime, false));
        $isEarly        = $clockOut->lt($endTime->copy()->subMinutes($earlyThreshold));

        $record->update([
            'clock_out'        => $clockOut,
            'hours_worked'     => $hoursWorked,
            'overtime_hours'   => $overtimeHours,
            'early_departure'  => $isEarly,
        ]);

        $notif = rescue(fn() => app(AttendanceNotificationService::class));
        if ($notif) {
            if ($isEarly) {
                rescue(fn() => $notif->earlyDeparture($record->employee_id, $clockOut->toDateString(), $earlyMinutes));
            }
            if ($overtimeHours > 0) {
                rescue(fn() => $notif->overtimeDetected($record->employee_id, $clockOut->toDateString(), $overtimeHours));
            }
        }
    }

    private function resolveClockType(int $employeeId): string
    {
        $today = Carbon::now($this->settings->timezone ?? config('app.timezone'))->toDateString();

        $lastClocking = AttendanceClocking::forEmployee($employeeId)
            ->whereDate('clocked_at', $today)
            ->whereIn('type', ['clock_in', 'clock_out'])
            ->latest('clocked_at')
            ->first();

        if (!$lastClocking || $lastClocking->type === 'clock_out') {
            return 'clock_in';
        }

        return 'clock_out';
    }

    private function closeOpenBreak(int $employeeId, Carbon $at): void
    {
        $openBreak = AttendanceBreak::forEmployee($employeeId)->open()->latest('break_start')->first();
        if ($openBreak) {
            $this->closeBreakRecord($openBreak, $at);
        }
    }

    private function closeBreakRecord(AttendanceBreak $break, Carbon $endTime): AttendanceBreak
    {
        $duration = (int) $break->break_start->diffInMinutes($endTime);

        $clockingBreakEnd = AttendanceClocking::create([
            'employee_id'          => $break->employee_id,
            'attendance_record_id' => $break->attendance_record_id,
            'type'                 => 'break_end',
            'method'               => 'manual',
            'work_type'            => 'office',
            'clocked_at'           => $endTime,
            'is_verified'          => true,
        ]);

        $break->update([
            'break_end'              => $endTime,
            'duration_minutes'       => $duration,
            'is_open'                => false,
            'clock_out_clocking_id'  => $clockingBreakEnd->id,
        ]);

        return $break->fresh();
    }

    private function saveGpsLog(int $employeeId, int $clockingId, array $meta): void
    {
        $isGeofenced = $this->checkGeofence($meta['latitude'] ?? 0, $meta['longitude'] ?? 0);

        AttendanceGpsLog::create([
            'employee_id'        => $employeeId,
            'clocking_id'        => $clockingId,
            'latitude'           => $meta['latitude'],
            'longitude'          => $meta['longitude'],
            'accuracy_meters'    => $meta['accuracy'] ?? null,
            'address'            => $meta['address'] ?? null,
            'is_within_geofence' => $isGeofenced['within'],
            'geofence_name'      => $isGeofenced['name'] ?? null,
            'work_site'          => $isGeofenced['site'] ?? null,
            'captured_at'        => now(),
        ]);
    }

    private function logDevice(int $employeeId, string $deviceId, string $deviceType, array $payload): void
    {
        AttendanceDeviceLog::create([
            'device_id'   => $deviceId,
            'device_type' => $deviceType,
            'employee_id' => $employeeId,
            'raw_data'    => $payload,
            'status'      => 'pending',
            'captured_at' => now(),
        ]);
    }

    private function checkGeofence(float $lat, float $lng): array
    {
        // Simple distance check against known office coordinates.
        // Extend this with a geofences table when multiple sites are needed.
        $officeGeofences = config('attendance.geofences', []);

        foreach ($officeGeofences as $fence) {
            $distance = $this->haversineDistance($lat, $lng, $fence['lat'], $fence['lng']);
            if ($distance <= ($fence['radius_meters'] ?? 200)) {
                return ['within' => true, 'name' => $fence['name'], 'site' => $fence['name']];
            }
        }

        return ['within' => false, 'name' => null, 'site' => null];
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
