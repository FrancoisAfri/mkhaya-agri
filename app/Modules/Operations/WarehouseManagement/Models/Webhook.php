<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** An endpoint subscribed to warehouse events. The secret signs every delivery and is shown once. */
class Webhook extends Model
{
    use BelongsToCompany;

    /** Consecutive failed deliveries after which the endpoint is switched off. */
    public const DISABLE_AFTER = 20;

    protected $table = 'wms_webhooks';

    protected $fillable = ['uuid', 'company_id', 'name', 'url', 'secret', 'secret_hint', 'event_types', 'warehouse_ids', 'is_active',
        'consecutive_failures', 'disabled_at', 'disabled_reason', 'last_delivered_at', 'created_by'];

    protected $hidden = ['secret'];

    protected $casts = ['secret' => 'encrypted', 'event_types' => 'array', 'warehouse_ids' => 'array', 'is_active' => 'boolean',
        'disabled_at' => 'datetime', 'last_delivered_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function wants(string $type, ?int $warehouseId): bool
    {
        return in_array($type, $this->event_types ?? [], true)
            && ($this->warehouse_ids === null || $this->warehouse_ids === [] || ($warehouseId !== null && in_array($warehouseId, array_map('intval', $this->warehouse_ids), true)));
    }
}
