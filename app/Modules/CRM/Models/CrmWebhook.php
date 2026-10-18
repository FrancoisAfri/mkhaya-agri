<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmWebhook extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'crm_webhooks';

    protected $fillable = [
        'uuid', 'name', 'url', 'events', 'secret', 'is_active', 'last_triggered_at', 'created_by',
    ];

    protected $casts = [
        'events'            => 'array',
        'is_active'         => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    protected $hidden = ['secret'];

    /** Events a webhook can subscribe to. */
    const EVENTS = [
        'lead.created'       => 'Lead created',
        'deal.created'       => 'Deal created',
        'deal.stage_changed' => 'Deal stage changed',
        'deal.won'           => 'Deal won',
        'deal.lost'          => 'Deal lost',
        'activity.completed' => 'Activity completed',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(CrmWebhookDelivery::class, 'webhook_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
