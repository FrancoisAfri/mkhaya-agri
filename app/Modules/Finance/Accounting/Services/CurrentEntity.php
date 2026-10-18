<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Support\SoleCompany;
use App\Modules\Tenancy\Services\Edition;

/**
 * Which trading entity the current request is working in.
 *
 * The second half of the addressing pair: CurrentCompany says whose data this is, CurrentEntity
 * says which of their businesses it belongs to. A request that resolves a company always resolves
 * an entity too — the tenant's default one, unless somebody has switched.
 *
 * ── Chosen, then remembered, then defaulted ──────────────────────────────────
 *
 * In order: an entity set explicitly on this instance (a console pass, a queued job, a test);
 * the one in the session, if it still belongs to this tenant and is still active; otherwise the
 * tenant's default.
 *
 * The session value is re-checked rather than trusted. It survives a logout, a tenant switch and
 * an entity being archived, and an id that is no longer this tenant's must never select rows —
 * `company_id` is what keeps tenants apart, but a stale entity id would quietly show the wrong
 * letterhead inside the right tenant, which is its own kind of wrong.
 */
class CurrentEntity
{
    private const SESSION_KEY = 'fin_entity_id';

    private ?int $overridden = null;

    private bool $hasOverride = false;

    /**
     * The resolved business per tenant, for the life of this request.
     *
     * EntityScope asks for this on every finance query, and answering it costs a round trip —
     * checking the session's id still belongs to the tenant, or looking up their default. Without
     * memoising, listing a hundred invoices means a hundred extra queries for an answer that
     * cannot change between them.
     *
     * Keyed by company so a tenant switch inside one process — TenantContext::run, a per-tenant
     * cron fanning out — gets its own answer rather than the previous tenant's.
     *
     * @var array<int, int|null>
     */
    private array $resolved = [];

    public function __construct(private readonly CurrentCompany $company)
    {
    }

    /** The active entity's id, or null when there is no tenant to resolve one for. */
    public function id(): ?int
    {
        if ($this->hasOverride) {
            return $this->overridden;
        }

        $companyId = $this->companyId();

        if ($companyId === null) {
            return null;
        }

        if (array_key_exists($companyId, $this->resolved)) {
            return $this->resolved[$companyId];
        }

        /*
         * The session first, then what the user chose last time.
         *
         * "Until the user changes it" includes tomorrow: a session cookie forgets overnight and
         * would drop somebody back into Mkhaya MK after a week of working in Mkhaya Move. The
         * stored preference is what makes the choice survive signing out.
         */
        foreach ([$this->fromSession(), $this->fromUser()] as $chosen) {
            if ($chosen !== null && $this->belongsToCompany($chosen, $companyId)) {
                return $this->resolved[$companyId] = $chosen;
            }
        }

        return $this->resolved[$companyId] = $this->defaultFor($companyId)?->id;
    }

    public function get(): ?FinEntity
    {
        $id = $this->id();

        return $id === null ? null : FinEntity::withoutGlobalScopes()->find($id);
    }

    /**
     * Work inside one entity for the duration of a callback.
     *
     * The shape TenantContext uses for companies, and for the same reason: a console pass or a
     * queued job has no session to read, and restoring what was there keeps one iteration from
     * leaking into the next.
     */
    public function run(?int $entityId, callable $callback): mixed
    {
        $hadOverride = $this->hasOverride;
        $previous = $this->overridden;

        $this->overridden = $entityId;
        $this->hasOverride = true;

        try {
            return $callback();
        } finally {
            $this->overridden = $previous;
            $this->hasOverride = $hadOverride;
        }
    }

    /** Remember a choice for this user, refusing one that is not theirs to make. */
    public function set(int $entityId): bool
    {
        $companyId = $this->companyId();

        if ($companyId === null || ! $this->belongsToCompany($entityId, $companyId)) {
            return false;
        }

        // The remembered answer is now wrong.
        $this->resolved = [];

        if (function_exists('session') && app()->bound('session')) {
            session([self::SESSION_KEY => $entityId]);
        }

        // Remembered against the user as well, so the choice outlives the session.
        if ($user = auth()->user()) {
            $user->forceFill(['fin_entity_id' => $entityId])->saveQuietly();
        }

        return true;
    }

    public function clear(): void
    {
        $this->hasOverride = false;
        $this->overridden = null;
        $this->resolved = [];

        if (function_exists('session') && app()->bound('session')) {
            session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * The tenant's default entity, created if it has none.
     *
     * Created rather than returned empty: a tenant that has never opened Accounting has no
     * settings row and so was given no entity by the backfill, and the first document they raise
     * must still have somewhere to belong.
     */
    public function defaultFor(int $companyId): ?FinEntity
    {
        /*
         * Active first, whatever the default flag says.
         *
         * A tenant can archive the business that was marked default. Returning it anyway would
         * select an entity that `set()` then refuses, and every list in the module would filter
         * on a business nobody is allowed to be in.
         */
        $existing = FinEntity::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('is_default')->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $name = \Illuminate\Support\Facades\DB::table('companies')->where('id', $companyId)->value('name');

        // A tenant who had no business now has one, so anything remembered about them is stale.
        unset($this->resolved[$companyId]);

        return FinEntity::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'name'       => $name ?: 'Default',
            'is_default' => true,
            'status'     => 'active',
        ]);
    }

    /**
     * Whose books these are.
     *
     * The sole-company fallback matters on a self-hosted install: there is one customer and one
     * company, an administrator signed in there has no CurrentCompany and often no
     * `users.company_id` either, and without this every finance query would resolve no business
     * and silently stop scoping. SoleCompany answers only when exactly one exists — two would be
     * ambiguous again, and guessing there is the leak the scope exists to prevent.
     *
     * Public because the entity picker in the app bar has to ask the same question, and the one
     * time it answered it for itself it disagreed here: a platform admin on a self-hosted install
     * got a scoped module with no way to see or change which business they were scoped to.
     */
    public function companyId(): ?int
    {
        $companyId = $this->company->id() ?? auth()->user()?->company_id;

        if (! $companyId && Edition::isSelfHosted()) {
            $companyId = SoleCompany::id();
        }

        return $companyId ? (int) $companyId : null;
    }

    /** The entity this user was last working in, whatever browser they are on now. */
    private function fromUser(): ?int
    {
        $stored = auth()->user()?->fin_entity_id;

        return $stored === null ? null : (int) $stored;
    }

    private function fromSession(): ?int
    {
        if (! function_exists('session') || ! app()->bound('session')) {
            return null;
        }

        $value = session(self::SESSION_KEY);

        return $value === null ? null : (int) $value;
    }

    private function belongsToCompany(int $entityId, int $companyId): bool
    {
        return FinEntity::withoutGlobalScopes()
            ->where('id', $entityId)
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->exists();
    }
}
