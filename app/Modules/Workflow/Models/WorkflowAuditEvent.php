<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkflowAuditEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;
    protected $table = 'workflow_audit_events';

    protected $guarded = ['id'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'context' => 'array', 'created_at' => 'datetime'];

    public static function requiresCompany(): bool
    {
        return true;
    }
}
