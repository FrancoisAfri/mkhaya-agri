<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyReviewer extends Model
{
    use HasFactory;

    protected $table = 'policy_reviewers';

    protected $fillable = [
        'policy_id',
        'user_id',
        'role',
        'assigned_at',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
