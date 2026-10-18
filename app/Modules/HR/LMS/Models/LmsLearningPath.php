<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LmsLearningPath extends Model
{
    use SoftDeletes;

    protected $table = 'lms_learning_paths';

    protected $fillable = [
        'uuid',
        'title',
        'description',
        'is_mandatory',
        'is_published',
        'created_by',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'is_published' => 'boolean',
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

    public function pathCourses(): HasMany
    {
        return $this->hasMany(LmsLearningPathCourse::class, 'path_id')->orderBy('sort_order');
    }

    public function courses(): HasManyThrough
    {
        return $this->hasManyThrough(
            LmsCourse::class,
            LmsLearningPathCourse::class,
            'path_id',
            'id',
            'id',
            'course_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
