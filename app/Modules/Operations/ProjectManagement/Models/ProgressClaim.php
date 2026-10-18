<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A payment certificate (EPIC-100).
 *
 * How a contractor gets paid, and the reason the BOQ and the site diary are worth keeping. Every
 * figure below the gross is derived from the one above it, in the order a quantity surveyor works:
 *
 *   work to date + materials on site + variations   = gross
 *   gross − retention held                          = net of retention
 *   that − previously certified                     = due this certificate
 *
 * Retention sits on the claim rather than the project because the percentage changes between
 * certificates — typically halving at practical completion — and a single project-level figure
 * would be wrong the first time it moved, in the direction of overstating what is owed.
 */
class ProgressClaim extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_progress_claims';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'claim_number', 'period_start', 'period_end',
        'work_to_date', 'materials_on_site', 'variations', 'previously_certified',
        'retention_pct', 'retention_held', 'retention_released', 'net_due', 'currency',
        'milestone_task_id', 'status', 'submitted_at', 'certified_at', 'certified_by',
        'notes', 'document_id',
    ];

    protected $casts = [
        'period_start'         => 'date',
        'period_end'           => 'date',
        'work_to_date'         => 'decimal:2',
        'materials_on_site'    => 'decimal:2',
        'variations'           => 'decimal:2',
        'previously_certified' => 'decimal:2',
        'retention_pct'        => 'decimal:2',
        'retention_held'       => 'decimal:2',
        'retention_released'   => 'decimal:2',
        'net_due'              => 'decimal:2',
        'claim_number'         => 'integer',
        'submitted_at'         => 'datetime',
        'certified_at'         => 'datetime',
    ];

    public const STATUSES = ['draft', 'submitted', 'certified', 'paid', 'rejected'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'milestone_task_id');
    }

    /** Everything measured this certificate, before retention. */
    public function gross(): float
    {
        return round(
            (float) $this->work_to_date + (float) $this->materials_on_site + (float) $this->variations,
            2,
        );
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
