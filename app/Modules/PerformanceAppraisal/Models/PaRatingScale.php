<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaRatingScale extends Model
{
    use LogsActivity;
    protected $table = 'pa_rating_scales';

    protected $fillable = [
        'name', 'description', 'min_score', 'max_score', 'is_default', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Rating Scale created',
                'updated' => 'Rating Scale updated',
                'deleted' => 'Rating Scale deleted',
                default   => $e,
            });
    }

    public function values(): HasMany
    {
        return $this->hasMany(PaRatingValue::class, 'rating_scale_id')->orderBy('sort_order');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(PaCycle::class, 'rating_scale_id');
    }
}
