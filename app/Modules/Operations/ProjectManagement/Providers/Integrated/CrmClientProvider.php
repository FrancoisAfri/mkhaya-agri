<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\Finance\Accounting\Services\PartySyncService;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ClientProvider;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Clients, backed by CRM accounts.
 *
 * Selected when the tenant has `crm.customers`. The customer record stays in one place: PM links to
 * it rather than keeping a second name that drifts the first time someone is renamed in CRM.
 */
class CrmClientProvider implements ClientProvider
{
    public function search(string $query, int $limit = 20): array
    {
        return CrmAccount::query()
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%' . $query . '%')
                ->orWhere('email', 'ilike', '%' . $query . '%')))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (CrmAccount $a) => $this->present($a))
            ->all();
    }

    public function get(string $id): ?ProvidedRecord
    {
        $isUuid = Str::isUuid($id);
        $isNumeric = ctype_digit($id);

        // Neither shape: it cannot match anything, and asking Postgres to compare it to a bigint
        // would raise and abort the surrounding transaction rather than simply finding nothing.
        if (! $isUuid && ! $isNumeric) {
            return null;
        }

        $account = CrmAccount::query()
            ->where(fn ($q) => $q
                ->when($isUuid, fn ($w) => $w->orWhere('uuid', $id))
                ->when($isNumeric, fn ($w) => $w->orWhere('id', (int) $id)))
            ->first();

        /*
         * The finance details are added here and not in search().
         *
         * `get()` is what resolves a client that has been *chosen* — it is what builds the ref
         * whose snapshot an invoice reads. search() draws a list of names, and paying for a
         * per-row lookup to decorate twenty rows nobody has picked yet is twenty queries for
         * nothing.
         */
        return $account ? $this->present($account, withFinance: true) : null;
    }

    /**
     * What Accounting knows about this client that CRM does not.
     *
     * `crm_accounts` has no VAT or registration number — those live on the accounting customer,
     * which is where somebody enters them and where the invoice run reads them. So a client picked
     * on an invoice filled in a name and left the VAT number blank to be typed from memory, on a
     * document that is not a valid tax invoice without it.
     *
     * Guarded on the table, not the module: Project Management must keep working on a tenant that
     * has not bought Accounting, and a missing table is the honest test for that.
     *
     * @return array<string, mixed>
     */
    private function financeDetails(CrmAccount $account): array
    {
        if (! Schema::hasTable('fin_parties')) {
            return [];
        }

        $party = app(PartySyncService::class)->partyForCrmAccount($account->id);

        if ($party === null) {
            return [];
        }

        return array_filter([
            'vat_number' => $party->vat_number,
            'registration_number' => $party->registration_number,
            // Accounting's address only where CRM has none: CRM is the contact record and the one
            // people keep current, so it wins where both hold an answer.
            'address' => $account->address ?: $party->address,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * A client added from a project screen becomes a CRM account, not a PM-local name.
     *
     * This is the whole point of the seam. Someone starting a job at eight in the morning should
     * not have to open another module to type a customer's name — but the name they type belongs
     * in the customer list everybody else reads, not in a shadow one behind Project Management.
     *
     * An existing account with the same name is reused rather than duplicated: on a tenant with
     * CRM, two "Sasol" accounts is a worse outcome than a project attached to the wrong-but-real
     * one, and the second is visible while the first quietly splits the reporting.
     */
    public function create(array $data): ProvidedRecord
    {
        $existing = CrmAccount::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))])
            ->first();

        if ($existing) {
            return $this->present($existing);
        }

        $account = CrmAccount::create(array_filter([
            'uuid'       => (string) Str::uuid(),
            'name'       => trim($data['name']),
            'email'      => $data['email'] ?? null,
            'phone'      => $data['phone'] ?? null,
            // The address is the reason this form exists at all: an invoice cannot be addressed to
            // a name, and without somewhere to type it here it was retyped on every first account.
            'address'    => $data['address'] ?? null,
            'city'       => $data['city'] ?? null,
            'country'    => $data['country'] ?? null,
            'website'    => $data['website'] ?? null,
            'notes'      => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ], fn ($v) => $v !== null && $v !== ''));

        return $this->present($account);
    }

    public function isAuthoritative(): bool
    {
        return true;
    }

    /**
     * The deals won for this client (`crm.opportunities`).
     *
     * Won only. An open deal is a hope and a lost one is history; neither belongs beside a project
     * that is already being delivered, and showing them would make the figure on the page mean
     * something different from what a reader assumes it means.
     *
     * @return list<ProvidedRecord>
     */
    public function opportunitiesFor(string $clientId, int $limit = 10): array
    {
        if (! ModuleTable::ready('crm_deals', ['account_id', 'title', 'amount', 'status'])) {
            return [];
        }

        $account = $this->accountKey($clientId);

        if ($account === null) {
            return [];
        }

        $rows = ModuleTable::query('crm_deals')
            ?->where('account_id', $account)
            ->whereIn('status', ['won', 'Won', 'closed_won'])
            ->orderByDesc('closed_at')
            ->limit($limit)
            ->get() ?? [];

        return collect($rows)->map(fn ($deal) => new ProvidedRecord(
            id: (string) ($deal->uuid ?: $deal->id),
            label: (string) ($deal->title ?: 'Untitled deal'),
            source: 'crm',
            meta: [
                'amount'    => $deal->amount !== null ? (float) $deal->amount : null,
                'currency'  => $deal->currency,
                'closed_at' => $deal->closed_at,
            ],
        ))->all();
    }

    /** The CRM account's primary key, from whichever form of id the caller had. */
    private function accountKey(string $clientId): ?int
    {
        $isUuid = Str::isUuid($clientId);
        $isNumeric = ctype_digit($clientId);

        if (! $isUuid && ! $isNumeric) {
            return null;
        }

        return CrmAccount::query()
            ->where(fn ($q) => $q
                ->when($isUuid, fn ($w) => $w->orWhere('uuid', $clientId))
                ->when($isNumeric, fn ($w) => $w->orWhere('id', (int) $clientId)))
            ->value('id');
    }

    private function present(CrmAccount $account, bool $withFinance = false): ProvidedRecord
    {
        return new ProvidedRecord(
            id: (string) ($account->uuid ?: $account->id),
            label: $account->name ?: 'Unnamed account',
            source: 'crm',
            email: $account->email,
            /*
             * The address travels with the record because an invoice needs it.
             *
             * Picking a client on an invoice filled in a name and left the "To:" address blank for
             * somebody to retype from memory — and CRM was holding it the whole time. The local
             * provider already passes its whole snapshot through for exactly this reason.
             */
            meta: array_merge([
                'industry' => $account->industry,
                'city' => $account->city,
                'country' => $account->country,
                'website' => $account->website,
                'address' => $account->address,
            ], $withFinance ? $this->financeDetails($account) : []),
        );
    }
}
