<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pa360Rater extends Model
{
    protected $table = 'pa_360_raters';

    protected $fillable = [
        'review_id', 'rater_id', 'relationship', 'status',
        'nominated_by_employee', 'invited_at', 'submitted_at',
    ];

    protected $casts = [
        'nominated_by_employee' => 'boolean',
        'invited_at'            => 'datetime',
        'submitted_at'          => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Pa360Review::class, 'review_id');
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Pa360Response::class, 'rater_id');
    }
}
