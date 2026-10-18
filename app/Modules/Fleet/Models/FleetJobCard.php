<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A workshop job card: the record of work carried out on a vehicle, from the
 * reported fault through approval, parts consumption and the mechanic's
 * feedback, to closure.
 */
class FleetJobCard extends Model
{
    use SoftDeletes, LogsActivity, BelongsToCompany;

    protected $table = 'fleet_job_cards';

    /** Statuses a card moves through. */
    public const STATUS_DRAFT             = 'draft';
    public const STATUS_PENDING_APPROVAL  = 'pending_approval';
    public const STATUS_APPROVED          = 'approved';
    public const STATUS_IN_PROGRESS       = 'in_progress';
    public const STATUS_AWAITING_FEEDBACK = 'awaiting_feedback';
    public const STATUS_COMPLETED         = 'completed';
    public const STATUS_CLOSED            = 'closed';
    public const STATUS_REJECTED          = 'rejected';
    public const STATUS_CANCELLED         = 'cancelled';

    /** Statuses that mean the card is finished and must not be edited further. */
    public const TERMINAL = [self::STATUS_CLOSED, self::STATUS_REJECTED, self::STATUS_CANCELLED];

    protected $fillable = [
        'uuid', 'company_id', 'card_number', 'vehicle_id', 'maintenance_id', 'driver_id',
        'mechanic_id', 'supplier_id', 'title', 'fault_description', 'work_requested',
        'work_performed', 'priority', 'status', 'odometer', 'opened_date', 'due_date',
        'started_at', 'completed_at', 'closed_at', 'closed_by', 'cancelled_at',
        'cancelled_by', 'cancellation_reason', 'labour_hours', 'labour_rate',
        'labour_cost', 'parts_cost', 'other_cost', 'total_cost', 'invoice_number',
        'mechanic_feedback', 'mechanic_feedback_at', 'current_step',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'opened_date'          => 'date',
        'due_date'             => 'date',
        'started_at'           => 'datetime',
        'completed_at'         => 'datetime',
        'closed_at'            => 'datetime',
        'cancelled_at'         => 'datetime',
        'mechanic_feedback_at' => 'datetime',
        'odometer'             => 'decimal:1',
        'labour_hours'         => 'decimal:2',
        'labour_rate'          => 'decimal:2',
        'labour_cost'          => 'decimal:2',
        'parts_cost'           => 'decimal:2',
        'other_cost'           => 'decimal:2',
        'total_cost'           => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            $m->uuid        ??= (string) Str::uuid();
            $m->opened_date ??= now()->toDateString();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Relations ─────────────────────────────────────────────────────────────
    public function vehicle(): BelongsTo     { return $this->belongsTo(FleetVehicle::class, 'vehicle_id'); }
    public function maintenance(): BelongsTo { return $this->belongsTo(FleetMaintenance::class, 'maintenance_id'); }
    public function driver(): BelongsTo      { return $this->belongsTo(FleetDriver::class, 'driver_id'); }
    public function creator(): BelongsTo     { return $this->belongsTo(User::class, 'created_by'); }

    public function parts(): HasMany        { return $this->hasMany(FleetJobCardPart::class, 'job_card_id'); }
    public function serviceLines(): HasMany { return $this->hasMany(FleetJobCardServiceTypeLine::class, 'job_card_id'); }
    public function approvals(): HasMany    { return $this->hasMany(FleetJobCardApproval::class, 'job_card_id')->orderBy('sequence'); }
    public function notes(): HasMany        { return $this->hasMany(FleetJobCardNote::class, 'job_card_id')->latest(); }
    public function images(): HasMany       { return $this->hasMany(FleetJobCardImage::class, 'job_card_id'); }
    public function documents(): HasMany    { return $this->hasMany(FleetJobCardDocument::class, 'job_card_id'); }
    public function history(): HasMany      { return $this->hasMany(FleetJobCardHistory::class, 'job_card_id')->latest('performed_at'); }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeOpen($q)     { return $q->whereNotIn('status', self::TERMINAL); }
    public function scopeAwaitingApproval($q) { return $q->where('status', self::STATUS_PENDING_APPROVAL); }
    public function scopeForMechanic($q, $mechanicId) { return $q->where('mechanic_id', $mechanicId); }
    public function scopeOverdue($q)  { return $q->open()->whereNotNull('due_date')->whereDate('due_date', '<', now()); }

    // ── Derived values ────────────────────────────────────────────────────────
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /**
     * Recalculate parts/labour/total from the card's lines. Called after any
     * change to parts or service lines so the stored totals never drift.
     */
    public function recalculateTotals(): void
    {
        $partsCost = (float) $this->parts()->sum('line_total');
        $labourCost = (float) $this->labour_hours * (float) $this->labour_rate;
        if ($labourCost <= 0) {
            $labourCost = (float) $this->serviceLines()->sum('cost');
        }

        $this->parts_cost = $partsCost;
        $this->labour_cost = $labourCost;
        $this->total_cost = $partsCost + $labourCost + (float) $this->other_cost;
        $this->saveQuietly();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fleet')
            ->logOnly(['status', 'vehicle_id', 'mechanic_id', 'priority', 'total_cost', 'card_number', 'current_step'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
