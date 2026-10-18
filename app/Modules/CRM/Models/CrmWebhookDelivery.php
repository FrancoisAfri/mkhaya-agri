<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmWebhookDelivery extends Model
{
    protected $table = 'crm_webhook_deliveries';

    protected $fillable = [
        'webhook_id', 'event', 'payload', 'status_code', 'success', 'error',
    ];

    protected $casts = [
        'payload'     => 'array',
        'success'     => 'boolean',
        'status_code' => 'integer',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(CrmWebhook::class, 'webhook_id');
    }
}
