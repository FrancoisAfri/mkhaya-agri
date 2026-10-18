<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaProbationCheckin extends Model
{
    protected $table = 'pa_probation_checkins';

    protected $fillable = [
        'review_id', 'checkin_type', 'scheduled_at', 'completed_at',
        'agenda', 'notes', 'action_items', 'status', 'created_by',
    ];

    protected $casts = [
        'scheduled_at'  => 'datetime',
        'completed_at'  => 'datetime',
        'action_items'  => 'array',
    ];

    public function review(): BelongsTo   { return $this->belongsTo(PaProbationReview::class, 'review_id'); }
    public function creator(): BelongsTo  { return $this->belongsTo(User::class, 'created_by'); }
}
