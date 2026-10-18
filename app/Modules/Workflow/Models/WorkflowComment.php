<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkflowComment extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;
    protected $table = 'workflow_comments';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    public static function requiresCompany(): bool
    {
        return true;
    }
}
