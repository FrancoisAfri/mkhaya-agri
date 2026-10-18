<?php

namespace App\Modules\Tenancy\Services;

use App\Models\AppSetting;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Per-company monthly SMS allowance. Mirrors AiQuota: allowance = override ??
 * plan ?? config default (+ monthly top-up credit); 0 = unlimited. Usage is the
 * sum of sms_log.segments for the company this calendar month. When exhausted and
 * enforcement is on, OTP falls back to email and non-critical SMS is skipped.
 */
class SmsQuota
{
    public function isEnforced(): bool
    {
        return (bool) config('sms.quota.enabled', true)
            && config('edition.edition', 'saas') !== 'self_hosted';
    }

    public function mode(): string
    {
        // Shares the AI enforcement toggle so a single switch governs both.
        $m = AppSetting::get('ai_quota_enforcement', 'strict');

        return in_array($m, ['strict', 'grace'], true) ? $m : 'strict';
    }

    /** Effective monthly allowance = base + this month's credit. 0 = unlimited. */
    public function quotaFor(Company $company): int
    {
        $base = $this->baseQuotaFor($company);

        return $base === 0 ? 0 : $base + $this->monthlyCredit($company);
    }

    /** Base monthly allowance (override ?? plan ?? config default). 0 = unlimited. */
    public function baseQuotaFor(Company $company): int
    {
        if ($company->sms_quota !== null) {
            return (int) $company->sms_quota;
        }
        $planQuota = Plan::where('code', $company->plan)->value('sms_quota');
        if ($planQuota !== null) {
            return (int) $planQuota;
        }

        return (int) config('sms.quota.default_quota', 100);
    }

    public function monthlyCredit(Company $company): int
    {
        return (int) DB::table('company_monthly_credits')
            ->where('company_id', $company->id)
            ->where('type', 'sms')
            ->where('period', now()->format('Y-m'))
            ->sum('amount');
    }

    public function usedThisMonth(?int $companyId): int
    {
        if (! $companyId) {
            return 0;
        }

        return (int) DB::table('sms_log')
            ->where('company_id', $companyId)
            ->where('status', 'sent')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('segments');
    }

    public function isUnlimited(Company $company): bool
    {
        return $this->quotaFor($company) === 0;
    }

    public function remaining(Company $company): int
    {
        $quota = $this->quotaFor($company);

        return $quota === 0 ? PHP_INT_MAX : max(0, $quota - $this->usedThisMonth($company->id));
    }

    /** True when the company is over its SMS allowance for the current mode. */
    public function blocked(Company $company): bool
    {
        if (! $this->isEnforced()) {
            return false;
        }
        $quota = $this->quotaFor($company);
        if ($quota === 0) {
            return false;
        }
        $used = $this->usedThisMonth($company->id);
        $ceiling = $this->mode() === 'grace'
            ? (int) ceil($quota * (1 + config('ai.quota.grace_percent', 10) / 100))
            : $quota;

        return $used >= $ceiling;
    }

    public function randCost(int $segments): float
    {
        return round($segments * (float) config('sms.quota.rand_per_sms', 0.30), 2);
    }

    public function snapshot(Company $company): array
    {
        $base = $this->baseQuotaFor($company);
        $unlimited = $base === 0;
        $credit = $unlimited ? 0 : $this->monthlyCredit($company);
        $quota = $unlimited ? 0 : $base + $credit;
        $used = $this->usedThisMonth($company->id);
        $percent = $unlimited ? 0.0 : ($quota > 0 ? min(999, round($used / $quota * 100, 1)) : 0.0);
        $warnAt = (float) config('sms.quota.warn_threshold', 0.8) * 100;

        return [
            'unlimited' => $unlimited,
            'base' => $base,
            'credit' => $credit,
            'quota' => $quota,
            'used' => $used,
            'remaining' => $unlimited ? null : max(0, $quota - $used),
            'percent' => $percent,
            'low' => ! $unlimited && $percent >= $warnAt && $percent < 100,
            'exceeded' => ! $unlimited && $used >= $quota,
            'blocked' => $this->blocked($company),
            'mode' => $this->mode(),
        ];
    }
}
