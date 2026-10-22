<?php

namespace App\Modules\Agri\Models;

use Illuminate\Support\Facades\Crypt;
use Spatie\Activitylog\LogOptions;

/**
 * An organisation's connection to Xero, QuickBooks Online or Sage Business Cloud Accounting: its
 * OAuth tokens (encrypted, never sent to a browser or written to the audit log), the tenant, realm
 * or business chosen after connecting, their chart as last pulled, and the sync settings.
 */
class AccountingConnection extends AgriModel
{
    public const XERO = 'xero';
    public const QUICKBOOKS = 'quickbooks';
    public const SAGE = 'sage';
    public const PROVIDERS = [self::XERO, self::QUICKBOOKS, self::SAGE];

    public const DEFAULTS = ['push_invoices' => true, 'push_bills' => true, 'push_journals' => true, 'pull_payments' => true,
        'default_item' => null, 'sync_from' => null,
        // Which of our dimensions goes to which of theirs: Xero tracking categories, QuickBooks class / department, Sage analysis types.
        'tracking' => ['farm' => null, 'field' => null, 'enterprise' => null]];

    protected $table = 'agri_accounting_connections';

    protected $hidden = ['tokens', 'oauth_state'];

    protected $casts = ['tenants' => 'array', 'catalog' => 'array', 'settings' => 'array', 'connected_at' => 'datetime', 'last_sync_at' => 'datetime',
        'payments_pulled_at' => 'datetime', 'oauth_state_expires_at' => 'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return parent::getActivitylogOptions()->logExcept(['updated_at', 'created_at', 'tokens', 'oauth_state', 'catalog', 'last_sync_at', 'payments_pulled_at']);
    }

    /** @return array{access_token?: string, refresh_token?: string, expires_at?: int, refresh_expires_at?: ?int, scope?: ?string} */
    public function tokens(): array
    {
        try {
            return $this->tokens ? (json_decode(Crypt::decryptString($this->tokens), true) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function putTokens(?array $tokens): void
    {
        $this->tokens = $tokens ? Crypt::encryptString(json_encode($tokens)) : null;
    }

    public function options(): array
    {
        $s = $this->settings ?? [];

        return array_merge(self::DEFAULTS, $s, ['tracking' => array_merge(self::DEFAULTS['tracking'], $s['tracking'] ?? [])]);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && $this->tenant_id !== null;
    }

    public static function for(int $companyId, string $provider): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId, 'provider' => $provider],
            ['status' => self::registered($provider) ? 'disconnected' : 'needs_registration']);
    }

    /** Whether the platform has registered an app with this provider (client id and secret configured). */
    public static function registered(string $provider): bool
    {
        return filled(config("agri.accounting.providers.{$provider}.client_id")) && filled(config("agri.accounting.providers.{$provider}.client_secret"));
    }
}
