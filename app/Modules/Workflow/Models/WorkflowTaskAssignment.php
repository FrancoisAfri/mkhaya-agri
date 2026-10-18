<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkflowTaskAssignment extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;
    protected $table = 'workflow_task_assignments';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    public static function requiresCompany(): bool
    {
        return true;
    }
}
