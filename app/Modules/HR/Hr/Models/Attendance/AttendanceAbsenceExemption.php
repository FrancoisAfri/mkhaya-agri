<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An employee who is not expected to clock in, and so must never be treated as
 * absent by the ERS pipeline — field staff, executives, permanently remote staff.
 */
class AttendanceAbsenceExemption extends Model
{
    use BelongsToCompany;

    protected $table = 'attendance_absence_exemptions';

    protected $fillable = [
        'uuid', 'company_id', 'employee_id', 'reason',
        'effective_from', 'effective_to', 'is_active', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    /** Exemptions in force on the given date (defaults to today). */
    public function scopeInForce($query, ?string $date = null)
    {
        $date = $date ?: Carbon::today()->toDateString();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /** Employee ids exempt today — the list the absence detector filters against. */
    public static function activeEmployeeIds(?string $date = null): array
    {
        return static::inForce($date)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
