<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowActionRun extends Model
{
    use BelongsToCompany;

    protected $table = 'workflow_action_runs';

    protected $guarded = ['id'];

    protected $casts = ['input' => 'array', 'output' => 'array', 'next_attempt_at' => 'datetime'];

    public static function requiresCompany(): bool
    {
        return true;
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }
}
