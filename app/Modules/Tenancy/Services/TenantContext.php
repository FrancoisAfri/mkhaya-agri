<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Jobs\RunTenantCommandJob;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs a unit of background work as a specific tenant. Sets BOTH the app-level
 * CurrentCompany (drives CompanyScope + entitlements) and the Postgres
 * `app.company_id` session variable (drives row-level security) — the same pair
 * the ResolveCompany middleware sets for web requests — then restores the prior
 * context afterwards.
 *
 * Use this in queued jobs, scheduled commands and webhook handlers so records they
 * create are tagged to the right company (and reads are scoped), instead of
 * running with no tenant (which reads across all tenants and writes rows with a
 * NULL company_id that no tenant user can see).
 */
class TenantContext
{
    public function __construct(private CurrentCompany $current) {}

    /** Execute $callback with $companyId active, then restore the previous tenant. */
    public function run(?int $companyId, callable $callback): mixed
    {
        $previous = $this->current->id();
        $this->apply($companyId);

        try {
            return $callback();
        } finally {
            $this->apply($previous);
        }
    }

    /**
     * Run $callback once per active/trial company, each inside that company's
     * tenant context. Use to make a scheduled command tenant-aware: every query
     * inside the callback is RLS-scoped to the company, and rows it creates are
     * tagged to it. The company list is read with no tenant active (sees all).
     */
    public function eachCompany(callable $callback): void
    {
        $previous = $this->current->id();
        $this->apply(null);

        $ids = $this->activeCompanyIds();

        // No companies at all (fresh install / unit tests): there is nothing to
        // isolate — run once untenanted so the work still happens.
        if ($ids->isEmpty()) {
            $this->run(null, fn () => $callback(null));
            $this->apply($previous);

            return;
        }

        foreach ($ids as $id) {
            $this->run((int) $id, fn () => $callback((int) $id));
        }
        $this->apply($previous);
    }

    /**
     * IDs of companies a scheduled task should process (active + trial).
     *
     * On a self-hosted installation, status is not consulted. `active` and `trial` are SaaS
     * subscription states, and a self-hosted customer has no subscription to be in one of — their
     * entitlement is the signed licence, which CompanyEntitlements checks separately. Their single
     * company's status is therefore whatever the install left behind, and if that was not one of
     * those two words, this returned nothing.
     *
     * Returning nothing was not a quiet no-op. eachCompany() reads an empty list as a fresh
     * install and runs the callback once untenanted; RunsPerTenant then refuses the null company,
     * correctly, because tenant work done untenanted writes rows belonging to nobody. So every
     * scheduled command ran, did nothing whatsoever, and reported success — content plans,
     * publishing, supplier sweeps, budget alerts, leave accrual, all of them, with no error
     * anywhere to say so.
     */
    public function activeCompanyIds(): Collection
    {
        $previous = $this->current->id();
        $this->apply(null); // read with no tenant so every company is visible

        $ids = Company::query()
            // SaaS keeps its billing gate: a suspended tenant must not go on receiving scheduled
            // work it has stopped paying for.
            ->when(! Edition::isSelfHosted(), fn ($q) => $q->whereIn('status', ['active', 'trial']))
            ->pluck('id');

        $this->apply($previous);

        return $ids;
    }

    /**
     * Fan a per-tenant command out to the queue: one RunTenantCommandJob per
     * active/trial company, each invoking the command with `--company=<id>`.
     * Returns the number of jobs dispatched. Use for heavy per-tenant crons so
     * they parallelise across workers instead of running serially in one process.
     */
    public function dispatchEachCompany(string $command, array $options = [], string $queue = 'tenants'): int
    {
        $count = 0;
        foreach ($this->activeCompanyIds() as $id) {
            RunTenantCommandJob::dispatch((int) $id, $command, $options)->onQueue($queue);
            $count++;
        }

        return $count;
    }

    /** Make $companyId the active tenant for the app scope + Postgres RLS. */
    public function apply(?int $companyId): void
    {
        if ($companyId === null) {
            $this->current->clear();
        } else {
            $this->current->set($companyId);
        }

        // Same convention as ResolveCompany::stampDbSession — feature tests run
        // with the RLS session var bypassed so untenanted fixtures stay visible.
        // Tests that exercise RLS itself call set_config directly (see RlsTest).
        if (app()->environment('testing')) {
            return;
        }

        try {
            DB::statement("SELECT set_config('app.company_id', ?, false)", [$companyId === null ? '' : (string) $companyId]);
        } catch (\Throwable) {
            // best-effort (non-Postgres / no connection) — app scope remains
        }
    }
}
