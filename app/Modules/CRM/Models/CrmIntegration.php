<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class CrmIntegration extends Model
{
    protected $table = 'crm_integrations';

    protected $fillable = [
        'provider', 'category', 'config', 'active', 'status', 'status_message', 'last_tested_at', 'created_by',
    ];

    protected $casts = [
        'active'         => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    /** Decrypted config as an array. */
    public function configArray(): array
    {
        if (! $this->config) return [];
        try {
            return json_decode(Crypt::decryptString($this->config), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function setConfigArray(array $config): void
    {
        $this->config = Crypt::encryptString(json_encode($config));
    }

    /** The active integration for a category (or null). */
    public static function active(string $category): ?self
    {
        return static::where('category', $category)->where('active', true)->first();
    }

    /** Decrypted config for a specific provider. */
    public static function configFor(string $provider): array
    {
        return static::where('provider', $provider)->first()?->configArray() ?? [];
    }
}
