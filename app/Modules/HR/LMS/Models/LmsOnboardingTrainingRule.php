<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LmsOnboardingTrainingRule extends Model
{
    use SoftDeletes;

    protected $table = 'lms_onboarding_training_rules';

    public const RULE_TYPES = [
        'induction'  => 'General Induction',
        'department' => 'Department-Specific',
        'role_based' => 'Role / Position Based',
        'policy'     => 'Policy Training',
    ];

    public const SCOPE_TYPES = [
        'all'        => 'All New Employees',
        'department' => 'Specific Department',
        'position'   => 'Specific Job Title / Position',
    ];

    protected $fillable = [
        'course_id', 'rule_type', 'scope_type', 'scope_id', 'scope_label',
        'due_within_days', 'is_mandatory', 'blocks_onboarding_completion',
        'min_pass_score', 'min_understanding_score', 'is_active', 'created_by',
    ];

    protected $casts = [
        'due_within_days'             => 'integer',
        'is_mandatory'                => 'boolean',
        'blocks_onboarding_completion'=> 'boolean',
        'is_active'                   => 'boolean',
        'min_pass_score'              => 'integer',
        'min_understanding_score'     => 'integer',
        'scope_id'                    => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LmsOnboardingTrainingAssignment::class, 'rule_id');
    }

    public function ruleTypeLabel(): string
    {
        return self::RULE_TYPES[$this->rule_type] ?? $this->rule_type;
    }

    public function scopeTypeLabel(): string
    {
        return self::SCOPE_TYPES[$this->scope_type] ?? $this->scope_type;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
