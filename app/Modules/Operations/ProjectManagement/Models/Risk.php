<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something that has not happened yet, and what we intend to do about it (§11).
 *
 * `score` is probability × impact, written on save rather than computed on read, so the register
 * can be ordered by exposure in the database. Sorting in the browser only ever sorts the page you
 * are looking at, which puts the worst risk on page three.
 */
class Risk extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_risks';

    public const CATEGORIES = [
        'technical', 'commercial', 'schedule', 'safety', 'environmental',
        'legal', 'resource', 'external', 'quality', 'other',
    ];

    /** The four responses every standard names. Anything else is one of these under another word. */
    public const STRATEGIES = ['avoid', 'reduce', 'transfer', 'accept'];

    public const STATUSES = ['open', 'mitigating', 'closed', 'realised'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'code', 'title', 'description', 'category',
        'probability', 'impact', 'score', 'response_strategy', 'mitigation', 'contingency',
        'status', 'owner_user_id', 'review_due', 'closed_at', 'closure_note',
        'cost_impact', 'schedule_impact_days', 'currency', 'created_by',
    ];

    protected $casts = [
        'probability'          => 'integer',
        'impact'               => 'integer',
        'score'                => 'integer',
        'schedule_impact_days' => 'integer',
        'cost_impact'          => 'decimal:2',
        'review_due'           => 'date',
        'closed_at'            => 'datetime',
    ];

    protected static function booted(): void
    {
        // Kept in step automatically: a score that disagrees with its own probability and impact is
        // worse than no score, because people trust the number and not the two columns beside it.
        static::saving(fn (Risk $r) => $r->score = (int) $r->probability * (int) $r->impact);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function issues()
    {
        return $this->hasMany(Issue::class, 'risk_id');
    }

    /** Still capable of hurting the project. Realised and closed risks are history, not exposure. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'mitigating']);
    }

    /** 15+ on a 25-point scale — the band that belongs in front of a steering committee. */
    public function isHigh(): bool
    {
        return $this->score >= 15;
    }

    /** Red / amber / green, decided once here so every screen bands it the same way. */
    public function band(): string
    {
        return $this->score >= 15 ? 'high' : ($this->score >= 8 ? 'medium' : 'low');
    }

    public function isOverdueForReview(): bool
    {
        return $this->review_due !== null
            && $this->review_due->isPast()
            && in_array($this->status, ['open', 'mitigating'], true);
    }
}
