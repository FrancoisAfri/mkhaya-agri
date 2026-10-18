<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single entity's run through a multi-step automation flow — tracks the
 * current step and when to resume after a wait.
 */
class CrmFlowRun extends Model
{
    protected $table = 'crm_flow_runs';

    protected $fillable = [
        'workflow_id', 'entity_type', 'entity_id', 'current_step', 'current_node',
        'status', 'next_run_at', 'started_at', 'finished_at', 'last_error',
    ];

    protected $casts = [
        'current_step' => 'integer',
        'next_run_at'  => 'datetime',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(CrmWorkflow::class, 'workflow_id');
    }
}
