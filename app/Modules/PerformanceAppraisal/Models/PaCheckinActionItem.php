<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaCheckinActionItem extends Model
{
    protected $table = 'pa_checkin_action_items';

    protected $fillable = [
        'checkin_id', 'owner_id', 'description', 'due_date', 'status',
        'carried_from_id', 'completed_at',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    public function checkin(): BelongsTo
    {
        return $this->belongsTo(PaCheckin::class, 'checkin_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
