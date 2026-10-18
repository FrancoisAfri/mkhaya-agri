<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmWorkflowRun extends Model
{
    protected $table = 'crm_workflow_runs';

    protected $fillable = [
        'workflow_id', 'entity_type', 'entity_id', 'status', 'detail',
    ];

    protected $casts = [
        'detail' => 'array',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(CrmWorkflow::class, 'workflow_id');
    }
}
