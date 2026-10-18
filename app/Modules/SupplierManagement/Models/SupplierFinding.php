<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One thing the world said about a supplier.
 *
 * Every field here that is not the article itself exists to answer a question somebody will ask about
 * it: is this really about our supplier (`entity_confidence`, `entity_basis`), how bad is it
 * (`category`, `severity`), did a machine decide that (`ai_generated`, `analysis_confidence`), is
 * anybody else reporting it (`corroborations`), and has a person looked (`status`).
 *
 * Positive findings are kept. A supplier winning a large contract is part of the picture, and a
 * system that files only bad news produces a charge sheet rather than an assessment — which is how
 * you end up with a reputation score that only ever goes one way.
 */
class SupplierFinding extends Model
{
    use BelongsToCompany;

    protected $table = 'supplier_findings';

    protected $fillable = [
        'uuid', 'supplier_id', 'channel', 'platform', 'source_name', 'url', 'url_hash',
        'external_id', 'published_at', 'author', 'title', 'excerpt', 'language',
        'category', 'severity', 'sentiment', 'is_positive', 'analysis_confidence',
        'ai_generated', 'analysis_note', 'entity_confidence', 'entity_basis', 'matched_subject',
        'corroborations', 'status', 'adjudicated_by', 'adjudicated_at', 'adjudication_note',
        'monitoring_run_id', 'provider_code', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'adjudicated_at' => 'datetime',
        'entity_basis' => 'array',
        'is_positive' => 'boolean',
        'ai_generated' => 'boolean',
        'entity_confidence' => 'integer',
        'analysis_confidence' => 'integer',
        'corroborations' => 'integer',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FALSE_POSITIVE = 'false_positive';

    public const STATUS_DISMISSED = 'dismissed';

    /** Below the entity-confidence floor: attributed to nobody until a person says otherwise. */
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /**
     * What a finding can be about.
     *
     * Taken from the specification, and grouped by what each one means for a procurement decision
     * rather than by subject matter — `regulatory_significance` is why a regulator's finding outweighs
     * a customer complaint even when both are "negative".
     */
    public const CATEGORIES = [
        'fraud' => ['label' => 'Fraud', 'weight' => 1.0, 'regulatory' => true],
        'corruption' => ['label' => 'Corruption', 'weight' => 1.0, 'regulatory' => true],
        'bribery' => ['label' => 'Bribery', 'weight' => 1.0, 'regulatory' => true],
        'criminal' => ['label' => 'Criminal allegation', 'weight' => 0.95, 'regulatory' => true],
        'sanctions' => ['label' => 'Sanctions or designation', 'weight' => 1.0, 'regulatory' => true],
        'regulatory' => ['label' => 'Regulatory action', 'weight' => 0.9, 'regulatory' => true],
        'insolvency' => ['label' => 'Insolvency or business rescue', 'weight' => 0.9, 'regulatory' => false],
        'financial_distress' => ['label' => 'Financial distress', 'weight' => 0.75, 'regulatory' => false],
        'litigation' => ['label' => 'Lawsuit', 'weight' => 0.7, 'regulatory' => false],
        'labour' => ['label' => 'Labour dispute', 'weight' => 0.55, 'regulatory' => false],
        'safety' => ['label' => 'Safety incident', 'weight' => 0.7, 'regulatory' => true],
        'environmental' => ['label' => 'Environmental damage', 'weight' => 0.7, 'regulatory' => true],
        'cyber' => ['label' => 'Cyber incident', 'weight' => 0.7, 'regulatory' => false],
        'data_breach' => ['label' => 'Data breach', 'weight' => 0.8, 'regulatory' => true],
        'customer_complaints' => ['label' => 'Customer complaints', 'weight' => 0.35, 'regulatory' => false],
        'product_failure' => ['label' => 'Product failure', 'weight' => 0.5, 'regulatory' => false],
        'reputation' => ['label' => 'Reputation damage', 'weight' => 0.45, 'regulatory' => false],
        'positive' => ['label' => 'Positive development', 'weight' => 0.0, 'regulatory' => false],
        'neutral' => ['label' => 'Neutral mention', 'weight' => 0.0, 'regulatory' => false],
    ];

    public const SEVERITIES = ['none' => 0.0, 'low' => 0.25, 'medium' => 0.55, 'high' => 0.8, 'critical' => 1.0];

    protected static function booted(): void
    {
        static::creating(function (self $finding) {
            $finding->uuid ??= (string) Str::uuid();
            $finding->first_seen_at ??= Carbon::now();
            $finding->last_seen_at ??= Carbon::now();

            if (! $finding->url_hash && $finding->url) {
                $finding->url_hash = self::hashUrl($finding->url);
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SupplierMonitoringRun::class, 'monitoring_run_id');
    }

    /**
     * The dedupe key.
     *
     * Normalised first, because the same article arrives with a tracking parameter one month and
     * without it the next, and two rows for one story both double-counts it in the score and gives a
     * cleared finding a second life.
     */
    public static function hashUrl(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('/^https?:\/\//', '', $url) ?? $url;
        $url = preg_replace('/[?&](utm_[a-z]+|fbclid|gclid|ref|source)=[^&]*/i', '', $url) ?? $url;

        return hash('sha256', rtrim(rtrim($url, '/'), '?&'));
    }

    /** Findings that still count: open, needing review, or confirmed. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_CONFIRMED, self::STATUS_NEEDS_REVIEW]);
    }

    public function scopeNegative(Builder $query): Builder
    {
        return $query->where('is_positive', false)->whereNotIn('category', ['positive', 'neutral']);
    }

    public function scopeUnadjudicated(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_NEEDS_REVIEW]);
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_FALSE_POSITIVE, self::STATUS_DISMISSED], true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category]['label'] ?? ($this->category ?: 'Unclassified');
    }

    /** Whether this category is one a regulator would act on — it outweighs volume of complaints. */
    public function isRegulatory(): bool
    {
        return (bool) (self::CATEGORIES[$this->category]['regulatory'] ?? false);
    }

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
