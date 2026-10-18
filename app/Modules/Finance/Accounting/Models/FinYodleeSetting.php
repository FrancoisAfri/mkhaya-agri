<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The Yodlee subscription a company uses.
 *
 * Normally Mkhaya's: the row with a null company_id is the platform's own subscription, readable by
 * every tenant, so a customer links a bank and never sees a credential field. A tenant row overrides
 * it for the occasional large customer holding their own Yodlee contract.
 *
 * The keys themselves are encrypted at rest and hidden from serialisation. With one subscription now
 * standing behind every customer's bank data, an Inertia page that accidentally serialised this
 * would not leak one company's credential — it would leak all of them.
 */
class FinYodleeSetting extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_yodlee_settings';

    protected $fillable = [
        'uuid', 'company_id', 'environment', 'base_url', 'fastlink_url',
        'credentials', 'user_login_name', 'is_active', 'verified_at', 'last_error', 'created_by',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_active'   => 'boolean',
        'verified_at' => 'datetime',
    ];

    /** Never serialised. The screen reports whether a secret is set, never what it is. */
    protected $hidden = ['credentials'];

    /**
     * A null company_id row belongs to the platform and every tenant may read it.
     *
     * Both halves are needed: the RLS policy allows the null row through, and this tells
     * CompanyScope to stop filtering it out. Without it the platform subscription installs cleanly
     * and is invisible to everybody — the trap documented on ContractsTemplate.
     */
    public static function sharesGlobalRecords(): bool
    {
        return true;
    }

    /**
     * The subscription this company should use: its own if it has one, otherwise the platform's.
     *
     * Ordered so a tenant row wins. `company_id` sorts nulls last on Postgres by default for DESC,
     * so this is stated explicitly rather than relied upon.
     */
    public static function resolve(): ?self
    {
        return static::query()
            ->orderByRaw('company_id IS NULL')   // false (a tenant row) first
            ->first();
    }

    /** Mkhaya's own subscription — the one a platform admin edits. */
    public static function platform(): ?self
    {
        return static::query()->withoutGlobalScopes()->whereNull('company_id')->first();
    }

    public function isPlatformWide(): bool
    {
        return $this->company_id === null;
    }

    /**
     * Yodlee's published hosts.
     *
     * Kept here rather than in config because they differ per environment and a tenant can be on a
     * different one from its neighbour — a customer still in sandbox alongside one already live.
     * base_url on the row overrides, for the regional hosts Yodlee issues to some subscriptions.
     */
    public const HOSTS = [
        'sandbox'     => 'https://sandbox.api.yodlee.com/ysl',
        'development' => 'https://development.api.yodlee.com/ysl',
        'production'  => 'https://production.api.yodlee.com/ysl',
    ];

    public const FASTLINK_URLS = [
        'sandbox'     => 'https://fl4.sandbox.yodlee.com/authenticate/restserver/fastlink',
        'development' => 'https://fl4.development.yodlee.com/authenticate/development/fastlink',
        'production'  => 'https://fl4.prod.yodlee.com/authenticate/production/fastlink',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $s) {
            $s->uuid ??= (string) Str::uuid();
        });
    }

    public function apiBaseUrl(): string
    {
        return rtrim($this->base_url ?: (self::HOSTS[$this->environment] ?? self::HOSTS['sandbox']), '/');
    }

    public function fastlinkUrl(): string
    {
        return $this->fastlink_url ?: (self::FASTLINK_URLS[$this->environment] ?? self::FASTLINK_URLS['sandbox']);
    }

    public function clientId(): ?string
    {
        return $this->credentials['client_id'] ?? null;
    }

    public function secret(): ?string
    {
        return $this->credentials['secret'] ?? null;
    }

    /** Whether there is enough here to call Yodlee at all. */
    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->secret());
    }

    /** What the settings screen may safely see. */
    public function toSafeArray(): array
    {
        return [
            'environment'      => $this->environment,
            'base_url'         => $this->apiBaseUrl(),
            'fastlink_url'     => $this->fastlinkUrl(),
            'client_id'        => $this->clientId(),          // an identifier, not a secret
            'has_secret'       => filled($this->secret()),    // the secret itself never leaves here
            'platform_wide'    => $this->isPlatformWide(),
            'is_active'        => $this->is_active,
            'configured'       => $this->isConfigured(),
            'verified_at'      => $this->verified_at?->toDateTimeString(),
            'last_error'       => $this->last_error,
        ];
    }
}
