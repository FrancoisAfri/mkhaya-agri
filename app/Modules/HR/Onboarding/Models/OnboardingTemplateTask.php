<?php

namespace App\Modules\HR\Onboarding\Models;

use App\Modules\HR\Onboarding\Enums\OnboardingTaskType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OnboardingTemplateTask extends Model
{
    use LogsActivity;
    protected $table = 'onboarding_template_tasks';

    protected $fillable = [
        'onboarding_template_id',
        'type',
        'title',
        'description',
        'day_offset',
        'owner_type',
        'sort_order',
        // Needed when copying a catalogue template: the copy's tasks must carry the owning tenant
        // or the RLS write policy rejects them.
        'company_id',
    ];

    protected $casts = [
        'type'       => OnboardingTaskType::class,
        'day_offset' => 'integer',
        'sort_order' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Onboarding')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'onboarding_template_id');
    }
}
