<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkflowDraft extends Model
{
    use BelongsToCompany;

    protected $table = 'workflow_drafts';

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array'];

    public static function requiresCompany(): bool
    {
        return true;
    }
}
