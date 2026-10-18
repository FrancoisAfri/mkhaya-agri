<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaProbationFeedback extends Model
{
    protected $table = 'pa_probation_feedback';

    protected $fillable = [
        'review_id', 'given_by', 'feedback_type', 'content', 'rating', 'is_draft',
    ];

    protected $casts = [
        'rating'   => 'decimal:1',
        'is_draft' => 'boolean',
    ];

    public function review(): BelongsTo   { return $this->belongsTo(PaProbationReview::class, 'review_id'); }
    public function author(): BelongsTo   { return $this->belongsTo(User::class, 'given_by'); }
}
