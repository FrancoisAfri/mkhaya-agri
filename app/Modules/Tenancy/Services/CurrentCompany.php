<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Company;

/**
 * Holds the active company (tenant) for the current request. Resolved from the
 * authenticated user's company, or — for platform admins — a company they are
 * impersonating. When NO company is set (console, seeders, platform admin not
 * impersonating) the tenant global scope is inactive, so those contexts see all
 * data. That keeps the multi-tenant retrofit additive and safe.
 *
 * Registered as a singleton so one instance is shared across the request.
 */
class CurrentCompany
{
    private ?int $id = null;

    private ?Company $company = null;

    private bool $impersonating = false;

    public function set(?int $companyId, bool $impersonating = false): void
    {
        $this->id = $companyId;
        $this->company = null;
        $this->impersonating = $impersonating;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function isSet(): bool
    {
        return $this->id !== null;
    }

    public function impersonating(): bool
    {
        return $this->impersonating;
    }

    public function get(): ?Company
    {
        if ($this->id === null) {
            return null;
        }

        return $this->company ??= Company::find($this->id);
    }

    public function clear(): void
    {
        $this->id = null;
        $this->company = null;
        $this->impersonating = false;
    }
}
