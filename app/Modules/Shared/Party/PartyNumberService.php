<?php

namespace App\Modules\Shared\Party;

use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Support\Facades\DB;

/**
 * Issues the two identifiers a customer can carry.
 *
 *   customer_number  CUS000123  permanent, issued the moment the party exists,
 *                               whichever module created it
 *   code (debtor)    DEB000123  granted when Accounting is in use; appears on
 *                               invoices and statements
 *
 * They are additive, never swapped. A number that has appeared on a ticket, a
 * quote or an email cannot be reissued without breaking every human reference to
 * it and the audit trail behind it — so promotion grants the debtor code and
 * leaves the customer number alone.
 */
class PartyNumberService
{
    public const CUSTOMER_PREFIX = 'CUS';
    public const DEBTOR_PREFIX   = 'DEB';
    private const PAD = 6;

    /**
     * Next customer number for the active tenant.
     *
     * Derived from the highest existing number rather than a counter table, so it
     * cannot drift out of step with the rows it names. Wrapped in a transaction
     * with a row lock to keep two simultaneous creations from colliding.
     */
    public function nextCustomerNumber(?int $companyId = null): string
    {
        return DB::transaction(function () use ($companyId) {
            $highest = FinParty::query()
                ->withoutGlobalScopes()
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->whereNotNull('customer_number')
                ->where('customer_number', 'like', self::CUSTOMER_PREFIX . '%')
                ->lockForUpdate()
                ->orderByRaw('length(customer_number) desc, customer_number desc')
                ->value('customer_number');

            $next = $highest ? ((int) substr($highest, strlen(self::CUSTOMER_PREFIX))) + 1 : 1;

            return self::CUSTOMER_PREFIX . str_pad((string) $next, self::PAD, '0', STR_PAD_LEFT);
        });
    }

    /** Give a party its permanent customer number if it has none. */
    public function ensureCustomerNumber(FinParty $party, ?string $createdByModule = null): FinParty
    {
        if ($party->customer_number) {
            return $party;
        }

        $party->forceFill([
            'customer_number'   => $this->nextCustomerNumber($party->company_id),
            'created_by_module' => $party->created_by_module ?: $createdByModule,
        ])->save();

        return $party;
    }

    /**
     * Promote a party to an Accounting customer: grant the debtor code and stamp
     * when it happened. Existing documents keep whatever they were issued with.
     *
     * Idempotent — a party already promoted is returned untouched, so running a
     * backfill twice cannot renumber anyone.
     */
    public function promoteToDebtor(FinParty $party): FinParty
    {
        if ($party->debtor_code_issued_at) {
            return $party;
        }

        $code = $party->code ?: $this->nextDebtorCode($party->company_id);

        $party->forceFill([
            'code'                  => $code,
            'debtor_code_issued_at' => now(),
        ])->save();

        return $party;
    }

    public function nextDebtorCode(?int $companyId = null): string
    {
        return DB::transaction(function () use ($companyId) {
            $highest = FinParty::query()
                ->withoutGlobalScopes()
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->where('code', 'like', self::DEBTOR_PREFIX . '%')
                ->lockForUpdate()
                ->orderByRaw('length(code) desc, code desc')
                ->value('code');

            $next = $highest ? ((int) substr($highest, strlen(self::DEBTOR_PREFIX))) + 1 : 1;

            return self::DEBTOR_PREFIX . str_pad((string) $next, self::PAD, '0', STR_PAD_LEFT);
        });
    }
}
