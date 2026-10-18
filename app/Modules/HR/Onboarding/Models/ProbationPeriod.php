<?php

namespace App\Modules\HR\Onboarding\Models;

use App\Modules\HR\Onboarding\Enums\ProbationStatus;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProbationPeriod extends Model
{
    protected $table = 'probation_periods';

    protected $fillable = [
        'hr_person_id',
        'start_date',
        'end_date',
        'extended_end_date',
        'duration_months',
        'status',
        'outcome',
        'outcome_notes',
        'next_review_date',
        'created_by',
    ];

    protected $casts = [
        'status'           => ProbationStatus::class,
        'start_date'       => 'date',
        'end_date'         => 'date',
        'extended_end_date' => 'date',
        'next_review_date' => 'date',
        'duration_months'  => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function hrPerson(): BelongsTo
    {
        return $this->belongsTo(HrPerson::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProbationReview::class)->orderBy('review_date');
    }
}
