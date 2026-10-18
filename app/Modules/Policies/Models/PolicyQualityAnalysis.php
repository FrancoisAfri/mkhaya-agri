<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyQualityAnalysis extends Model
{
    protected $table = 'policy_quality_analyses';

    protected $fillable = [
        'policy_id',
        'analysed_by',
        'status',
        'suggestions',
        'summary',
        'issue_count',
        'completed_at',
    ];

    protected $casts = [
        'suggestions'  => 'array',
        'issue_count'  => 'integer',
        'completed_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analysed_by');
    }
}
