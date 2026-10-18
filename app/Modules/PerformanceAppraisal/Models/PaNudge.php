<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaNudge extends Model
{
    protected $table = 'pa_nudges';

    protected $fillable = [
        'user_id', 'about_employee_id', 'cycle_id', 'type', 'priority',
        'title', 'body', 'action_route', 'data', 'dismissed_at', 'acted_at',
    ];

    protected $casts = [
        'data'         => 'array',
        'dismissed_at' => 'datetime',
        'acted_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function aboutEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'about_employee_id');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('dismissed_at');
    }
}
