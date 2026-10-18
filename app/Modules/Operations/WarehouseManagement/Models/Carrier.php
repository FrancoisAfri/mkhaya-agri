<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A carrier and the driver that talks to it (WMS-170). Credentials are encrypted and never sent to a screen. */
class Carrier extends Model
{
    use BelongsToCompany;

    public const DRIVERS = ['MANUAL'];

    protected $table = 'wms_carriers';

    protected $fillable = ['uuid', 'company_id', 'code', 'name', 'driver', 'account_ref', 'credentials', 'settings', 'tracking_url', 'is_active'];

    protected $hidden = ['credentials'];

    protected $casts = ['credentials' => 'encrypted:array', 'settings' => 'array', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function rates(): HasMany { return $this->hasMany(CarrierRate::class, 'carrier_id')->orderBy('service')->orderBy('max_weight_kg'); }

    public function trackingLink(?string $tracking): ?string
    {
        return $this->tracking_url && $tracking ? str_replace('{tracking}', rawurlencode($tracking), $this->tracking_url) : null;
    }
}
