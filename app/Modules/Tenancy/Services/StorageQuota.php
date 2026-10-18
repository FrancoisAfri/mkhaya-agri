<?php

namespace App\Modules\Tenancy\Services;

use App\Models\AppSetting;
use App\Modules\Tenancy\Exceptions\StorageQuotaExceededException;
use App\Modules\Tenancy\Models\BillingAddon;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyAddon;
use App\Modules\Tenancy\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Per-company DATABASE storage quota. Unlike AI/SMS (monthly), storage is
 * cumulative: allowance = plan storage_gb + purchased storage-pack add-ons +
 * granted storage credits; usage = SUM(tenant_files.size) for the company (the
 * real bytes stored). Platform admins / self-hosted are never metered.
 *
 * Enforcement mode reuses the platform 'ai_quota_enforcement' setting: 'strict'
 * blocks at 100%, 'grace' allows a small overage window before blocking.
 */
class StorageQuota
{
    public const GB = 1073741824; // 1 GiB in bytes

    public function __construct(private readonly CurrentCompany $current) {}

    public function isEnforced(): bool
    {
        return config('edition.edition', 'saas') !== 'self_hosted' && $this->current->isSet();
    }

    public function mode(): string
    {
        $m = AppSetting::get('ai_quota_enforcement', 'strict');

        return in_array($m, ['strict', 'grace'], true) ? $m : 'strict';
    }

    /** Total allowance in bytes (plan + storage packs + credits). 0 plan GB => unlimited. */
    public function quotaBytes(?Company $company = null): int
    {
        $company ??= $this->currentCompany();
        if (! $company) {
            return 0; // no company → treated as unlimited (platform/console)
        }
        $planGb = (int) (Plan::where('code', $company->plan)->value('storage_gb') ?? 0);
        if ($planGb === 0) {
            return 0; // unlimited base (e.g. enterprise if set to 0)
        }
        $base = $planGb * self::GB;
        $base += $this->packBytes($company);
        $base += $this->creditBytes($company);

        return $base;
    }

    /** Extra GB from purchased storage-pack add-ons (permanent). */
    public function packBytes(Company $company): int
    {
        $packs = BillingAddon::where('type', 'storage_pack')->pluck('units', 'code'); // code => GB
        $total = 0;
        foreach (CompanyAddon::where('company_id', $company->id)->where('type', 'storage_pack')->get() as $a) {
            $gb = (int) ($packs[$a->addon_code] ?? 0);
            $total += $gb * self::GB * max(1, (int) $a->quantity);
        }

        return $total;
    }

    /** Extra bytes granted via one-off top-ups (bought/requested). */
    public function creditBytes(Company $company): int
    {
        return (int) DB::table('company_storage_credits')->where('company_id', $company->id)->sum('bytes');
    }

    /** Total bytes stored for the company: uploaded files + relational data. */
    public function usedBytes(?int $companyId = null): int
    {
        $companyId ??= $this->current->id();
        if (! $companyId) {
            return 0;
        }

        return $this->fileBytes($companyId) + $this->dataBytes($companyId);
    }

    /** Uploaded-file bytes (tenant_files). */
    public function fileBytes(?int $companyId = null): int
    {
        $companyId ??= $this->current->id();
        if (! $companyId) {
            return 0;
        }

        return (int) DB::table('tenant_files')->where('company_id', $companyId)->sum('size');
    }

    /** Relational-data footprint (cached nightly by storage:recompute). */
    public function dataBytes(?int $companyId = null): int
    {
        $companyId ??= $this->current->id();
        if (! $companyId) {
            return 0;
        }

        return (int) (DB::table('company_storage_stats')->where('company_id', $companyId)->value('data_bytes') ?? 0);
    }

    public function isUnlimited(?Company $company = null): bool
    {
        return $this->quotaBytes($company) === 0;
    }

    public function remainingBytes(?Company $company = null): int
    {
        $quota = $this->quotaBytes($company);
        if ($quota === 0) {
            return PHP_INT_MAX;
        }

        return max(0, $quota - $this->usedBytes($company?->id));
    }

    /** Would storing $addBytes exceed the allowance (respecting the mode)? */
    public function wouldExceed(int $addBytes, ?Company $company = null): bool
    {
        if (! $this->isEnforced()) {
            return false;
        }
        $company ??= $this->currentCompany();
        $quota = $this->quotaBytes($company);
        if ($quota === 0) {
            return false; // unlimited
        }
        $ceiling = $this->mode() === 'grace' ? (int) ceil($quota * 1.10) : $quota;

        return ($this->usedBytes($company?->id) + $addBytes) > $ceiling;
    }

    /** Throw if writing $addBytes would put the active company over quota. */
    public function guardWrite(int $addBytes, string $path = ''): void
    {
        if ($this->wouldExceed($addBytes)) {
            throw new StorageQuotaExceededException;
        }
    }

    /** Snapshot for the usage page / warnings. */
    public function snapshot(?Company $company = null): array
    {
        $company ??= $this->currentCompany();
        $quota = $this->quotaBytes($company);
        $files = $this->fileBytes($company?->id);
        $data = $this->dataBytes($company?->id);
        $used = $files + $data;
        $unlimited = $quota === 0;
        $percent = $unlimited ? 0.0 : ($quota > 0 ? min(999, round($used / $quota * 100, 1)) : 0.0);
        $warnAt = 80.0;

        return [
            'unlimited' => $unlimited,
            'quota' => $quota,
            'used' => $used,
            'files' => $files,   // uploaded documents / images
            'data' => $data,    // relational data footprint
            'remaining' => $unlimited ? null : max(0, $quota - $used),
            'percent' => $percent,
            'low' => ! $unlimited && $percent >= $warnAt && $percent < 100,
            'exceeded' => ! $unlimited && $used >= $quota,
            'packs' => $company ? $this->packBytes($company) : 0,
            'credits' => $company ? $this->creditBytes($company) : 0,
        ];
    }

    private function currentCompany(): ?Company
    {
        return $this->current->id() ? Company::find($this->current->id()) : null;
    }
}
