<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaTemplate extends Model
{
    use LogsActivity;
    protected $table = 'pa_templates';

    protected $fillable = [
        'name', 'description', 'is_default', 'is_active', 'created_by',
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
                'created' => 'Appraisal Template created',
                'updated' => 'Appraisal Template updated',
                'deleted' => 'Appraisal Template deleted',
                default   => $e,
            });
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PaTemplateSection::class, 'template_id')->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(PaCycle::class, 'template_id');
    }
}
