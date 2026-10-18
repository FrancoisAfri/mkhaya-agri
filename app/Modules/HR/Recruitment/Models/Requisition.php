<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Core\Position\Models\HrPosition;
use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Enums\VacancyType;
use Database\Factories\Recruitment\RequisitionFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Requisition extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'recruitment_requisitions';

    protected static function newFactory(): Factory
    {
        return RequisitionFactory::new();
    }

    protected $fillable = [
        'uuid',
        'requisition_number',
        'title',
        'department',
        'position_id',
        'reporting_to',
        'job_type',
        'headcount',
        'reason',
        'job_description',
        'required_qualifications',
        'required_skills',
        'salary_band_min',
        'salary_band_max',
        'target_start_date',
        'status',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'converted_at',
        'cancelled_at',
        'approval_notes',
        'rejection_reason',
        'requested_by',
        'approved_by',
        'rejected_by',
    ];

    protected $casts = [
        'status'          => RequisitionStatus::class,
        'job_type'        => VacancyType::class,
        'required_skills' => 'array',
        'submitted_at'    => 'datetime',
        'approved_at'     => 'datetime',
        'rejected_at'     => 'datetime',
        'converted_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
        'target_start_date' => 'date',
        'salary_band_min' => 'decimal:2',
        'salary_band_max' => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'rejected_by');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function vacancy(): HasOne
    {
        return $this->hasOne(Vacancy::class, 'requisition_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeDraft($query)
    {
        return $query->where('status', RequisitionStatus::Draft->value);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', RequisitionStatus::PendingApproval->value);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', RequisitionStatus::Approved->value);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Generate a unique requisition number in the format REQ-YYYY-NNNN.
     */
    public static function generateRequisitionNumber(): string
    {
        $year  = date('Y');
        $count = static::withTrashed()->count() + 1;

        return 'REQ-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
