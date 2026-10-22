<?php

namespace App\Modules\Agri\Models;

use Illuminate\Support\Facades\Crypt;
use Spatie\Activitylog\LogOptions;

/**
 * An outside system's subscription to farm events. Each delivery is an integration message
 * (integration "webhook"), signed with this subscription's secret and retried with back-off.
 */
class WebhookSubscription extends AgriModel
{
    protected $table = 'agri_webhook_subscriptions';

    protected $hidden = ['secret'];

    protected $casts = ['events' => 'array', 'is_active' => 'boolean', 'last_delivery_at' => 'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return parent::getActivitylogOptions()->logExcept(['updated_at', 'created_at', 'secret', 'deliveries', 'last_delivery_at', 'last_status']);
    }

    public function secret(): string
    {
        try {
            return Crypt::decryptString((string) $this->secret);
        } catch (\Throwable) {
            return '';
        }
    }

    public function putSecret(string $secret): void
    {
        $this->secret = Crypt::encryptString($secret);
    }

    public function wants(string $event): bool
    {
        return $this->is_active && (in_array($event, $this->events ?? [], true) || in_array('*', $this->events ?? [], true));
    }
}
