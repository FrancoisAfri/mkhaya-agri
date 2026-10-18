<?php

namespace App\Core\SMS\Services;

use App\Core\SMS\Models\SmsConfiguration;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\SmsQuota;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Send an SMS and meter it against the company's monthly allowance.
     * $purpose (otp|notification|bulk) — non-critical purposes are blocked when the
     * company is over its SMS quota; OTP and unlabelled sends always go through so
     * login/2FA never breaks. Every send (or skip) is logged to sms_log.
     */
    public static function send(string|array $numbers, string $message, ?string $purpose = null): bool
    {
        $config = SmsConfiguration::getActive();

        if (!$config || empty($config->sms_username) || empty($config->sms_password)) {
            Log::warning('SMS not sent: no active SMS configuration found.');
            return false;
        }

        $companyId = static::currentCompanyId();
        $segments  = static::segmentsFor($numbers, $message);

        // Enforce quota only for explicitly non-critical traffic — never OTP.
        if ($companyId && in_array($purpose, ['notification', 'bulk'], true)) {
            $company = Company::find($companyId);
            if ($company && app(SmsQuota::class)->blocked($company)) {
                static::log($companyId, $numbers, $purpose, $segments, 'skipped', $config->provider, 'SMS quota exhausted');
                Log::info('SMS skipped: company over monthly SMS quota.', ['company_id' => $companyId]);
                return false;
            }
        }

        $ok = match ($config->provider) {
            'bulksms' => BulkSmsService::send($numbers, $message, $config->sms_username, $config->sms_password),
            default   => false,
        };

        static::log($companyId, $numbers, $purpose, $segments, $ok ? 'sent' : 'failed', $config->provider, $ok ? null : 'provider send failed');

        return $ok;
    }

    private static function currentCompanyId(): ?int
    {
        try {
            $cc = app(CurrentCompany::class);
            if ($cc->id()) {
                return $cc->id();
            }
        } catch (\Throwable) {
        }

        return Auth::user()->company_id ?? null;
    }

    private static function segmentsFor(string|array $numbers, string $message): int
    {
        $recipients = is_array($numbers) ? max(1, count($numbers)) : 1;
        $perMessage = max(1, (int) ceil(mb_strlen($message) / (int) config('sms.quota.chars_per_segment', 160)));

        return $recipients * $perMessage;
    }

    private static function log(?int $companyId, string|array $numbers, ?string $purpose, int $segments, string $status, ?string $provider, ?string $error): void
    {
        try {
            DB::table('sms_log')->insert([
                'company_id' => $companyId,
                'user_id'    => Auth::id(),
                'recipient'  => is_array($numbers) ? (($numbers[0] ?? '') . (count($numbers) > 1 ? ' +' . (count($numbers) - 1) : '')) : $numbers,
                'purpose'    => $purpose,
                'segments'   => $segments,
                'status'     => $status,
                'provider'   => $provider,
                'error'      => $error,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SMS logging failed', ['error' => $e->getMessage()]);
        }
    }

    public static function isConfigured(): bool
    {
        $config = SmsConfiguration::getActive();
        return $config !== null && !empty($config->sms_username) && !empty($config->sms_password);
    }

    public static function isTwoFactorEnabled(): bool
    {
        $config = SmsConfiguration::getActive();
        return ($config?->two_factor_enabled ?? false) && static::isConfigured();
    }

    public static function getConfig(): ?SmsConfiguration
    {
        return SmsConfiguration::first();
    }
}
