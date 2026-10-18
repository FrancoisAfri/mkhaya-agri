<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetPushSubscription extends Model
{
    protected $table = 'fleet_push_subscriptions';

    protected $fillable = ['user_id', 'endpoint', 'p256dh_key', 'auth_key', 'device_label', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
