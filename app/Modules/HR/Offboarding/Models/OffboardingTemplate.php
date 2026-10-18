<?php

namespace App\Modules\HR\Offboarding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class OffboardingTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'offboarding_templates';

    protected $fillable = [
        'title',
        'description',
        'notice_period_days',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function templateTasks(): HasMany
    {
        return $this->hasMany(OffboardingTemplateTask::class, 'template_id')->orderBy('sort_order');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(OffboardingPlan::class, 'template_id');
    }
}
