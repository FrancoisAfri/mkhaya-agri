<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaCycle extends Model
{
    use LogsActivity;
    protected $table = 'pa_cycles';

    protected $fillable = [
        'name', 'description', 'cycle_type', 'start_date', 'end_date',
        'self_review_deadline', 'manager_review_deadline', 'status',
        'rating_scale_id', 'template_id',
        'allow_self_review', 'allow_peer_review', 'require_manager_approval',
        'created_by',
    ];

    protected $casts = [
        'start_date'               => 'date',
        'end_date'                 => 'date',
        'self_review_deadline'     => 'date',
        'manager_review_deadline'  => 'date',
        'allow_self_review'        => 'boolean',
        'allow_peer_review'        => 'boolean',
        'require_manager_approval' => 'boolean',
    ];

    public function ratingScale(): BelongsTo
    {
        return $this->belongsTo(PaRatingScale::class, 'rating_scale_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PaTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Appraisal Cycle created',
                'updated' => 'Appraisal Cycle updated',
                'deleted' => 'Appraisal Cycle deleted',
                default   => $e,
            });
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
