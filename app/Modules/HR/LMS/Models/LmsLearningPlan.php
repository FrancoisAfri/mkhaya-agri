<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LmsLearningPlan extends Model
{
    use SoftDeletes;

    protected $table = 'lms_learning_plans';

    public const TYPES    = ['learning_plan', 'revision_plan'];
    public const STATUSES = ['draft', 'active', 'completed', 'archived'];

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'plan_type',
        'status',
        'ai_generated',
        'ai_context',
        'target_completion_date',
        'completed_at',
        'generated_by',
    ];

    protected $casts = [
        'ai_generated'           => 'boolean',
        'ai_context'             => 'array',
        'target_completion_date' => 'date',
        'completed_at'           => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LmsLearningPlanItem::class, 'learning_plan_id')->orderBy('sort_order');
    }

    public function completionPct(): float
    {
        $total     = $this->items()->count();
        if ($total === 0) return 0.0;
        $completed = $this->items()->where('status', 'completed')->count();
        return round(($completed / $total) * 100, 1);
    }
}
