<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use App\Support\Eloquent\AliasesColumns;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A clock-in or clock-out in T&A's clock log (attendance_clockings), as the engine records it: the
 * worker, how it was captured (phone, kiosk, badge, device, supervisor, correction), where, whether
 * it was verified, the phone's own id for a tap sent offline, and what superseded it.
 */
class ClockEvent extends SitedModel
{
    use AliasesColumns, SoftDeletes;

    public const METHODS = ['mobile', 'kiosk', 'qr', 'biometric', 'supervisor', 'correction', 'import', 'gate', 'manual', 'facial', 'gps'];

    protected $table = 'attendance_clockings';

    protected $casts = ['clocked_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'gps_verified' => 'boolean', 'captured_offline' => 'boolean', 'supervisor_confirmed' => 'boolean',
        'is_verified' => 'boolean', 'is_missed' => 'boolean', 'is_corrected' => 'boolean'];

    protected function columnAliases(): array
    {
        return ['occurred_at' => 'clocked_at'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (ClockEvent $e) {
            $e->attributes['employee_id'] ??= Worker::withoutGlobalScopes()->whereKey($e->attributes['worker_id'] ?? 0)->value('hr_person_id');
            $e->attributes['work_type'] ??= ! empty($e->attributes['farm_id']) ? 'site' : 'office';
            foreach (['gps_verified', 'captured_offline', 'supervisor_confirmed'] as $flag) {
                $e->attributes[$flag] = (bool) ($e->attributes[$flag] ?? false);
            }
            $e->attributes['is_verified'] = ($e->attributes['verification'] ?? null) === 'verified';
            $e->attributes['is_corrected'] = ($e->attributes['method'] ?? null) === 'correction';
        });
    }

    /** in | out, the engine's words for T&A's clock_in | clock_out. */
    public function getKindAttribute(): ?string
    {
        $type = $this->attributes['type'] ?? $this->attributes['kind'] ?? null;

        return match ($type) { 'clock_in', 'in' => 'in', 'clock_out', 'out' => 'out', default => $type };
    }

    public function setKindAttribute(?string $kind): void
    {
        $this->attributes['type'] = match ($kind) { 'in' => 'clock_in', 'out' => 'clock_out', default => $kind };
        unset($this->attributes['kind']);
    }

    public function worker(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id');
    }

    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        // Events are never edited; only a supersession is worth a log line.
        return parent::getActivitylogOptions()->logOnly(['superseded_by_id', 'supervisor_confirmed', 'verification']);
    }
}
