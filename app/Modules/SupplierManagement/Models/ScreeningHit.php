<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One name that came back off a list, and what a person decided about it.
 *
 * The adjudication is the point. Sanctions and PEP screening produces false positives by design —
 * the lists are matched on names, and names repeat — so the useful question is never "did anything
 * match" but "is there anything here nobody has looked at yet". A hit cleared in January that comes
 * back every month makes continuous screening worthless within two cycles.
 *
 * So a hit is upserted, not re-created: seeing it again bumps `last_seen_at` and `times_seen` and
 * leaves the verdict alone. Only genuinely new names arrive as open.
 */
class ScreeningHit extends Model
{
    use BelongsToCompany;

    protected $table = 'screening_hits';

    protected $fillable = [
        'uuid', 'supplier_id', 'subject_type', 'subject_id', 'subject_name',
        'list_type', 'list_name', 'matched_name', 'external_ref',
        'match_score', 'match_basis', 'details', 'provider_code',
        'status', 'adjudicated_by', 'adjudicated_at', 'adjudication_note',
        'first_seen_at', 'last_seen_at', 'times_seen',
    ];

    protected $casts = [
        'match_score' => 'integer',
        'match_basis' => 'array',
        'details' => 'array',
        'times_seen' => 'integer',
        'adjudicated_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FALSE_POSITIVE = 'false_positive';

    public const STATUS_DISMISSED = 'dismissed';

    public const TYPE_SANCTIONS = 'sanctions';

    public const TYPE_PEP = 'pep';

    public const TYPE_ADVERSE = 'adverse_media';

    protected static function booted(): void
    {
        static::creating(function (self $hit) {
            $hit->uuid ??= (string) Str::uuid();
            $hit->first_seen_at ??= Carbon::now();
            $hit->last_seen_at ??= Carbon::now();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** Hits nobody has decided about — the only ones that should be holding anything up. */
    public function scopeUnadjudicated(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    /** Hits that still count against the supplier: open, or confirmed as real. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_CONFIRMED]);
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('list_type', $type);
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_FALSE_POSITIVE, self::STATUS_DISMISSED], true);
    }

    /**
     * Record a person's decision.
     *
     * A note is required for anything other than "confirmed", because "this is not our supplier" is
     * a judgement somebody will have to defend later, and an unexplained dismissal is indis-
     * tinguishable from a mis-click.
     */
    public function adjudicate(string $status, ?int $userId, ?string $note = null): self
    {
        $this->update([
            'status' => $status,
            'adjudicated_by' => $userId,
            'adjudicated_at' => Carbon::now(),
            'adjudication_note' => $note,
        ]);

        return $this;
    }
}
