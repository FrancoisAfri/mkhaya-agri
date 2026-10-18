<?php

namespace App\Modules\Tenancy\Traits;

use App\Modules\Tenancy\Exceptions\TenantContextMissingException;
use App\Modules\Tenancy\Scopes\CompanyScope;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Support\SoleCompany;

/**
 * Marks a model as company-owned (multi-tenant). Adds a global scope that limits
 * queries to the active company, and auto-stamps the company id on new records.
 *
 * The tenant key is `company_id` (override getCompanyColumn() for a different one).
 *
 * ── Where the owner comes from ───────────────────────────────────────────────
 *
 * In order, stopping at the first that answers: the value already set on the model; the active
 * CurrentCompany; the authenticated user's own company; and, on a single-tenant installation, the
 * one company that exists. Only a model declaring `sharesGlobalRecords()` skips the chain, because
 * for that model a null company is the intent rather than the absence of one.
 *
 * The chain used to stop after CurrentCompany for every model that was not strict, and the rest of
 * this file explains why that was the wrong place to stop.
 *
 * ── Strict models ────────────────────────────────────────────────────────────
 *
 * For most tables a null company_id is meaningful: `public_holidays` and `app_settings` use it to
 * mean "shared by every tenant". For others it is simply a row nobody will ever see again — row
 * level security matches `company_id = <session>`, so a null one is invisible to every tenant
 * while still looking, to the person who created it, like the save worked.
 *
 * A model that can never legitimately be global overrides `requiresCompany()` to return true. Such
 * a model falls back to the authenticated user's own company, and if there is still no company to
 * stamp it refuses to be created at all. Failing loudly beats writing a record that silently
 * disappears — that is a support ticket about "my budget vanished" and an afternoon of nobody being
 * able to reproduce it.
 *
 * Default is false, so nothing that relies on the shared-null convention changes.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            $column = $model->getCompanyColumn();

            if ($model->getAttribute($column)) {
                return; // already set explicitly
            }

            $current = app(CurrentCompany::class);

            if ($current->isSet()) {
                $model->setAttribute($column, $current->id());

                return;
            }

            // A model that uses null to mean "shared with every tenant" is saying the absence of
            // a company is the point. None of the fallbacks below apply to it.
            if ($model::sharesGlobalRecords()) {
                return;
            }

            // A platform admin acting without picking a tenant has no CurrentCompany, but usually
            // does belong to one. `id()` is an instance method on CurrentCompany; the user's own
            // column is the honest second source.
            $fallback = auth()->user()?->getAttribute($column);

            if ($fallback) {
                $model->setAttribute($column, $fallback);

                return;
            }

            // Single-tenant installation: there is one company and no choice to make.
            if ($sole = SoleCompany::id()) {
                $model->setAttribute($column, $sole);

                return;
            }

            if (! $model::requiresCompany()) {
                return;
            }

            throw new TenantContextMissingException($model::class);
        });
    }

    /**
     * Is a null company_id a bug on this model rather than a "shared by everyone" record?
     *
     * A method rather than a property because PHP refuses to let a class redeclare a trait's
     * property with a different default — which is exactly what overriding this would be.
     */
    public static function requiresCompany(): bool
    {
        return false;
    }

    /**
     * Does a null company_id mean "shared with every tenant" on this model?
     *
     * When true, CompanyScope returns the tenant's own rows *and* the shared ones, so the model can
     * carry a system-wide library that every tenant reads alongside records they own.
     *
     * Only turn this on where the table's row-level security policy also permits a null
     * company_id. The two have to agree: the ORM scope would hand back the shared rows and Postgres
     * would filter them out again, leaving a library that installs cleanly and shows nothing.
     *
     * The counterpart of requiresCompany(), and mutually exclusive with it.
     */
    public static function sharesGlobalRecords(): bool
    {
        return false;
    }

    public function getCompanyColumn(): string
    {
        return 'company_id';
    }

    /** Escape hatch: query across all companies (e.g. platform-level jobs). */
    public function scopeWithoutCompanyScope($query)
    {
        return $query->withoutGlobalScope(CompanyScope::class);
    }
}
