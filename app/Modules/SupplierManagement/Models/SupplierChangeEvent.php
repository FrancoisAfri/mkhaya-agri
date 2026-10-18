<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Something about a supplier moved.
 *
 * Distinct from an observation on purpose. An observation says what a source reported; a change says
 * that report differs from the last one, by how much, and that nobody has looked at it. Only the
 * second is work — and burying it among a hundred identical-looking observations is how continuous
 * monitoring becomes a log nobody opens.
 */
class SupplierChangeEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'supplier_change_events';

    protected $fillable = [
        'uuid', 'supplier_id', 'category', 'check_type', 'field', 'previous_value', 'current_value',
        'severity', 'summary', 'detected_by', 'verification_id', 'monitoring_run_id',
        'triggered_reassessment', 'risk_before', 'risk_after',
        'status', 'reviewed_by', 'reviewed_at', 'review_note', 'detected_at',
    ];

    protected $casts = [
        'triggered_reassessment' => 'boolean',
        'risk_before' => 'decimal:2',
        'risk_after' => 'decimal:2',
        'detected_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_ACTIONED = 'actioned';
    public const STATUS_DISMISSED = 'dismissed';

    /** The kinds of change worth telling somebody about. */
    public const CATEGORIES = [
        'directors' => ['label' => 'Directors', 'severity' => 'high'],
        'ownership' => ['label' => 'Ownership', 'severity' => 'high'],
        'company_status' => ['label' => 'Company status', 'severity' => 'critical'],
        'company_name' => ['label' => 'Company name', 'severity' => 'high'],
        'address' => ['label' => 'Registered address', 'severity' => 'low'],
        'banking' => ['label' => 'Banking details', 'severity' => 'critical'],
        'tax' => ['label' => 'Tax status', 'severity' => 'high'],
        'credit' => ['label' => 'Credit standing', 'severity' => 'high'],
        'screening' => ['label' => 'Screening', 'severity' => 'critical'],
        'bbbee' => ['label' => 'B-BBEE', 'severity' => 'medium'],
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->uuid ??= (string) Str::uuid();
            $event->detected_at ??= Carbon::now();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function scopeUnreviewed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeSerious(Builder $query): Builder
    {
        return $query->whereIn('severity', ['high', 'critical']);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category]['label'] ?? $this->category;
    }

    public function riskMovement(): ?float
    {
        return $this->risk_before === null || $this->risk_after === null
            ? null
            : round((float) $this->risk_after - (float) $this->risk_before, 2);
    }

    public function review(string $status, ?int $userId, ?string $note = null): self
    {
        $this->update([
            'status' => $status,
            'reviewed_by' => $userId,
            'reviewed_at' => Carbon::now(),
            'review_note' => $note,
        ]);

        return $this;
    }
}
