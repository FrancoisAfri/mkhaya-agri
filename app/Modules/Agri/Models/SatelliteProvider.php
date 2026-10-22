<?php

namespace App\Modules\Agri\Models;

use Illuminate\Support\Facades\Crypt;
use Spatie\Activitylog\LogOptions;

/**
 * An organisation's satellite imagery provider: its credentials (encrypted, never sent to a
 * browser or written to the audit log), its fetch settings and the last connection check.
 */
class SatelliteProvider extends AgriModel
{
    public const COPERNICUS = 'copernicus';
    public const AGROMONITORING = 'agromonitoring';
    public const KEYS = [self::COPERNICUS, self::AGROMONITORING];

    /** Fetch settings an organisation starts with. */
    public const DEFAULTS = [
        'max_cloud_pct' => 30,          // over the field, after cloud masking; above it a scene is skipped
        'daily_budget' => 200,          // requests to the provider per organisation per day
        'lookback_days' => 60,          // how far back the first fetch for a field reaches
        'require_plan' => true,         // only fields with an approved or growing crop plan
        'zone_stats' => true,           // statistics per management zone too (one request per zone)
        'images' => true,               // keep the NDVI picture per field per date
    ];

    protected $table = 'agri_satellite_providers';

    protected $hidden = ['credentials'];

    protected $casts = ['is_active' => 'boolean', 'settings' => 'array', 'last_test_ok' => 'boolean', 'last_test_at' => \App\Modules\Agri\Casts\FarmDateTime::class];

    public function getActivitylogOptions(): LogOptions
    {
        return parent::getActivitylogOptions()->logExcept(['updated_at', 'created_at', 'credentials']);
    }

    public function credentials(): array
    {
        try {
            return $this->credentials ? (json_decode(Crypt::decryptString($this->credentials), true) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function putCredentials(array $credentials): void
    {
        $this->credentials = Crypt::encryptString(json_encode($credentials));
    }

    /** The settings with every default filled in. */
    public function options(): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($this->settings ?? [], self::DEFAULTS));
    }

    public static function activeFor(int $companyId): ?self
    {
        return static::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->first();
    }
}
