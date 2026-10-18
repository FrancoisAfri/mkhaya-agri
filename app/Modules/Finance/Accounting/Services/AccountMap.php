<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinAccountMapping;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger account a module's posting belongs in.
 *
 * Every module that posts to the general ledger used to carry its account codes as constants, and
 * a tenant whose chart of accounts did not happen to use those codes got no postings at all —
 * correctly, since a journal against a guessed account is worse than no journal, but with nowhere
 * to say which accounts to use instead. This is that "instead".
 *
 * ── Resolution order ────────────────────────────────────────────────────────
 *
 *   1. A mapping this tenant has set.
 *   2. The platform default, which is seeded from the constants each module used to carry — so the
 *      day this shipped, every tenant resolved to exactly the code that was hardcoded and nothing
 *      changed for anybody.
 *   3. The module's own fallback, passed in by the caller. A safety net for a key added in code
 *      before its default row exists, not a place to keep the real answer.
 *
 * ── The account still has to exist ──────────────────────────────────────────
 *
 * Mapping a key to `5100` does not conjure account 5100. `postable()` is the question that matters
 * — the code must exist, be active, and be a leaf — and it is asked here rather than left to the
 * caller, because every caller asking it separately is every caller being able to forget.
 *
 * When the answer is no, this returns null and the caller does not post. That is deliberate and it
 * is the behaviour that was already right: a cost stays recorded in its own module, exportable and
 * re-postable, rather than landing somewhere it does not belong and having to be found and
 * reversed by somebody who did not make the mistake.
 */
class AccountMap
{
    /** @var array<string, ?string> resolved codes for this request */
    private array $cache = [];

    /**
     * The postable account code for a module's key, or null when there is nothing safe to use.
     *
     * @param  string|null  $fallback  the module's own constant, used only when nothing is mapped
     */
    public function codeFor(string $module, string $key, ?string $fallback = null): ?string
    {
        $companyId = app(CurrentCompany::class)->id();
        $cacheKey = $module . '|' . $key . '|' . ($companyId ?? 'null');

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        return $this->cache[$cacheKey] = $this->resolve($module, $key, $fallback, $companyId);
    }

    /** Whether a posting for this key can be made at all — the caller's gate. */
    public function has(string $module, string $key, ?string $fallback = null): bool
    {
        return $this->codeFor($module, $key, $fallback) !== null;
    }

    /**
     * Every key a module has a mapping or a default for, with whether it currently resolves.
     *
     * What the settings screen renders. An unmapped key is not an error to hide — it is the one
     * piece of information a tenant needs, because it is silently costing them postings.
     *
     * @return list<array{key:string, account_code:?string, label:?string, postable:bool, source:string}>
     */
    public function forModule(string $module): array
    {
        if (! Schema::hasTable('fin_account_mappings')) {
            return [];
        }

        $companyId = app(CurrentCompany::class)->id();

        $rows = FinAccountMapping::query()
            ->where('source_module', $module)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            // The tenant's own row last, so it overwrites the default in the keyed collection.
            ->orderByRaw('company_id nulls first')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[$row->key] = [
                'key'          => $row->key,
                'account_code' => $row->account_code,
                'label'        => $row->label,
                'postable'     => $this->isPostable($row->account_code),
                'source'       => $row->company_id === null ? 'default' : 'tenant',
            ];
        }

        return array_values($out);
    }

    /** Set a tenant's own mapping, leaving the platform default untouched. */
    public function set(string $module, string $key, string $code, ?int $companyId = null): FinAccountMapping
    {
        $companyId ??= app(CurrentCompany::class)->id();

        return FinAccountMapping::updateOrCreate(
            ['company_id' => $companyId, 'source_module' => $module, 'key' => $key],
            ['account_code' => $code],
        );
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * ── A tenant's own mapping is the last word ─────────────────────────────
     *
     * If they have set one and it does not resolve, nothing posts. It must not quietly fall through
     * to the platform default or the module's constant: somebody said "material costs go to 9910",
     * and posting them to 5000 instead because 9910 is missing is exactly the wrong entry this
     * class exists to prevent — one that looks right on the screen where it was configured and is
     * wrong in the books. Silence is recoverable; a misposting is a correcting journal and an
     * awkward conversation.
     *
     * Only when they have said nothing does the default apply, and then the module's constant.
     */
    private function resolve(string $module, string $key, ?string $fallback, ?int $companyId): ?string
    {
        $own = $this->mappedCode($module, $key, $companyId);

        if ($own !== null) {
            return $this->isPostable($own) ? $own : null;
        }

        foreach ([$this->mappedCode($module, $key, null), $fallback] as $code) {
            if ($code !== null && $this->isPostable($code)) {
                return $code;
            }
        }

        return null;
    }

    /** The code mapped for a company, or for the platform when $companyId is null. */
    private function mappedCode(string $module, string $key, ?int $companyId): ?string
    {
        if (! Schema::hasTable('fin_account_mappings')) {
            return null;
        }

        return FinAccountMapping::query()
            ->where('source_module', $module)
            ->where('key', $key)
            // Explicitly `is null` rather than `= null`, which matches nothing in SQL.
            ->when($companyId === null,
                fn ($q) => $q->whereNull('company_id'),
                fn ($q) => $q->where('company_id', $companyId))
            ->value('account_code');
    }

    /**
     * The account exists, is active and may carry journal lines.
     *
     * A mapping to a heading account is a mapping to something no journal can post against, and
     * finding that out at posting time is finding it out in a log nobody reads.
     */
    private function isPostable(?string $code): bool
    {
        if ($code === null || $code === '' || ! Schema::hasTable('fin_accounts')) {
            return false;
        }

        return FinAccount::query()
            ->where('code', $code)
            ->where('is_postable', true)
            ->where('is_active', true)
            ->exists();
    }
}
