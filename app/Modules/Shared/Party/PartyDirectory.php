<?php

namespace App\Modules\Shared\Party;

use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Support\Collection;

/**
 * The one sanctioned way for a module to look a customer up.
 *
 * Helpdesk, Fleet, Property and the rest resolve customers through here rather
 * than importing FinParty, so "Accounting owns the financial identity" stays
 * true without every module having to depend on Accounting being bought,
 * switched on, or even present in the tenant's plan.
 *
 * The contract that makes that work: financial detail is returned only when
 * Accounting is available, and null otherwise. Callers take the null path — they
 * never catch an exception to discover a module is off.
 */
class PartyDirectory
{
    public function __construct(
        private readonly ModuleAvailability $modules,
        private readonly PartyNumberService $numbers,
    ) {}

    // ── Lookup ────────────────────────────────────────────────────────────────

    public function find(string $uuid): ?FinParty
    {
        return FinParty::query()->where('uuid', $uuid)->first();
    }

    /** Look up by either identifier — users will type whichever they know. */
    public function findByNumber(string $number): ?FinParty
    {
        $number = trim($number);

        return FinParty::query()
            ->where('customer_number', $number)
            ->orWhere('code', $number)
            ->first();
    }

    /** @return Collection<int, FinParty> */
    public function search(string $term, int $limit = 20): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        return FinParty::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('customer_number', 'ilike', "%{$term}%")
                ->orWhere('code', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%"))
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    // ── Identity (always available) ───────────────────────────────────────────

    /**
     * What every module may rely on, with or without Accounting.
     *
     * @return array<string, mixed>|null
     */
    public function identity(string $uuid): ?array
    {
        $party = $this->find($uuid);

        if (! $party) {
            return null;
        }

        return [
            'uuid'            => $party->uuid,
            'name'            => $party->name,
            'customer_number' => $party->customer_number,
            'display_number'  => $this->displayNumber($party),
            'email'           => $party->email,
            'phone'           => $party->phone,
            'is_active'       => (bool) $party->is_active,
        ];
    }

    /**
     * The number to show a user: the debtor code once Accounting has granted one,
     * otherwise the customer number. Both remain on the record either way.
     */
    public function displayNumber(FinParty|string $party): ?string
    {
        $party = $party instanceof FinParty ? $party : $this->find($party);

        if (! $party) {
            return null;
        }

        return $this->accountingAvailable() && $party->debtor_code_issued_at && $party->code
            ? $party->code
            : $party->customer_number;
    }

    /** Can this customer be transacted with at all? */
    public function isActive(string $uuid): bool
    {
        return (bool) $this->find($uuid)?->is_active;
    }

    // ── Financial (Accounting only) ───────────────────────────────────────────

    public function accountingAvailable(): bool
    {
        return $this->modules->active('accounting');
    }

    /**
     * Credit terms, limit and hold state — or null when Accounting is not
     * available to this tenant. A caller that gets null simply has no financial
     * opinion to apply; it must not treat that as "no credit".
     *
     * @return array<string, mixed>|null
     */
    public function financialProfile(string $uuid): ?array
    {
        if (! $this->accountingAvailable()) {
            return null;
        }

        $party = $this->find($uuid);

        if (! $party) {
            return null;
        }

        return [
            'debtor_code'       => $party->code,
            'promoted_at'       => $party->debtor_code_issued_at,
            'credit_limit'      => $party->credit_limit,
            'credit_terms_days' => $party->credit_terms_days,
            'on_hold'           => (bool) $party->on_hold,
            'vat_number'        => $party->vat_number,
            'tax_exempt'        => (bool) $party->tax_exempt,
        ];
    }

    /**
     * Should work be refused on credit grounds?
     *
     * False whenever Accounting is unavailable — a tenant without Accounting has
     * no credit control, so nothing may be blocked on that basis.
     */
    public function isOnCreditHold(string $uuid): bool
    {
        return (bool) ($this->financialProfile($uuid)['on_hold'] ?? false);
    }

    // ── Creation ──────────────────────────────────────────────────────────────

    /**
     * Create a party from any module. It always gets a customer number; it only
     * gets a debtor code if Accounting is available, so a CRM-only tenant never
     * accumulates meaningless finance codes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, string $createdByModule): FinParty
    {
        $party = FinParty::create($attributes + [
            'kind'      => $attributes['kind'] ?? 'customer',
            'is_active' => $attributes['is_active'] ?? true,
        ]);

        $this->numbers->ensureCustomerNumber($party, $createdByModule);

        if ($this->accountingAvailable()) {
            $this->numbers->promoteToDebtor($party);
        }

        return $party->refresh();
    }

    /**
     * Bring existing parties up to date after Accounting is switched on: everyone
     * gains a debtor code, nobody is renumbered.
     *
     * @return int  how many were promoted
     */
    public function promoteAllToDebtors(): int
    {
        if (! $this->accountingAvailable()) {
            return 0;
        }

        $promoted = 0;

        FinParty::query()
            ->whereNull('debtor_code_issued_at')
            ->orderBy('id')
            ->chunkById(200, function ($parties) use (&$promoted) {
                foreach ($parties as $party) {
                    $this->numbers->promoteToDebtor($party);
                    $promoted++;
                }
            });

        return $promoted;
    }
}
