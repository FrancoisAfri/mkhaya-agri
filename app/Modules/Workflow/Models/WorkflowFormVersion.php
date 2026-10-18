<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowFormVersion extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;
    protected $table = 'workflow_form_versions';

    protected $guarded = ['id'];

    protected $casts = ['fields' => 'array'];

    public static function requiresCompany(): bool
    {
        return true;
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(WorkflowForm::class, 'form_id');
    }
}
