<?php

namespace App\Modules\HR\Offboarding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Shared\Traits\KeepsDatabaseDefaults;

class OffboardingTemplateTask extends Model
{
    use KeepsDatabaseDefaults;

    protected $table = 'offboarding_template_tasks';

    protected $fillable = [
        'template_id',
        'title',
        'description',
        'category',
        'assigned_role',
        'days_before_last_day',
        'sort_order',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(OffboardingTemplate::class, 'template_id');
    }
}
