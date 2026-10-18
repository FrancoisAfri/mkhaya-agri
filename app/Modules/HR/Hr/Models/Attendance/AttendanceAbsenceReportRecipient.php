<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Someone who receives the daily absent-users report. Either an employee (whose
 * address is resolved at send time) or a plain email address for people who
 * aren't staff, such as an outsourced HR provider.
 */
class AttendanceAbsenceReportRecipient extends Model
{
    use BelongsToCompany;

    protected $table = 'attendance_absence_report_recipients';

    protected $fillable = ['uuid', 'company_id', 'employee_id', 'email', 'name', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** The address to send to, preferring the linked employee's own address. */
    public function resolvedEmail(): ?string
    {
        return $this->employee?->email ?: $this->email;
    }

    public function resolvedName(): string
    {
        if ($this->employee) {
            return trim($this->employee->first_name . ' ' . $this->employee->surname);
        }

        return $this->name ?: (string) $this->email;
    }
}
