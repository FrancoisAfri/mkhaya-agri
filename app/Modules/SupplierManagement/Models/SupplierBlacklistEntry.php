<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\SupplierManagement\DueDiligence\Services\FieldComparator;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An entity or person barred from being engaged.
 *
 * `supplier_id` is nullable on purpose: the useful blacklist entry is the one written before the
 * company is a supplier again. A bar that only exists on an existing supplier record is defeated by
 * the oldest trick there is — register a new company, same people, same address, apply again.
 *
 * Personal identifiers are stored hashed rather than in the clear. Matching a barred individual only
 * ever needs "is this the same number", which a hash answers, and a blacklist of ID numbers in
 * plaintext is a POPIA incident waiting for a database dump.
 */
class SupplierBlacklistEntry extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'supplier_blacklist';

    protected $fillable = [
        'uuid', 'supplier_id', 'entity_name', 'normalised_name', 'registration_number',
        'scope', 'person_id', 'person_identifier_hash', 'reason', 'evidence', 'severity',
        'decided_by', 'decided_role', 'decided_at', 'review_on', 'expires_on',
        'is_active', 'lifted_by', 'lifted_at', 'lift_reason',
    ];

    protected $casts = [
        'evidence' => 'array',
        'is_active' => 'boolean',
        'decided_at' => 'datetime',
        'review_on' => 'date',
        'expires_on' => 'date',
        'lifted_at' => 'datetime',
    ];

    public const SCOPE_ENTITY = 'entity';

    public const SCOPE_PERSON = 'person';

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $entry->uuid ??= (string) Str::uuid();
            $entry->decided_at ??= Carbon::now();
        });

        static::saving(function (self $entry) {
            $entry->normalised_name = $entry->entity_name
                ? app(FieldComparator::class)->normaliseEntityName($entry->entity_name)
                : null;
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** Live bars: active, and not past an expiry date. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', now()->toDateString()));
    }

    public function scopeDueReview(Builder $query): Builder
    {
        return $query->live()->whereNotNull('review_on')->whereDate('review_on', '<=', now()->toDateString());
    }

    /** Hash an identifier the same way every time, so matching works and the number is never stored. */
    public static function hashIdentifier(string $identifier): string
    {
        return hash('sha256', preg_replace('/\D+/', '', $identifier) ?: $identifier);
    }

    public function lift(?int $userId, string $reason): self
    {
        $this->update([
            'is_active' => false,
            'lifted_by' => $userId,
            'lifted_at' => Carbon::now(),
            'lift_reason' => $reason,
        ]);

        return $this;
    }
}
