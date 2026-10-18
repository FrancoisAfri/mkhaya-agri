<?php

namespace App\Modules\HR\Leave\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveType extends Model
{
    use SoftDeletes;
    use HasFactory, LogsActivity;

    /**
     * Laravel derives a factory's class name from the model's namespace, which for a module model
     * means `Database\\Factories\\Modules\\HR\\Leave\\Models\\LeaveTypeFactory` — a path nothing lives at.
     * The factory is at `Database\\Factories\\LeaveTypeFactory`, so it is named here rather than moved:
     * every other caller already refers to it where it is.
     */
    protected static function newFactory(): \Database\Factories\LeaveTypeFactory
    {
        return \Database\Factories\LeaveTypeFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveType')
            ->logOnly(['name', 'status', 'statutory_type', 'bcea_minimum_days', 'is_paid',
                       'carry_over_enabled', 'accrual_rate_hours_per_month'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => "Leave type '{$this->name}' created",
                'updated' => "Leave type '{$this->name}' updated",
                'deleted' => "Leave type '{$this->name}' deleted",
                default   => "Leave type '{$this->name}': {$e}",
            });
    }
    protected $table = 'leave_types';

    /**
     * The colour this leave type is drawn in, derived rather than stored.
     *
     * Three places asked a leave type for its colour and there was no column to answer with.
     * `leave_types` has never had one — no migration creates it and nothing writes it. One of
     * those places selected it and took the Apply page down with "column color does not exist";
     * the others read it off a model that had not loaded it, quietly got null, and painted every
     * leave type the same fallback shade.
     *
     * The colour was always a property of what kind of leave it is, so it is computed from that.
     * A derived attribute rather than a column because there is nothing for anybody to set: these
     * are the statutory categories, and they do not vary per tenant.
     *
     * Callers must select `statutory_type` for this to say anything; without it every type comes
     * back the neutral grey, which is the honest answer to "I was not told".
     */
    public function getColorAttribute(): string
    {
        return match ($this->statutory_type) {
            'annual'    => '#4CAF50',
            'sick'      => '#F44336',
            'maternity',
            'paternity',
            'parental'  => '#9C27B0',
            'study'     => '#2196F3',
            'special'   => '#FF9800',
            default     => '#607D8B',
        };
    }

    protected $fillable = [
        'name', 'status', 'description', 'special_leave',
        'doc_required', 'notes_required',
        // Phase 1 statutory fields
        'statutory_type', 'bcea_minimum_days', 'is_paid',
        'carry_over_enabled', 'carry_over_max_hours', 'forfeiture_after_days',
        'accrual_rate_hours_per_month', 'require_probation_period_days',
        'max_consecutive_hours', 'gender_restriction',
        'requires_medical_certificate', 'prevent_deletion',
        'is_birthday_leave',
    ];

    protected $casts = [
        'status'                      => 'integer',
        'special_leave'               => 'integer',
        'doc_required'                => 'integer',
        'notes_required'              => 'integer',
        'is_paid'                     => 'boolean',
        'carry_over_enabled'          => 'boolean',
        'requires_medical_certificate' => 'boolean',
        'prevent_deletion'            => 'boolean',
        'is_birthday_leave'           => 'boolean',
        'bcea_minimum_days'           => 'decimal:2',
        'carry_over_max_hours'        => 'decimal:2',
        'accrual_rate_hours_per_month' => 'decimal:2',
        'max_consecutive_hours'       => 'decimal:2',
    ];

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'leave_type_id');
    }
}

