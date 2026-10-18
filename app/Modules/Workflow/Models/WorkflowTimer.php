<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkflowTimer extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;
    protected $table = 'workflow_timers';

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'fire_at' => 'datetime', 'fired_at' => 'datetime'];

    public static function requiresCompany(): bool
    {
        return true;
    }
}
