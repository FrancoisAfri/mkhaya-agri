<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkflowSettingsRow extends Model
{
    use BelongsToCompany;

    protected $table = 'workflow_settings';

    protected $guarded = ['id'];

    protected $casts = ['values' => 'array'];

    public static function requiresCompany(): bool
    {
        return true;
    }
}
