<?php

namespace App\Modules\Agri\Models;

use Illuminate\Support\Facades\Crypt;
use Spatie\Activitylog\LogOptions;

/**
 * An organisation's connection to John Deere Operations Center or CNH FieldOps: its OAuth tokens
 * (encrypted, never sent to a browser or written to the audit log), the Deere organisation or CNH
 * company chosen after connecting, what is imported and exported, and where the last sync left off.
 */
class MachineConnection extends AgriModel
{
    public const DEERE = 'deere';
    public const CNH = 'cnh';
    public const PROVIDERS = [self::DEERE, self::CNH];

    public const DEFAULTS = ['import_boundaries' => true, 'import_machines' => true, 'import_telemetry' => true, 'import_operations' => true,
        // Our boundary sent when ours changed and theirs did not. Off by default: the farm decides when the map is the master.
        'push_boundaries' => false,
        // An imported application completed here at once, issuing its inputs from the farm store. Off: a person completes it.
        'complete_imported' => false];

    protected $table = 'agri_machine_connections';

    protected $hidden = ['tokens', 'oauth_state'];

    protected $casts = ['organisations' => 'array', 'settings' => 'array', 'cursors' => 'array', 'last_sync' => 'array', 'connected_at' => 'datetime',
        'last_sync_at' => 'datetime', 'oauth_state_expires_at' => 'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return parent::getActivitylogOptions()->logExcept(['updated_at', 'created_at', 'tokens', 'oauth_state', 'last_sync_at', 'last_sync', 'cursors']);
    }

    public function tokens(): array
    {
        try {
            $raw = $this->attributes['tokens'] ?? null;

            return $raw ? (json_decode(Crypt::decryptString($raw), true) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function putTokens(?array $tokens): void
    {
        $this->attributes['tokens'] = $tokens ? Crypt::encryptString(json_encode($tokens)) : null;
    }

    public function options(): array
    {
        return array_merge(self::DEFAULTS, $this->settings ?? []);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && $this->org_id !== null;
    }

    public function platformName(): string
    {
        return (string) config("agri.machine_data.providers.{$this->provider}.name", $this->provider);
    }

    public static function for(int $companyId, string $provider): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId, 'provider' => $provider],
            ['status' => self::registered($provider) ? 'disconnected' : 'needs_registration']);
    }

    /** Whether the platform's developer app is approved and configured here (client id and secret). */
    public static function registered(string $provider): bool
    {
        return filled(config("agri.machine_data.providers.{$provider}.client_id")) && filled(config("agri.machine_data.providers.{$provider}.client_secret"));
    }
}
