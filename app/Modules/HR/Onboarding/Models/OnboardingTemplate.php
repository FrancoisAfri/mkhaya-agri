<?php

namespace App\Modules\HR\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OnboardingTemplate extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'onboarding_templates';

    protected $fillable = [
        'name',
        'description',
        'department',
        'job_title',
        'employment_type',
        'is_active',
        'created_by',
        // Catalogue columns. `company_id` is fillable because copying a global template has to
        // stamp the copy with the tenant that now owns it — RLS would otherwise reject the insert.
        'company_id',
        'industry',
        'catalogue_key',
        'copied_from_template_id',
    ];

    /** A template the platform ships, readable by every tenant and editable by none. */
    public function isGlobal(): bool
    {
        return $this->company_id === null;
    }

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Onboarding')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function templateTasks(): HasMany
    {
        return $this->hasMany(OnboardingTemplateTask::class)->orderBy('sort_order');
    }

    /**
     * Return template tasks formatted for CreateOnboardingPlanAction.
     * The returned array matches the 'tasks' input shape that action expects.
     *
     * @param  \Illuminate\Support\Carbon  $startDate
     * @return array<int, array<string, mixed>>
     */
    public function toTaskArray(\Illuminate\Support\Carbon $startDate): array
    {
        return $this->templateTasks->map(function (OnboardingTemplateTask $templateTask) use ($startDate): array {
            return [
                'type'        => $templateTask->type->value,
                'title'       => $templateTask->title,
                'description' => $templateTask->description,
                'due_date'    => $startDate->copy()->addDays($templateTask->day_offset)->toDateString(),
                'assigned_to' => null,
            ];
        })->values()->all();
    }
}
