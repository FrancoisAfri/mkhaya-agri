<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pre-use check on a machine (§22).
 *
 * Its value is the unbroken run of records rather than any single one: an inspector asked "when was
 * this last checked" needs a series, and a machine with a gap in the series is the finding. That is
 * why a passing inspection is still worth storing, and why these are not snags.
 */
class EquipmentInspection extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_equipment_inspections';

    public const RESULTS = ['pass', 'fail', 'conditional'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'external_ref_uuid', 'equipment', 'identifier',
        'inspected_on', 'inspector', 'result', 'checklist', 'defects', 'snag_id', 'next_due',
        'signed_by', 'signed_at', 'ip_address',
    ];

    protected $casts = [
        'inspected_on' => 'date',
        'next_due'     => 'date',
        'signed_at'    => 'datetime',
        'checklist'    => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** Due, or already past due — the state that stops a machine being used. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNotNull('next_due')->whereDate('next_due', '<=', now());
    }

    public function isOverdue(): bool
    {
        return $this->next_due !== null && $this->next_due->isPast();
    }
}
