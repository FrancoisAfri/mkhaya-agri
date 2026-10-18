<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Practical, sectional or final completion (§23).
 *
 * A record with money on it: practical completion starts the defects liability period and usually
 * releases half the retention; final completion releases the rest. Sectional exists because large
 * jobs hand over in pieces, and each piece starts its own defects period — one project-level date
 * would be wrong for every section but the last.
 */
class CompletionRecord extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_completion_records';

    public const KINDS = ['practical', 'sectional', 'final'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'kind', 'section', 'certified_on', 'certified_by',
        'outstanding_work', 'defects_period_days', 'defects_period_ends', 'retention_released',
        'currency', 'document_uuid', 'notes', 'created_by',
    ];

    protected $casts = [
        'certified_on'        => 'date',
        'defects_period_ends' => 'date',
        'defects_period_days' => 'integer',
        'retention_released'  => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Derived on save when a period is given, so the date somebody has to diarise exists on the
        // record rather than in an arithmetic somebody does in their head.
        static::saving(function (CompletionRecord $r) {
            if ($r->defects_period_days && $r->certified_on && ! $r->defects_period_ends) {
                $r->defects_period_ends = $r->certified_on->copy()->addDays((int) $r->defects_period_days);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function isInDefectsPeriod(): bool
    {
        return $this->defects_period_ends !== null && $this->defects_period_ends->isFuture();
    }
}
