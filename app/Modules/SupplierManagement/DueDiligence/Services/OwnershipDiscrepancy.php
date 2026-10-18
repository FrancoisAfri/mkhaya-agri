<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierVerification;

/**
 * Compares the ownership a supplier declared against the ownership a registry reports.
 *
 * This is the check the plan singled out as an automatic trigger for enhanced review, and the reason
 * is worth stating: the gap between the two lists is where the interesting name lives. Somebody who
 * would fail a screening is left off the form and appears in the filing; or appears on the form at
 * 4% and in the filing at 40%. Either way the discrepancy is the finding, and neither list on its own
 * would have produced it.
 *
 * Set comparison rather than field-by-field, because ownership is a list whose order means nothing.
 * Three kinds of difference are reported separately, since they are different problems:
 *
 *  - undisclosed: the registry names an owner the supplier did not
 *  - unconfirmed: the supplier names an owner the registry does not
 *  - percentage:  both name them, at materially different sizes
 */
class OwnershipDiscrepancy
{
    /** Percentage points of difference below which two figures are treated as the same stake. */
    private const TOLERANCE = 1.0;

    public function __construct(
        private readonly FieldComparator $comparator,
        private readonly VerificationEngine $engine,
    ) {}

    /**
     * Compare and record the result as an `ownership.beneficial` observation.
     *
     * @param  array<int, array{name:string, percentage?:float|null}>  $registryOwners  what the source reported
     */
    public function compare(Supplier $supplier, array $registryOwners, string $source = 'registry', ?string $reference = null): SupplierVerification
    {
        $declared = $this->declaredOwners($supplier);
        $observed = $this->normaliseOwners($registryOwners);

        $undisclosed = array_diff_key($observed, $declared);
        $unconfirmed = array_diff_key($declared, $observed);
        $different = [];

        foreach (array_intersect_key($declared, $observed) as $key => $owner) {
            $ours = $owner['percentage'];
            $theirs = $observed[$key]['percentage'];

            if ($ours === null || $theirs === null) {
                continue;   // One side did not state a size; that is not a disagreement about size.
            }

            if (abs($ours - $theirs) > self::TOLERANCE) {
                $different[$key] = ['name' => $owner['name'], 'declared' => $ours, 'registry' => $theirs];
            }
        }

        $matches = $undisclosed === [] && $unconfirmed === [] && $different === [];

        return $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::OWNERSHIP_BENEFICIAL,
            status: $matches ? VerificationStatus::VERIFIED : VerificationStatus::MISMATCH,
            observed: $this->asFields($observed),
            note: $this->summarise($undisclosed, $unconfirmed, $different, $source),
            reference: $reference,
            claimed: $this->asFields($declared),
            // Ownership is a set, not a row of fields, so the comparison is made here and handed to
            // the engine rather than left to the generic comparator — which would read "60%" and
            // "60%" as two names and, worse, read a tolerated 0.5% difference as a mismatch.
            comparison: $this->comparison($declared, $observed, $undisclosed, $unconfirmed, $different),
        );
    }

    /**
     * Per-owner verdicts, in the shape every other check stores.
     *
     * Keeping the same shape is what lets one screen render a discrepancy from any check, and lets
     * `mismatchedFields()` name the owners at fault without knowing anything about ownership.
     */
    private function comparison(array $declared, array $observed, array $undisclosed, array $unconfirmed, array $different): array
    {
        $comparison = [];

        foreach ($observed as $key => $owner) {
            $field = 'owner: '.$owner['name'];

            if (isset($undisclosed[$key])) {
                $comparison[$field] = [
                    'match' => false,
                    'kind' => 'undisclosed',
                    'claimed' => null,
                    'observed' => $this->stake($owner['percentage']),
                    'note' => 'Named by the source; not disclosed by the supplier.',
                ];

                continue;
            }

            $comparison[$field] = isset($different[$key])
                ? [
                    'match' => false,
                    'kind' => 'percentage',
                    'claimed' => $this->stake($different[$key]['declared']),
                    'observed' => $this->stake($different[$key]['registry']),
                    'note' => 'The size of the stake does not agree.',
                ]
                : [
                    'match' => true,
                    'kind' => 'exact',
                    'claimed' => $this->stake($declared[$key]['percentage'] ?? null),
                    'observed' => $this->stake($owner['percentage']),
                    'note' => null,
                ];
        }

        foreach ($unconfirmed as $key => $owner) {
            $comparison['owner: '.$owner['name']] = [
                'match' => false,
                'kind' => 'unconfirmed',
                'claimed' => $this->stake($owner['percentage']),
                'observed' => null,
                'note' => 'Declared by the supplier; not listed by the source.',
            ];
        }

        return $comparison;
    }

    private function stake(?float $percentage): ?string
    {
        return $percentage === null ? null : rtrim(rtrim(number_format($percentage, 2), '0'), '.').'%';
    }

    /**
     * What the supplier has told us, keyed by normalised name.
     *
     * Beneficial owners rather than immediate shareholders: the registry filing this is compared
     * against is a beneficial-ownership filing, so comparing it to a list of holding companies would
     * produce a discrepancy on every well-structured supplier.
     *
     * @return array<string, array{name:string, percentage:float|null}>
     */
    private function declaredOwners(Supplier $supplier): array
    {
        $owners = [];

        foreach (app(OwnershipGraph::class)->ultimateBeneficialOwners($supplier, 0.0) as $ubo) {
            $key = $this->comparator->normalisePersonName($ubo['name']);

            if ($key === '') {
                continue;
            }

            $owners[$key] = ['name' => $ubo['name'], 'percentage' => (float) $ubo['effective_percentage']];
        }

        return $owners;
    }

    /** @return array<string, array{name:string, percentage:float|null}> */
    private function normaliseOwners(array $owners): array
    {
        $normalised = [];

        foreach ($owners as $owner) {
            $name = trim((string) ($owner['name'] ?? ''));
            $key = $this->comparator->normalisePersonName($name);

            if ($key === '') {
                continue;
            }

            $percentage = $owner['percentage'] ?? null;

            $normalised[$key] = [
                'name' => $name,
                'percentage' => $percentage === null || $percentage === '' ? null : (float) $percentage,
            ];
        }

        return $normalised;
    }

    /** Flattened for the observation, so the stored record reads as owner-name → stake. */
    private function asFields(array $owners): array
    {
        $fields = [];

        foreach ($owners as $owner) {
            $fields['owner: '.$owner['name']] = $owner['percentage'] === null
                ? 'stake not stated'
                : number_format($owner['percentage'], 2).'%';
        }

        return $fields;
    }

    private function summarise(array $undisclosed, array $unconfirmed, array $different, string $source): string
    {
        if ($undisclosed === [] && $unconfirmed === [] && $different === []) {
            return "Declared ownership agrees with the {$source} record.";
        }

        $parts = [];

        if ($undisclosed !== []) {
            $parts[] = sprintf(
                'the %s names %s the supplier did not disclose',
                $source,
                $this->names($undisclosed)
            );
        }

        if ($unconfirmed !== []) {
            $parts[] = sprintf(
                'the supplier declared %s the %s does not list',
                $this->names($unconfirmed),
                $source
            );
        }

        foreach ($different as $entry) {
            $parts[] = sprintf(
                '%s is declared at %s%% and recorded at %s%%',
                $entry['name'],
                rtrim(rtrim(number_format($entry['declared'], 2), '0'), '.'),
                rtrim(rtrim(number_format($entry['registry'], 2), '0'), '.')
            );
        }

        return 'Ownership discrepancy — '.implode('; ', $parts).'.';
    }

    private function names(array $owners): string
    {
        return collect($owners)->pluck('name')->implode(', ');
    }
}
