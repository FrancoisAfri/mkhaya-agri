<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use App\Modules\Budget\Models\Budget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseRequest extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'purchase_requests';

    protected $fillable = [
        'uuid', 'request_number', 'requester_id', 'department', 'project_code',
        'budget_id', 'urgency', 'is_emergency', 'justification', 'delivery_location',
        'needed_by', 'currency', 'estimated_total', 'status',
        'budget_reservation_id', 'reserved_amount', 'created_by',
    ];

    protected $casts = [
        'is_emergency'    => 'boolean',
        'needed_by'       => 'date',
        'estimated_total' => 'decimal:2',
        'reserved_amount' => 'decimal:2',
    ];

    /**
     * Addressed by uuid, never by the row id.
     *
     * A purchase request is the start of the spend chain and is linked to from approvals. A sequential id in that URL says how many a tenant has and invites a guess at the next
     * one; authorisation is what actually refuses it, but the id makes the guess free and makes an
     * accidental leak -- a pasted link, a screenshot, a referrer header -- carry more than it needs to.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(fn (PurchaseRequest $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('Procurement')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class, 'purchase_request_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PurchaseRequestAttachment::class, 'purchase_request_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(PurchaseRequestHistory::class, 'purchase_request_id')->latest();
    }

    public function violations(): HasMany
    {
        return $this->hasMany(ProcurementPolicyViolation::class, 'source_id')->where('source_type', self::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function approval()
    {
        return $this->hasOne(ProcurementApproval::class, 'source_id')
            ->where('source_type', self::class)->latestOfMany();
    }

    public function scopeFilter(Builder $q, array $filters): Builder
    {
        return $q
            ->when($filters['search'] ?? null, fn ($x, $s) => $x->where(fn ($w) =>
                $w->where('request_number', 'ilike', "%{$s}%")->orWhere('justification', 'ilike', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($x, $v) => $x->where('status', $v))
            ->when($filters['urgency'] ?? null, fn ($x, $v) => $x->where('urgency', $v))
            ->when($filters['requester_id'] ?? null, fn ($x, $v) => $x->where('requester_id', $v));
    }

    public function recalcTotal(): void
    {
        $this->estimated_total = (float) $this->items()->sum('line_total');
        $this->saveQuietly();
    }
}
