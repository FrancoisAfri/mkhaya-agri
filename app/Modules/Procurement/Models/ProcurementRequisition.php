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

class ProcurementRequisition extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'procurement_requisitions';

    protected $fillable = [
        'uuid', 'requisition_number', 'purchase_request_id', 'title', 'procurement_method',
        'officer_id', 'budget_id', 'supplier_id', 'rfx_event_id', 'budget_reservation_id',
        'reservation_confirmed', 'status', 'estimated_total', 'currency', 'notes', 'created_by',
    ];

    protected $casts = [
        'reservation_confirmed' => 'boolean',
        'estimated_total'       => 'decimal:2',
    ];

    /**
     * Addressed by uuid, never by the row id.
     *
     * A requisition is linked to from its request, its RFQ and the order it produced. A sequential id in that URL says how many a tenant has and invites a guess at the next
     * one; authorisation is what actually refuses it, but the id makes the guess free and makes an
     * accidental leak -- a pasted link, a screenshot, a referrer header -- carry more than it needs to.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(fn (ProcurementRequisition $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('Procurement')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProcurementRequisitionItem::class, 'procurement_requisition_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProcurementRequisitionAttachment::class, 'procurement_requisition_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ProcurementRequisitionHistory::class, 'procurement_requisition_id')->latest();
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function scopeFilter(Builder $q, array $filters): Builder
    {
        return $q
            ->when($filters['search'] ?? null, fn ($x, $s) => $x->where(fn ($w) =>
                $w->where('requisition_number', 'ilike', "%{$s}%")->orWhere('title', 'ilike', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($x, $v) => $x->where('status', $v))
            ->when($filters['method'] ?? null, fn ($x, $v) => $x->where('procurement_method', $v));
    }
}
