<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * Subcontractors a project is delivered with. Local: pm_external_refs. Integrated: suppliers.
 *
 * The client was explicit about why this seam exists: somebody may buy Project Management on its
 * own, and a subcontractor is not an optional part of running a project. So PM owns the register
 * when it is alone, and points at the Supplier module's records when that module is present — the
 * same choice already made for clients and CRM.
 *
 * There is no onboarding here. A subcontractor is added by name and contact, and is given portal
 * access so they can invoice; vetting, B-BBEE scoring and vendor compliance are the Supplier
 * module's job on the tenants that have it.
 */
interface ContractorProvider
{
    /** @return list<ProvidedRecord> */
    public function search(string $query, int $limit = 20): array;

    public function get(string $id): ?ProvidedRecord;

    /**
     * Record a subcontractor in whichever system owns them here.
     *
     * @param  array{name:string, email?:?string, phone?:?string, registration_number?:?string,
     *               vat_number?:?string, notes?:?string}  $data
     */
    public function create(array $data): ProvidedRecord;

    /** True when subcontractors live in another module, so PM should link rather than own. */
    public function isAuthoritative(): bool;
}
