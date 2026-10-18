<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Pa360Review extends Model
{
    use LogsActivity;
    protected $table = 'pa_360_reviews';

    protected $fillable = [
        'employee_id', 'cycle_id', 'title', 'instructions', 'due_date',
        'require_anonymity', 'min_raters', 'max_raters', 'status',
        'question_ids', 'ai_summary', 'created_by',
    ];

    protected $casts = [
        'due_date'          => 'date',
        'require_anonymity' => 'boolean',
        'question_ids'      => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaCycle::class, 'cycle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function raters(): HasMany
    {
        return $this->hasMany(Pa360Rater::class, 'review_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Pa360Response::class, 'review_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => '360 Review created',
                'updated' => '360 Review updated',
                'deleted' => '360 Review deleted',
                default   => $e,
            });
    }

    public function completionRate(): float
    {
        $total     = $this->raters()->whereIn('status', ['pending', 'submitted'])->count();
        $submitted = $this->raters()->where('status', 'submitted')->count();

        return $total > 0 ? round(($submitted / $total) * 100, 1) : 0;
    }
}
