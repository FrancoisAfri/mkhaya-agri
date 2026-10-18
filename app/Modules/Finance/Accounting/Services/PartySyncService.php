<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Shared\Party\PartyNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Keeps Accounting's parties in step with their source of truth when the owning
 * module is active: customers ← CRM accounts, suppliers ← Supplier Management.
 *
 * Standalone → the module off, parties are entered manually here. When a client
 * later activates CRM / Supplier Management, reconcile() matches existing manual
 * parties to the module's records (by email/name) and links them, and pushes any
 * unmatched manual parties UP into the module — so nothing is re-captured.
 */
class PartySyncService
{
    public function crmActive(): bool
    {
        return $this->moduleActive('crm') && Schema::hasTable('crm_accounts');
    }

    public function supplierMgmtActive(): bool
    {
        return $this->moduleActive('supplier_management') && Schema::hasTable('suppliers');
    }

    public function accountingActive(): bool
    {
        return $this->moduleActive('accounting') && Schema::hasTable('fin_parties');
    }

    /** How many Accounting customers have no CRM account yet — what a "sync" would bring over. */
    public function customersNotInCrm(): int
    {
        if (! $this->accountingActive()) {
            return 0;
        }

        return FinParty::query()->customers()->whereNull('crm_account_id')->count();
    }

    /**
     * A module is "active" for syncing only when it exists in the global registry AND the
     * CURRENT company's plan/subscription still includes it. The registry flag alone is
     * global (shared across tenants), so a single company that deactivates CRM (or Supplier
     * Management) would otherwise keep syncing — the reported bug. currentAllowsModule()
     * resolves the active company's entitlement; with no current company (console/platform),
     * it returns true so backfills/CLI still work.
     */
    private function moduleActive(string $code): bool
    {
        // Single source of truth for "is this module usable by this tenant".
        return app(\App\Modules\Shared\Party\ModuleAvailability::class)->active($code);
    }

    // ── Customers ← CRM ─────────────────────────────────────────────────────

    public function syncCustomersFromCrm(): int
    {
        if (! $this->crmActive()) {
            return 0;
        }

        $this->reconcileManualCustomers();

        $count = 0;
        DB::table('crm_accounts')->whereNull('deleted_at')->orderBy('id')
            ->chunk(200, function ($accounts) use (&$count) {
                foreach ($accounts as $a) {
                    $this->partyForCrmAccount($a);
                    $count++;
                }
            });

        return $count;
    }

    /** Match manual customers to CRM accounts (link), else push them up to CRM. */
    private function reconcileManualCustomers(): void
    {
        FinParty::query()->where('source', 'manual')->customers()->whereNull('crm_account_id')
            ->orderBy('id')->chunkById(200, function ($parties) {
                foreach ($parties as $party) {
                    $accountId = $this->findCrmAccountFor($party) ?? $this->createCrmAccountFrom($party);
                    $party->update(['crm_account_id' => $accountId, 'source' => 'crm', 'kind' => 'customer']);
                }
            });
    }

    /**
     * Push a specific batch of customers up into CRM — used straight after an accounting import,
     * so a migrated customer book lands in CRM too instead of only in Accounting. Each one is
     * matched against an existing CRM account first (email, then exact name, then the normalised
     * company name) and only created when nothing matches, so re-running is safe.
     *
     * @param  array<int>|null  $partyIds  ids from an import, or null for EVERY customer that
     *                                     isn't in CRM yet (the "sync from Accounting" action)
     * @return array{created:int, linked:int}
     */
    public function pushCustomersToCrm(?array $partyIds = null): array
    {
        if ($partyIds === [] || ! $this->crmActive()) {
            return ['created' => 0, 'linked' => 0];
        }

        $created = $linked = 0;
        FinParty::query()->when($partyIds !== null, fn ($q) => $q->whereIn('id', $partyIds))
            ->customers()->whereNull('crm_account_id')
            ->orderBy('id')->chunkById(200, function ($parties) use (&$created, &$linked) {
                foreach ($parties as $party) {
                    if ($accountId = $this->findCrmAccountFor($party)) {
                        $linked++;
                    } else {
                        $accountId = $this->createCrmAccountFrom($party);
                        $created++;
                    }
                    // kind is left as-is: a party that also supplies us stays 'both'.
                    $party->update(['crm_account_id' => $accountId, 'source' => 'crm']);
                }
            });

        return ['created' => $created, 'linked' => $linked];
    }

    private function findCrmAccountFor(FinParty $party): ?int
    {
        $q = DB::table('crm_accounts')->whereNull('deleted_at');
        if ($party->email) {
            $id = (clone $q)->whereRaw('lower(email) = ?', [strtolower($party->email)])->value('id');
            if ($id) {
                return (int) $id;
            }
        }
        $id = (clone $q)->whereRaw('lower(name) = ?', [strtolower($party->name)])->value('id');
        if ($id) {
            return (int) $id;
        }

        // Last resort: the same company written differently ("X (Pty) Ltd" vs "X").
        return $this->findCrmAccountByNormalisedName((string) $party->name);
    }

    /** @var array<string,int>|null  normalised CRM account name => id, built once per request */
    private ?array $crmNameIndex = null;

    private function findCrmAccountByNormalisedName(string $name): ?int
    {
        $dupes = app(\App\Modules\CRM\Services\CrmDuplicateService::class);
        $key = $dupes->normalizedName($name);
        if ($key === '') {
            return null;
        }

        if ($this->crmNameIndex === null) {
            $this->crmNameIndex = [];
            DB::table('crm_accounts')->whereNull('deleted_at')->select('id', 'name')->orderBy('id')
                ->chunk(500, function ($rows) use ($dupes) {
                    foreach ($rows as $row) {
                        $k = $dupes->normalizedName((string) $row->name);
                        // First one wins — the oldest account is the one to attach to.
                        if ($k !== '' && ! isset($this->crmNameIndex[$k])) {
                            $this->crmNameIndex[$k] = (int) $row->id;
                        }
                    }
                });
        }

        return $this->crmNameIndex[$key] ?? null;
    }

    private function createCrmAccountFrom(FinParty $party): int
    {
        $id = (int) DB::table('crm_accounts')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => $party->name,
            'email' => $party->email, 'phone' => $party->phone,
            'website' => $party->website ?? null, 'industry' => $party->category ?? null,
            'address' => $party->address, 'city' => $party->city, 'country' => $party->country,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Keep the in-memory index current so two spellings of one company in the same
        // import file don't each create their own CRM account.
        if ($this->crmNameIndex !== null) {
            $key = app(\App\Modules\CRM\Services\CrmDuplicateService::class)->normalizedName((string) $party->name);
            if ($key !== '' && ! isset($this->crmNameIndex[$key])) {
                $this->crmNameIndex[$key] = $id;
            }
        }

        return $id;
    }

    /** Ensure (and return) the customer party for a CRM account row or id. */
    /**
     * The columns a sync rewrites, and which therefore cannot be edited here.
     *
     * Kept beside the code that writes them, because the two must not drift: a field that Accounting
     * lets somebody change and the next sync silently puts back is worse than one it never offered
     * — the change appears to save, and disappears hours later with nothing to explain it.
     *
     * Everything not listed belongs to Accounting alone. The source system has no VAT number, no
     * credit limit, no sales rep and no debtor code, so refusing to edit those on a synced record
     * means they can never be set at all — which is the state the customers page was in.
     *
     * @return list<string>
     */
    public static function sourceOwnedColumns(?string $source): array
    {
        return match ($source) {
            'crm' => ['name', 'email', 'phone', 'address', 'city', 'country', 'is_active'],
            'supplier_mgmt' => [
                'name', 'email', 'phone', 'address', 'postal_address', 'vat_number',
                'bank_name', 'bank_account', 'credit_terms_days', 'is_active',
            ],
            default => [],
        };
    }

    public function partyForCrmAccount($account): ?FinParty
    {
        if (is_numeric($account)) {
            $account = DB::table('crm_accounts')->where('id', $account)->first();
        }
        if (! $account) {
            return null;
        }

        /*
         * Contact details are refreshed from CRM on every sync, but identity is
         * NOT. updateOrCreate() would rewrite `code` each run, which would undo a
         * debtor code granted when Accounting was switched on (DEB000123 reset to
         * CRM-12) and break every invoice that shows it. Identity is written once,
         * on creation, and left alone afterwards.
         */
        $mutable = [
            'name'      => $account->name ?: ('Account ' . $account->id),
            'email'     => $account->email ?? null,
            'phone'     => $account->phone ?? null,
            'address'   => $account->address ?? null,
            'city'      => $account->city ?? null,
            'country'   => $account->country ?? null,
            'is_active' => true,
        ];

        $party = FinParty::where('crm_account_id', $account->id)->first();

        if ($party) {
            $party->fill($mutable)->save();

            return $party;
        }

        $party = FinParty::create($mutable + [
            'crm_account_id' => $account->id,
            'kind'           => 'customer',
            'source'         => 'crm',
            'code'           => 'CRM-' . $account->id,
        ]);

        // Every party gets a permanent customer number, whether or not Accounting
        // is in use; the debtor code is granted separately on promotion.
        app(PartyNumberService::class)->ensureCustomerNumber($party, 'crm');

        return $party->refresh();
    }

    // ── Suppliers ← Supplier Management ─────────────────────────────────────

    public function syncSuppliersFromModule(): int
    {
        if (! $this->supplierMgmtActive()) {
            return 0;
        }

        $this->reconcileManualSuppliers();

        $count = 0;
        DB::table('suppliers')->whereNull('deleted_at')->orderBy('id')
            ->chunk(200, function ($suppliers) use (&$count) {
                foreach ($suppliers as $s) {
                    $this->partyForSupplier($s);
                    $count++;
                }
            });

        return $count;
    }

    private function reconcileManualSuppliers(): void
    {
        FinParty::query()->where('source', 'manual')->suppliers()->whereNull('supplier_id')
            ->orderBy('id')->chunkById(200, function ($parties) {
                foreach ($parties as $party) {
                    $supplierId = $this->findSupplierFor($party) ?? $this->createSupplierFrom($party);
                    $party->update(['supplier_id' => $supplierId, 'source' => 'supplier_mgmt', 'kind' => 'supplier']);
                }
            });
    }

    private function findSupplierFor(FinParty $party): ?int
    {
        $q = DB::table('suppliers')->whereNull('deleted_at');
        if ($party->email) {
            $id = (clone $q)->whereRaw('lower(email) = ?', [strtolower($party->email)])->value('id');
            if ($id) {
                return (int) $id;
            }
        }
        $id = $q->whereRaw('lower(company_name) = ?', [strtolower($party->name)])->value('id');

        return $id ? (int) $id : null;
    }

    private function createSupplierFrom(FinParty $party): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_name' => $party->name,
            'email' => $party->email, 'phone_number' => $party->phone,
            'physical_address' => $party->address, 'postal_address' => $party->postal_address,
            'vat_registration_number' => $party->vat_number,
            'bank_name' => $party->bank_name, 'bank_account_number' => $party->bank_account,
            'payment_terms' => (int) ($party->credit_terms_days ?: 30),
            'fraud_corruption_policy_agreement' => false,
            'public_liability_insurance' => false,
            'health_safety_compliance' => false,
            'is_active' => true, 'is_preferred' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function partyForSupplier($supplier): ?FinParty
    {
        if (is_numeric($supplier)) {
            $supplier = DB::table('suppliers')->where('id', $supplier)->first();
        }
        if (! $supplier) {
            return null;
        }

        // Same rule as customers: refresh the detail, never rewrite identity.
        $mutable = [
            'name'              => $supplier->company_name ?: ($supplier->trading_name ?: ('Supplier ' . $supplier->id)),
            'email'             => $supplier->email ?? null,
            'phone'             => $supplier->phone_number ?? null,
            'address'           => $supplier->physical_address ?? null,
            'postal_address'    => $supplier->postal_address ?? null,
            'vat_number'        => $supplier->vat_registration_number ?? null,
            'bank_name'         => $supplier->bank_name ?? null,
            'bank_account'      => $supplier->bank_account_number ?? null,
            'credit_terms_days' => (int) ($supplier->payment_terms ?: 30),
            'is_active'         => (bool) ($supplier->is_active ?? true),
        ];

        $party = FinParty::where('supplier_id', $supplier->id)->first();

        if ($party) {
            $party->fill($mutable)->save();

            return $party;
        }

        $party = FinParty::create($mutable + [
            'supplier_id' => $supplier->id,
            'kind'        => 'supplier',
            'source'      => 'supplier_mgmt',
            'code'        => $supplier->supplier_number ?: ('SUP-' . $supplier->id),
        ]);

        app(PartyNumberService::class)->ensureCustomerNumber($party, 'supplier_mgmt');

        return $party->refresh();
    }
}
