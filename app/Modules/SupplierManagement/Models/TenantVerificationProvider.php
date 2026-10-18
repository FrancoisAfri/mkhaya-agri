<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One tenant's configuration of one verification source: their credentials, which checks they have
 * turned on, and what happened the last time it was used.
 *
 * Credentials are `encrypted:array` — the same treatment as `payment_gateways`, for the same reason:
 * these are the customer's own account with CIPC or a credit bureau, and a database dump must not
 * hand them over. They are never sent to the browser; the screen shows which keys are set, not what
 * they contain.
 */
class TenantVerificationProvider extends Model
{
    use BelongsToCompany;

    protected $table = 'company_verification_providers';

    protected $fillable = [
        'uuid', 'provider_code', 'is_enabled', 'mode', 'credentials',
        'enabled_checks', 'settings', 'monthly_quota', 'configured_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'credentials' => 'encrypted:array',
        'enabled_checks' => 'array',
        'settings' => 'array',
        'monthly_quota' => 'integer',
        'calls_this_month' => 'integer',
        'last_ok_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $config) {
            $config->uuid ??= (string) Str::uuid();
        });
    }

    public function catalogue(): BelongsTo
    {
        return $this->belongsTo(DueDiligenceProvider::class, 'provider_code', 'code');
    }

    public function cred(string $key, mixed $default = null): mixed
    {
        return ($this->credentials ?? [])[$key] ?? $default;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    /** Which credential keys are set — safe to send to the browser, unlike the values. */
    public function credentialKeys(): array
    {
        return array_keys(array_filter($this->credentials ?? [], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Whether this tenant has turned this specific check on. A null `enabled_checks` means "every
     * capability the provider has" rather than "none", so enabling a provider is one click and
     * narrowing it is a deliberate act.
     */
    public function allowsCheck(string $checkType): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        $enabled = $this->enabled_checks;

        return $enabled === null || $enabled === [] || in_array($checkType, $enabled, true);
    }

    /**
     * Has this month's metered allowance run out? Counted per calendar month, and the counter resets
     * itself by belonging to a month rather than by a job that has to run on the 1st.
     */
    public function quotaExhausted(): bool
    {
        if (! $this->monthly_quota) {
            return false;
        }

        return $this->currentMonthCalls() >= $this->monthly_quota;
    }

    public function quotaRemaining(): ?int
    {
        return $this->monthly_quota
            ? max(0, $this->monthly_quota - $this->currentMonthCalls())
            : null;
    }

    public function currentMonthCalls(): int
    {
        return $this->quota_month === Carbon::now()->format('Y-m')
            ? (int) $this->calls_this_month
            : 0;
    }

    /** Count a call against this month, rolling the counter over when the month changed. */
    public function recordCall(): void
    {
        $month = Carbon::now()->format('Y-m');

        $this->forceFill([
            'quota_month' => $month,
            'calls_this_month' => $this->quota_month === $month ? $this->calls_this_month + 1 : 1,
        ])->saveQuietly();
    }

    public function recordSuccess(): void
    {
        $this->forceFill([
            'last_ok_at' => Carbon::now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->saveQuietly();
    }

    public function recordFailure(string $message): void
    {
        $this->forceFill([
            'last_error' => Str::limit($message, 490, ''),
            'last_error_at' => Carbon::now(),
        ])->saveQuietly();
    }
}
