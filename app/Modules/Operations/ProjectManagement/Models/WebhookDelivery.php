<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened when we tried to deliver (EPIC-070).
 *
 * Kept separately from the outbox because "we sent it" and "they accepted it" are different claims,
 * and an integrator debugging at four in the afternoon needs the second one with a status code
 * against it.
 */
class WebhookDelivery extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_webhook_deliveries';

    protected $fillable = [
        'uuid', 'company_id', 'webhook_id', 'outbox_id', 'event_type',
        'status_code', 'duration_ms', 'attempt', 'error', 'delivered_at',
    ];

    protected $casts = [
        'status_code'  => 'integer',
        'duration_ms'  => 'integer',
        'attempt'      => 'integer',
        'delivered_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class, 'webhook_id');
    }
}
