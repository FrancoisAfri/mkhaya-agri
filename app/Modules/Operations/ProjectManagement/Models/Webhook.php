<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant's subscription to its own domain events (EPIC-070).
 *
 * The secret is encrypted at rest and never serialised: `$hidden` keeps it out of every API
 * response and Inertia payload, and only the dispatcher reads it. It cannot be hashed — an HMAC
 * needs the plaintext — so the protection is encryption plus never putting it on a screen after the
 * moment it is created.
 */
class Webhook extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, SoftDeletes;

    /** Consecutive failures before the subscription is switched off and someone is told. */
    public const FAILURE_LIMIT = 20;

    protected $table = 'pm_webhooks';

    protected $fillable = [
        'uuid', 'company_id', 'name', 'url', 'secret', 'secret_hint', 'event_types',
        'is_active', 'consecutive_failures', 'disabled_at', 'disabled_reason',
        'last_delivered_at', 'created_by',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        // Encrypted going in, plaintext coming out, and only ever read by WebhookDispatcher.
        'secret'               => 'encrypted',
        'event_types'          => 'array',
        'is_active'            => 'boolean',
        'consecutive_failures' => 'integer',
        'disabled_at'          => 'datetime',
        'last_delivered_at'    => 'datetime',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** An empty subscription list means everything — the common case, and the useful default. */
    public function wants(string $eventType): bool
    {
        $types = $this->event_types ?: [];

        return $types === [] || in_array($eventType, $types, true);
    }
}
