<?php

namespace App\Modules\Finance\Claims\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Spatie\Activitylog\Traits\LogsActivity;

class Claim extends Model
{
    use SoftDeletes;
    use LogsActivity;
    protected $table = 'claims';

    protected static function booted(): void
    {
        static::creating(function (Claim $claim) {
            if (empty($claim->uuid)) {
                $claim->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid', 'reference_number', 'employee_id', 'category_id', 'title', 'description',
        'currency', 'total_amount', 'claim_date', 'submitted_at', 'approved_at',
        'rejected_at', 'paid_at', 'paid_by', 'status', 'current_approver_id', 'approval_level',
        'rejection_reason', 'notes', 'ai_flags', 'risk_score', 'risk_level',
        'ai_reviewed_at', 'created_by',
    ];

    protected $casts = [
        'claim_date'     => 'date',
        'submitted_at'   => 'datetime',
        'approved_at'    => 'datetime',
        'rejected_at'    => 'datetime',
        'paid_at'        => 'datetime',
        'ai_reviewed_at' => 'datetime',
        'ai_flags'       => 'array',
        'total_amount'   => 'decimal:2',
        'risk_score'     => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ClaimCategory::class, 'category_id');
    }

    public function currentApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_approver_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClaimItem::class, 'claim_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClaimAttachment::class, 'claim_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ClaimApproval::class, 'claim_id')->orderBy('level');
    }

    public function policyViolations(): HasMany
    {
        return $this->hasMany(ClaimPolicyViolation::class, 'claim_id');
    }

    public function riskScore(): HasOne
    {
        return $this->hasOne(ClaimRiskScore::class, 'claim_id');
    }

    public function travelTrips(): HasMany
    {
        return $this->hasMany(ClaimTravelTrip::class, 'claim_id')->orderBy('trip_date')->orderBy('departure_time');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function employeeName(): string
    {
        if (! $this->employee) return '—';
        $person = $this->employee->relationLoaded('person')
            ? $this->employee->getRelation('person')
            : $this->employee->person;
        $name = trim(($person?->first_name ?? '') . ' ' . ($person?->surname ?? ''));
        return $name !== '' ? $name : ($this->employee->email ?? '—');
    }

    public function recalculateTotal(): void
    {
        $itemsTotal  = $this->items()->sum('amount');
        $tripsTotal  = $this->travelTrips()->sum('total_amount');
        $this->total_amount = $itemsTotal + $tripsTotal;
        $this->save();
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'draft'        => 'grey',
            'submitted'    => 'blue',
            'under_review' => 'warning',
            'approved'     => 'success',
            'rejected'     => 'error',
            'paid'         => 'deep-purple',
            'cancelled'    => 'grey',
            default        => 'grey',
        };
    }

    public function getAgeInDaysAttribute(): int
    {
        return (int) $this->claim_date->diffInDays(now());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Claims')
            ->logOnly(['reference_number', 'title', 'status', 'total_amount', 'rejection_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    public function tapActivity(ActivityLog $activity, string $eventName): void
    {
        $user = Auth::user();
        $activity->causer_id   = $user?->id;
        $activity->causer_type = $user ? get_class($user) : null;
        $activity->properties  = $activity->properties->merge([
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'old'        => $activity->properties['old'] ?? [],
            'attributes' => $activity->properties['attributes'] ?? [],
        ]);
    }

    public static function generateReference(): string
    {
        $year   = now()->year;
        $latest = static::whereYear('created_at', $year)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('id') ?? 0;
        return 'CLM-' . $year . '-' . str_pad($latest + 1, 4, '0', STR_PAD_LEFT);
    }
}
