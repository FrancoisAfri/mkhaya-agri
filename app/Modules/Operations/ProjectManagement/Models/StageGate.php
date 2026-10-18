<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A decision point between stages (§2).
 *
 * This is what gives the project's `stage` field teeth. Without a gate, a stage is a label somebody
 * changes; with one, moving on is a decision with named criteria, a decider and a date — and a
 * project that skipped a gate is visible rather than merely undocumented.
 *
 * `waived` is a real outcome, deliberately. Gates do get skipped under commercial pressure, and a
 * system that offers only pass or fail gets the gate marked "passed" instead — which destroys the
 * one record that mattered.
 */
class StageGate extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_stage_gates';

    public const STATUSES = ['pending', 'passed', 'failed', 'waived'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'stage', 'name', 'criteria', 'position',
        'status', 'approval_uuid', 'decided_at', 'decided_by', 'decision_note',
    ];

    protected $casts = ['position' => 'integer', 'decided_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function isDecided(): bool
    {
        return $this->status !== 'pending';
    }
}
