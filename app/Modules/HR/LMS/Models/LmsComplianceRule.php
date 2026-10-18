<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsComplianceRule extends Model
{
    use SoftDeletes, KeepsDatabaseDefaults;

    protected $table = 'lms_compliance_rules';

    public const SCOPE_TYPES = [
        'all'        => 'All Employees',
        'position'   => 'Job Title / Position',
        'department' => 'Department',
        'location'   => 'Location',
        'employee'   => 'Specific Employee',
    ];

    public const FREQUENCIES = [
        'once'      => 'Once Only',
        'annual'    => 'Annual',
        'biannual'  => 'Bi-Annual (every 6 months)',
        'quarterly' => 'Quarterly',
        'custom'    => 'Custom (days)',
    ];

    public const ESCALATE_TO = ['manager', 'hr', 'admin'];

    protected $fillable = [
        'course_id', 'name', 'description',
        'scope_type', 'scope_id', 'scope_value',
        'frequency', 'frequency_days', 'due_within_days', 'grace_period_days',
        'reminder_days_before', 'escalate_after_days', 'escalate_to_role',
        'restrict_actions', 'is_active', 'created_by',
    ];

    protected $casts = [
        'reminder_days_before' => 'array',
        'restrict_actions'     => 'array',
        'is_active'            => 'boolean',
        'due_within_days'      => 'integer',
        'grace_period_days'    => 'integer',
        'escalate_after_days'  => 'integer',
        'frequency_days'       => 'integer',
        'scope_id'             => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LmsComplianceAssignment::class, 'rule_id');
    }

    /** How many days between recurrences. Null = one-off. */
    public function recurrenceDays(): ?int
    {
        return match ($this->frequency) {
            'annual'    => 365,
            'biannual'  => 182,
            'quarterly' => 91,
            'custom'    => $this->frequency_days,
            default     => null,
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
