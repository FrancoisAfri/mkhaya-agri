<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use App\Support\Eloquent\AliasesColumns;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shift as T&A defines it (attendance_shifts), read the way the roster uses it: when it starts and
 * ends, its break, whether the break is paid, the certifications it needs and its minimum staff.
 */
class ShiftTemplate extends EngineModel
{
    use AliasesColumns, SoftDeletes;

    public const KINDS = ['day', 'night', 'split', 'rotating', 'weekend', 'public_holiday', 'seasonal', 'emergency', 'custom'];

    protected $table = 'attendance_shifts';

    protected $casts = ['required_certifications' => 'array', 'paid_break' => 'boolean', 'status' => 'boolean', 'is_overnight' => 'boolean'];

    protected function columnAliases(): array
    {
        return ['starts' => 'start_time', 'ends' => 'end_time', 'break_minutes' => 'break_duration_minutes', 'is_active' => 'status'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (ShiftTemplate $t) {
            $a = $t->getAttributes();
            if (isset($a['start_time'], $a['end_time'])) {
                $t->attributes['is_overnight'] = substr((string) $a['end_time'], 0, 5) <= substr((string) $a['start_time'], 0, 5);
            }
        });
    }
}
