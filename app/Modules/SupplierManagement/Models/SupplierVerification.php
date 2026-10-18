<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One check, once, against one source — an observation, not a status.
 *
 * Rows are never updated. Re-running a check appends; the supplier's current position is the newest
 * row per check type, and everything older is the history that makes "this got worse" a statement
 * the system can make. That is the single change that turns onboarding into monitoring.
 */
class SupplierVerification extends Model
{
    use BelongsToCompany;

    protected $table = 'supplier_verifications';

    protected $fillable = [
        'uuid', 'supplier_id', 'subject_type', 'subject_id', 'subject_label',
        'check_type', 'provider_code', 'status', 'confidence',
        'claimed', 'observed', 'field_matches', 'reference', 'summary', 'note',
        'cost', 'checked_by', 'checked_at', 'valid_until',
    ];

    protected $casts = [
        'claimed' => 'array',
        'observed' => 'array',
        'field_matches' => 'array',
        'confidence' => 'integer',
        'cost' => 'decimal:2',
        'checked_at' => 'datetime',
        'valid_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            $record->uuid ??= (string) Str::uuid();
            $record->checked_at ??= Carbon::now();
        });

        // A failed check has to reach the risk flags procurement actually works from, and a passing
        // re-check has to clear the flag it raised. Both live in the observer.
        static::observe(\App\Modules\SupplierManagement\Observers\SupplierVerificationObserver::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'checked_by');
    }

    /**
     * The newest observation per check type for a supplier.
     *
     * A window function rather than a group-by so the whole row comes back, and rather than an
     * `is_current` flag so there is no denormalised state that can drift out of agreement with the
     * rows themselves.
     */
    public static function currentFor(int $supplierId): \Illuminate\Support\Collection
    {
        return static::query()
            ->where('supplier_id', $supplierId)
            ->orderBy('check_type')
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->get()
            ->unique('check_type')
            ->keyBy('check_type');
    }

    public function scopeCheck(Builder $query, string $checkType): Builder
    {
        return $query->where('check_type', $checkType);
    }

    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [
            VerificationStatus::MISMATCH,
            VerificationStatus::NOT_FOUND,
            VerificationStatus::MANUAL_REVIEW,
        ]);
    }

    public function passed(): bool
    {
        return VerificationStatus::passes($this->status);
    }

    /** Past its own freshness horizon — a verified answer about a moment that has moved on. */
    public function isStale(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /** Fields where the source disagreed with what the supplier told us. */
    public function mismatchedFields(): array
    {
        return array_keys(array_filter(
            $this->field_matches ?? [],
            fn ($m) => ($m['match'] ?? true) === false
        ));
    }

    public function checkLabel(): string
    {
        return CheckType::label($this->check_type);
    }
}
