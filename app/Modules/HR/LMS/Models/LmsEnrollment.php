<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LmsEnrollment extends Model
{
    use SoftDeletes;

    protected $table = 'lms_enrollments';

    protected $fillable = [
        'uuid',
        'user_id',
        'course_id',
        'status',
        'progress_pct',
        'score',
        'time_spent_mins',
        'enrolled_at',
        'started_at',
        'completed_at',
        'due_date',
    ];

    protected $casts = [
        'enrolled_at'  => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'due_date'     => 'date',
        'progress_pct'    => 'integer',
        'score'           => 'integer',
        'time_spent_mins' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LmsQuizAttempt::class, 'enrollment_id');
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(LmsCertificate::class, 'enrollment_id');
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function isPassed(): bool
    {
        return $this->status === 'completed' && $this->certificate()->exists();
    }
}
