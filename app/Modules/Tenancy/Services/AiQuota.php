<?php

namespace App\Modules\Tenancy\Services;

use App\Models\AppSetting;
use App\Modules\Tenancy\Exceptions\AiQuotaExceededException;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Per-company monthly AI-token quota. Allowance = per-company override ?? plan
 * allowance ?? config default, plus this month's top-up credits. A quota of 0
 * means unlimited. Usage is the sum of ai_usage_log.total_tokens for the company
 * this calendar month (attributed via the user's company). Platform admins (no
 * company context) and the self-hosted edition are never metered.
 *
 * Enforcement mode ('strict' | 'grace') is a platform setting a super-admin
 * toggles. 'strict' blocks the moment usage reaches 100%; 'grace' lets a company
 * run one overage window (up to grace_percent past quota) before blocking.
 */
class AiQuota
{
    public function __construct(private readonly CurrentCompany $current) {}

    /** Whether metering applies right now (enabled + SaaS + a real company). */
    public function isEnforced(): bool
    {
        return (bool) config('ai.quota.enabled', true)
            && config('edition.edition', 'saas') !== 'self_hosted'
            && $this->current->isSet();
    }

    public function mode(): string
    {
        $m = AppSetting::get('ai_quota_enforcement', 'strict');

        return in_array($m, ['strict', 'grace'], true) ? $m : 'strict';
    }

    /** Effective monthly allowance = base + this month's credit. 0 = unlimited. */
    public function quotaFor(?Company $company = null): int
    {
        $company ??= $this->currentCompany();
        $base = $this->baseQuotaFor($company);
        if ($base === 0) {
            return 0; // unlimited
        }

        return $base + ($company ? $this->monthlyCredit($company) : 0);
    }

    /** Base monthly allowance (override ?? plan ?? config default). 0 = unlimited. */
    public function baseQuotaFor(?Company $company = null): int
    {
        $company ??= $this->currentCompany();
        if (! $company) {
            return (int) config('ai.quota.default_quota', 200000);
        }
        if ($company->ai_token_quota !== null) {
            return (int) $company->ai_token_quota;
        }
        $planQuota = Plan::where('code', $company->plan)->value('ai_token_quota');
        if ($planQuota !== null) {
            return (int) $planQuota; // includes 0 = unlimited
        }

        return (int) config('ai.quota.default_quota', 200000);
    }

    /** Extra tokens granted for the current month only. */
    public function monthlyCredit(Company $company): int
    {
        return (int) DB::table('company_monthly_credits')
            ->where('company_id', $company->id)
            ->where('type', 'ai')
            ->where('period', now()->format('Y-m'))
            ->sum('amount');
    }

    /** Tokens used this calendar month by the active (or given) company. */
    public function usedThisMonth(?int $companyId = null): int
    {
        $companyId ??= $this->current->id();
        if (! $companyId) {
            return 0;
        }

        // Prefer the stamped company_id; fall back to the user's company for rows
        // written before stamping. Both counted via one indexed query each.
        return (int) DB::table('ai_usage_log')
            ->where('created_at', '>=', now()->startOfMonth())
            ->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->orWhere(function ($q2) use ($companyId) {
                        $q2->whereNull('company_id')
                            ->whereIn('user_id', DB::table('users')->where('company_id', $companyId)->select('id'));
                    });
            })
            ->sum('total_tokens');
    }

    public function isUnlimited(?Company $company = null): bool
    {
        return $this->quotaFor($company) === 0;
    }

    public function randCost(int $tokens): float
    {
        return round($tokens / 1000 * (float) config('ai.quota.rand_per_1k_tokens', 0.25), 2);
    }

    public function remaining(?Company $company = null): int
    {
        $quota = $this->quotaFor($company);
        if ($quota === 0) {
            return PHP_INT_MAX;
        }

        return max(0, $quota - $this->usedThisMonth($company?->id));
    }

    /** Rough token estimate for a prompt (chars/4 + expected completion budget). */
    public function estimateTokens(string $prompt, int $completionBudget = 800): int
    {
        return (int) ceil(mb_strlen($prompt) / 4) + $completionBudget;
    }

    /** True when the company is over its allowance for the current mode. */
    public function blocked(?Company $company = null): bool
    {
        if (! $this->isEnforced()) {
            return false;
        }
        $company ??= $this->currentCompany();
        $quota = $this->quotaFor($company);
        if ($quota === 0) {
            return false; // unlimited
        }
        $used = $this->usedThisMonth($company?->id);
        $ceiling = $this->mode() === 'grace'
            ? (int) ceil($quota * (1 + config('ai.quota.grace_percent', 10) / 100))
            : $quota;

        return $used >= $ceiling;
    }

    /** Throw if the active company is blocked. Safe no-op otherwise. */
    public function ensureWithinQuota(): void
    {
        if ($this->blocked()) {
            throw new AiQuotaExceededException;
        }
    }

    /** Snapshot for dashboards / shared props. */
    public function snapshot(?Company $company = null): array
    {
        $company ??= $this->currentCompany();
        $base = $this->baseQuotaFor($company);
        $unlimited = $base === 0;
        $credit = ($unlimited || ! $company) ? 0 : $this->monthlyCredit($company);
        $quota = $unlimited ? 0 : $base + $credit;
        $used = $this->usedThisMonth($company?->id);
        $percent = $unlimited ? 0.0 : ($quota > 0 ? min(999, round($used / $quota * 100, 1)) : 0.0);
        $warnAt = (float) config('ai.quota.warn_threshold', 0.8) * 100;

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
            'rand_spent' => $this->randCost($used),
        ];
    }

    private function currentCompany(): ?Company
    {
        return $this->current->id() ? Company::find($this->current->id()) : null;
    }
}
