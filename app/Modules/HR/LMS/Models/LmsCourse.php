<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LmsCourse extends Model
{
    use SoftDeletes;

    protected $table = 'lms_courses';

    protected $fillable = [
        'uuid',
        'title',
        'description',
        'category_id',
        'cover_image',
        'type',
        'duration_mins',
        'pass_score',
        'expires_after_days',
        'is_mandatory',
        'is_published',
        'created_by',
        // QCTO / NQF / SETA fields
        'nqf_level',
        'credits',
        'saqa_id',
        'unit_standard_code',
        'ofo_code',
        'seta_id',
        'seta_accreditation_number',
        'seta_accreditation_expires_at',
        'qualification_component',
        'is_qcto_registered',
        'is_accredited',
    ];

    protected $casts = [
        'is_mandatory'                  => 'boolean',
        'is_published'                  => 'boolean',
        'duration_mins'                 => 'integer',
        'pass_score'                    => 'integer',
        'expires_after_days'            => 'integer',
        'is_qcto_registered'            => 'boolean',
        'is_accredited'                 => 'boolean',
        'nqf_level'                     => 'integer',
        'credits'                       => 'decimal:2',
        'seta_accreditation_expires_at' => 'date',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(LmsCategory::class, 'category_id');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(LmsCourseContent::class, 'course_id')->orderBy('sort_order');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(LmsEnrollment::class, 'course_id');
    }

    public function quizQuestions(): HasMany
    {
        return $this->hasMany(LmsQuizQuestion::class, 'course_id')->orderBy('sort_order');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(LmsCourseModule::class, 'course_id')->orderBy('sort_order');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(LmsLesson::class, 'course_id')->orderBy('sort_order');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(LmsCourseMaterial::class, 'course_id')->orderBy('sort_order');
    }

    public function prerequisites(): HasMany
    {
        return $this->hasMany(LmsCoursePrerequisite::class, 'course_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function enrolledCount(): int
    {
        return $this->enrollments()->count();
    }

    public function completionRate(): float
    {
        $total = $this->enrollments()->count();
        if ($total === 0) {
            return 0.0;
        }
        $completed = $this->enrollments()->where('status', 'completed')->count();
        return round(($completed / $total) * 100, 1);
    }

    public function isEnrolled(int $userId): bool
    {
        return $this->enrollments()->where('user_id', $userId)->exists();
    }
}
