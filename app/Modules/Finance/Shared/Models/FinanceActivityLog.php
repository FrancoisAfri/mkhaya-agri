<?php

namespace App\Modules\Finance\Shared\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'module', 'action', 'entity_type', 'entity_id',
        'performed_by', 'target_user_id', 'description',
        'ip_address', 'device', 'browser', 'metadata', 'risk_level', 'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
