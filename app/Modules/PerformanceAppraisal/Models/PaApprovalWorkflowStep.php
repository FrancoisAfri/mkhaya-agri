<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;

class PaApprovalWorkflowStep extends Model
{
    protected $table = 'pa_approval_workflow_steps';
    protected $guarded = [];

    protected $casts = [
        'is_required' => 'boolean',
        'step_order'  => 'integer',
    ];
}
