<?php

namespace App\Modules\SupplierManagement\Services;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use Illuminate\Support\Collection;

/**
 * Whether a supplier's mandatory paperwork is actually in order, asked at the moment it matters.
 *
 * `supplier_document_types.compulsory` was set in configuration, shown on the onboarding checklist,
 * and never consulted by anything: a supplier with every compulsory document missing activated
 * without complaint, and one whose tax clearance lapsed six months ago kept receiving orders until
 * the nightly job happened to notice.
 *
 * The nightly job is still the right place to *suspend* a supplier and tell somebody. This is the
 * synchronous half — the question asked at activation and at the point of spend, so the answer does
 * not depend on when a cron last ran.
 */
class SupplierComplianceService
{
    /**
     * Compulsory document types this supplier has not provided at all.
     *
     * @return Collection<int, string> the document type names, for a message a person can act on
     */
    public function missingDocuments(Supplier $supplier): Collection
    {
        $required = $this->requiredTypes();

        if ($required->isEmpty()) {
            return collect();
        }

        $held = $supplier->documents()->pluck('document_type_id')->filter()->unique();

        return $required->reject(fn ($name, $id) => $held->contains($id))->values();
    }

    /**
     * Compulsory documents that were provided but have since expired.
     *
     * Documents with no expiry date are treated as not expiring — plenty of them genuinely do not,
     * and inventing a lapse for them would block suppliers who have done nothing wrong.
     */
    public function lapsedDocuments(Supplier $supplier): Collection
    {
        $required = $this->requiredTypes();

        if ($required->isEmpty()) {
            return collect();
        }

        return $supplier->documents()
            ->whereIn('document_type_id', $required->keys())
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->get()
            ->map(fn ($document) => $required->get($document->document_type_id))
            ->filter()
            ->unique()
            ->values();
    }

    /** Everything standing between this supplier and being allowed to trade. */
    public function blockers(Supplier $supplier): Collection
    {
        return $this->missingDocuments($supplier)->merge($this->lapsedDocuments($supplier))->unique()->values();
    }

    /** @return Collection<int, string> compulsory type ids keyed to their names */
    private function requiredTypes(): Collection
    {
        return SupplierDocumentType::query()
            ->where('active', true)
            ->where('compulsory', true)
            ->pluck('document_type_name', 'id');
    }
}
