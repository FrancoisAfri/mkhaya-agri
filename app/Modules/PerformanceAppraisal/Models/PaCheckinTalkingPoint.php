<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaCheckinTalkingPoint extends Model
{
    protected $table = 'pa_checkin_talking_points';

    protected $fillable = [
        'checkin_id', 'created_by', 'author_role', 'body', 'is_discussed', 'sort_order',
    ];

    protected $casts = [
        'is_discussed' => 'boolean',
    ];

    public function checkin(): BelongsTo
    {
        return $this->belongsTo(PaCheckin::class, 'checkin_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
