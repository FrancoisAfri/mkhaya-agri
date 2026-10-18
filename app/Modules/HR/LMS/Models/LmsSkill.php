<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LmsSkill extends Model
{
    use SoftDeletes;

    protected $table = 'skills';

    public const CATEGORIES = [
        'technical'   => 'Technical',
        'soft'        => 'Soft Skills',
        'leadership'  => 'Leadership',
        'compliance'  => 'Compliance',
        'operational' => 'Operational',
    ];

    public const SOURCES = ['manual', 'training', 'assessment', 'manager'];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'max_level',
        'level_labels',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'level_labels' => 'array',
        'is_active'    => 'boolean',
        'max_level'    => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function jobTitleMappings(): HasMany
    {
        return $this->hasMany(LmsJobTitleSkill::class, 'skill_id');
    }

    public function courseMappings(): HasMany
    {
        return $this->hasMany(LmsCourseSkill::class, 'skill_id');
    }

    public function questionMappings(): HasMany
    {
        return $this->hasMany(LmsQuestionSkillMapping::class, 'skill_id');
    }

    public function employeeSkills(): HasMany
    {
        return $this->hasMany(LmsEmployeeSkill::class, 'skill_id');
    }

    public function levelLabel(int $level): string
    {
        $labels = $this->level_labels ?? [];
        return $labels[(string) $level] ?? "Level {$level}";
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
