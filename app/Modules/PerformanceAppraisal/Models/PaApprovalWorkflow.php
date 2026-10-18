<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaApprovalWorkflow extends Model
{
    protected $table = 'pa_approval_workflows';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(PaApprovalWorkflowStep::class, 'workflow_id')->orderBy('step_order');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PaApprovalRequest::class, 'workflow_id');
    }
}
