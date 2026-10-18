<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * Customers a project is delivered for. Local: pm_external_refs. Integrated: CRM accounts.
 *
 * `create()` is what makes the module standalone-capable in practice rather than in principle. A
 * project has a client field; if the only way to fill it were "install CRM first", the field would
 * be a dead end on every tenant that has not bought CRM. So PM can create one — and when CRM *is*
 * installed, the same call creates it in CRM instead, so there is never a second customer list
 * quietly growing beside the real one.
 */
interface ClientProvider
{
    /** @return list<ProvidedRecord> */
    public function search(string $query, int $limit = 20): array;

    public function get(string $id): ?ProvidedRecord;

    /**
     * Create a client in whichever system owns them here.
     *
     * @param  array{name:string, email?:?string, phone?:?string, city?:?string, notes?:?string}  $data
     */
    public function create(array $data): ProvidedRecord;

    /** True when clients live in another module, so PM should link rather than own. */
    public function isAuthoritative(): bool;

    /**
     * Deals won for this client (`crm.opportunities`).
     *
     * The question it answers on a project page is "what did we sell them, and for how much?" —
     * which is the number a project manager is measured against and, without this, lives only in
     * somebody's memory of the proposal.
     *
     * @return list<ProvidedRecord>
     */
    public function opportunitiesFor(string $clientId, int $limit = 10): array;
}
