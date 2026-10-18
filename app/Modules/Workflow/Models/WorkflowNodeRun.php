<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowNodeRun extends Model
{
    use BelongsToCompany;

    public $timestamps = false;
    protected $table = 'workflow_node_runs';

    protected $guarded = ['id'];

    protected $casts = ['detail' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public static function requiresCompany(): bool
    {
        return true;
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }
}
