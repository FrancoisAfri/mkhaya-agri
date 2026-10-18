<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Shared\Traits\KeepsDatabaseDefaults;

class PaCompRecommendation extends Model
{
    use KeepsDatabaseDefaults;

    protected $table = 'pa_comp_recommendations';

    protected $fillable = [
        'comp_review_id', 'employee_id', 'performance_score', 'rating_band',
        'current_salary', 'suggested_merit_pct', 'final_merit_pct',
        'suggested_bonus_pct', 'final_bonus_pct', 'new_salary', 'bonus_amount',
        'status', 'justification',
    ];

    protected $casts = [
        'performance_score'   => 'float',
        'current_salary'      => 'float',
        'suggested_merit_pct' => 'float',
        'final_merit_pct'     => 'float',
        'suggested_bonus_pct' => 'float',
        'final_bonus_pct'     => 'float',
        'new_salary'          => 'float',
        'bonus_amount'        => 'float',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(PaCompReview::class, 'comp_review_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
