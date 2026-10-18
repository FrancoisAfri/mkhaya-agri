<?php

namespace App\Modules\HR\Offboarding\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingClearance extends Model
{
    protected $table = 'offboarding_clearances';

    protected $fillable = [
        'plan_id',
        'department',
        'status',
        'signed_off_by',
        'signed_off_at',
        'notes',
    ];

    protected $casts = [
        'signed_off_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(OffboardingPlan::class, 'plan_id');
    }

    public function signedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }

    public function isCleared(): bool
    {
        return $this->status === 'cleared';
    }
}
