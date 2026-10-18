<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveRequestStatus;
use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveRequest extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    /**
     * Laravel derives a factory's class name from the model's namespace, which for a module model
     * means `Database\\Factories\\Modules\\HR\\Leave\\Models\\LeaveRequestFactory` — a path nothing lives at.
     * The factory is at `Database\\Factories\\LeaveRequestFactory`, so it is named here rather than moved:
     * every other caller already refers to it where it is.
     */
    protected static function newFactory(): \Database\Factories\LeaveRequestFactory
    {
        return \Database\Factories\LeaveRequestFactory::new();
    }

    protected $table = 'leave_requests';

    protected $fillable = [
        'uuid', 'request_number', 'employee_id', 'leave_type_id', 'leave_cycle_id',
        'request_mode', 'is_overtime', 'is_bulk',
        'start_date', 'end_date', 'start_time', 'end_time',
        'working_days', 'working_hours', 'hours_reserved',
        'status', 'current_approval_level', 'current_approver_id', 'submitted_by',
        'submitted_at', 'approved_at', 'rejected_at', 'cancelled_at', 'cancelled_by_user_id',
        'rejection_reason', 'cancellation_reason', 'notes',
        'escalation_count', 'escalated_at',
        'bcea_validated_at', 'bcea_validation_result',
        'ai_explanation', 'ai_processed_at',
        'payroll_synced_at', 'ta_synced_at',
    ];

    protected $casts = [
        'start_date'             => 'date',
        'end_date'               => 'date',
        'submitted_at'           => 'datetime',
        'approved_at'            => 'datetime',
        'rejected_at'            => 'datetime',
        'cancelled_at'           => 'datetime',
        'escalated_at'           => 'datetime',
        'bcea_validated_at'      => 'datetime',
        'ai_processed_at'        => 'datetime',
        'payroll_synced_at'      => 'datetime',
        'ta_synced_at'           => 'datetime',
        'bcea_validation_result' => 'array',
        'is_overtime'            => 'boolean',
        'is_bulk'                => 'boolean',
        'working_days'           => 'decimal:2',
        'working_hours'          => 'decimal:2',
        'hours_reserved'         => 'decimal:2',
        'current_approval_level' => 'integer',
        'escalation_count'       => 'integer',
        'status'                 => LeaveRequestStatus::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid           ??= (string) Str::uuid();
            $model->request_number ??= self::generateRequestNumber();
        });
    }

    public static function generateRequestNumber(): string
    {
        $year    = now()->year;
        $last    = self::whereYear('created_at', $year)->max('id') ?? 0;
        $seq     = str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
        return "LR-{$year}-{$seq}";
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(LeaveCycle::class, 'leave_cycle_id');
    }

    public function approvalLevels(): HasMany
    {
        return $this->hasMany(LeaveApprovalLevel::class)->orderBy('level');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LeaveDocument::class);
    }

    public function balanceTransactions(): HasMany
    {
        return $this->hasMany(LeaveBalanceTransaction::class, 'reference_id')
            ->where('reference_type', 'leave_request');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->whereIn('status', [
            LeaveRequestStatus::Pending->value,
            LeaveRequestStatus::PendingManager->value,
            LeaveRequestStatus::PendingDepartment->value,
            LeaveRequestStatus::PendingHr->value,
            LeaveRequestStatus::PendingPayroll->value,
        ]);
    }

    public function scopeForApprover($query, int $userId)
    {
        return $query->where('current_approver_id', $userId)->pending();
    }

    public function scopeForEmployee($query, int $hrPersonId)
    {
        return $query->where('employee_id', $hrPersonId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveRequest')
            ->logOnly(['status', 'current_approval_level', 'rejection_reason', 'approved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => "Leave request {$this->request_number} submitted",
                'updated' => "Leave request {$this->request_number} updated",
                'deleted' => "Leave request {$this->request_number} cancelled",
                default   => "Leave request {$this->request_number}: {$e}",
            });
    }
}
