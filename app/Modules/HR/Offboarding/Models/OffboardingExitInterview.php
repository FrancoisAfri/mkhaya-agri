<?php

namespace App\Modules\HR\Offboarding\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OffboardingExitInterview extends Model
{
    protected $table = 'offboarding_exit_interviews';

    protected $fillable = [
        'plan_id',
        'conducted_by',
        'interview_date',
        'reason_for_leaving',
        'satisfaction_score',
        'would_recommend',
        'strengths',
        'improvements',
        'additional_feedback',
        'is_anonymous',
    ];

    protected $casts = [
        'interview_date'   => 'date',
        'satisfaction_score' => 'integer',
        'would_recommend'  => 'boolean',
        'is_anonymous'     => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(OffboardingPlan::class, 'plan_id');
    }

    public function conductedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }
}
