<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierBlacklistEntry;
use App\Modules\SupplierManagement\Models\SupplierEntity;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use Illuminate\Support\Facades\Auth;

/**
 * The bar list, and the check that makes it worth keeping.
 *
 * A blacklist that only matches the exact company you barred stops nothing. The company is
 * deregistered, a new one is registered the following week with the same directors at the same
 * address, and it applies again — so the check has to look at the people and the ownership chain as
 * well as the name on the door.
 *
 * Hence three ways a supplier can hit the list:
 *
 *  - directly: same registration number, or the same name once entity forms are stripped
 *  - by person: a barred individual is a director, shareholder or beneficial owner
 *  - by chain: a barred company appears anywhere in the ownership graph
 *
 * The check is a warning with its reasons, not a silent refusal. Somebody has to be told what the
 * match was so they can confirm it is the same party — "blocked" with no explanation gets overridden
 * out of frustration, which is worse than not blocking.
 */
class Blacklist
{
    public function __construct(private readonly FieldComparator $comparator) {}

    /**
     * Does anything about this supplier hit the bar list?
     *
     * @return array{blocked:bool, matches:array<int, array{kind:string, matched:string, reason:string, entry_id:int, decided_at:string|null}>}
     */
    public function check(Supplier $supplier): array
    {
        $matches = [];

        foreach ($this->entityMatches($supplier) as $match) {
            $matches[] = $match;
        }

        foreach ($this->personMatches($supplier) as $match) {
            $matches[] = $match;
        }

        foreach ($this->chainMatches($supplier) as $match) {
            $matches[] = $match;
        }

        return ['blocked' => $matches !== [], 'matches' => $matches];
    }

    /**
     * Check a prospective supplier before it exists as a record.
     *
     * This is the version that matters at onboarding: the point of the list is to stop the
     * application, not to annotate a supplier already in the directory.
     */
    public function checkDetails(?string $name, ?string $registrationNumber = null, array $personNames = []): array
    {
        $matches = [];
        $normalised = $name ? $this->comparator->normaliseEntityName($name) : null;
        $digits = $registrationNumber ? preg_replace('/\D+/', '', $registrationNumber) : null;

        $entries = SupplierBlacklistEntry::query()->live()->get();

        foreach ($entries as $entry) {
            if ($digits && $entry->registration_number
                && preg_replace('/\D+/', '', $entry->registration_number) === $digits) {
                $matches[] = $this->describe($entry, 'registration_number', $entry->entity_name);

                continue;
            }

            if ($normalised && $entry->normalised_name && $entry->normalised_name === $normalised) {
                $matches[] = $this->describe($entry, 'name', $entry->entity_name);
            }
        }

        foreach ($personNames as $personName) {
            $key = $this->comparator->normalisePersonName((string) $personName);

            if ($key === '') {
                continue;
            }

            foreach ($entries->where('scope', SupplierBlacklistEntry::SCOPE_PERSON) as $entry) {
                if ($this->comparator->normalisePersonName($entry->entity_name) === $key) {
                    $matches[] = $this->describe($entry, 'person', $entry->entity_name);
                }
            }
        }

        return ['blocked' => $matches !== [], 'matches' => $matches];
    }

    /** Bar an entity or a person. */
    public function add(array $attributes): SupplierBlacklistEntry
    {
        $identifier = $attributes['identifier'] ?? null;

        return SupplierBlacklistEntry::create([
            'supplier_id' => $attributes['supplier_id'] ?? null,
            'entity_name' => $attributes['entity_name'],
            'registration_number' => $attributes['registration_number'] ?? null,
            'scope' => $attributes['scope'] ?? SupplierBlacklistEntry::SCOPE_ENTITY,
            'person_id' => $attributes['person_id'] ?? null,
            // Hashed: matching needs "same number", not the number.
            'person_identifier_hash' => $identifier ? SupplierBlacklistEntry::hashIdentifier($identifier) : null,
            'reason' => $attributes['reason'],
            'evidence' => $attributes['evidence'] ?? null,
            'severity' => $attributes['severity'] ?? 'permanent',
            'decided_by' => Auth::id(),
            'decided_role' => $attributes['decided_role'] ?? null,
            'review_on' => $attributes['review_on'] ?? null,
            'expires_on' => $attributes['expires_on'] ?? null,
            'is_active' => true,
        ]);
    }

    private function entityMatches(Supplier $supplier): array
    {
        $normalised = $this->comparator->normaliseEntityName((string) $supplier->company_name);
        $digits = preg_replace('/\D+/', '', (string) $supplier->company_registration_number);

        return SupplierBlacklistEntry::query()->live()->get()
            ->filter(function (SupplierBlacklistEntry $entry) use ($supplier, $normalised, $digits) {
                if ((int) $entry->supplier_id === (int) $supplier->id) {
                    return true;
                }

                if ($digits !== '' && $entry->registration_number
                    && preg_replace('/\D+/', '', $entry->registration_number) === $digits) {
                    return true;
                }

                return $entry->normalised_name && $entry->normalised_name === $normalised;
            })
            ->map(fn (SupplierBlacklistEntry $entry) => $this->describe($entry, 'entity', $entry->entity_name))
            ->values()
            ->all();
    }

    /**
     * A barred person sitting on this supplier.
     *
     * Matched by hashed identifier first, because names repeat and identifiers do not; by normalised
     * name second, because most records will not have an identifier and a name match is still worth
     * putting in front of somebody.
     */
    private function personMatches(Supplier $supplier): array
    {
        $people = SupplierPerson::query()->where('supplier_id', $supplier->id)->get();

        if ($people->isEmpty()) {
            return [];
        }

        $entries = SupplierBlacklistEntry::query()->live()
            ->where('scope', SupplierBlacklistEntry::SCOPE_PERSON)->get();

        $matches = [];

        foreach ($people as $person) {
            $key = $this->comparator->normalisePersonName($person->name());
            $hash = $person->id_number ? SupplierBlacklistEntry::hashIdentifier((string) $person->id_number) : null;

            foreach ($entries as $entry) {
                $sameIdentifier = $hash && $entry->person_identifier_hash === $hash;
                $sameName = $key !== '' && $this->comparator->normalisePersonName($entry->entity_name) === $key;

                if ($sameIdentifier || $sameName) {
                    $matches[] = $this->describe(
                        $entry,
                        'person',
                        $person->name().($sameIdentifier ? ' (identity number matches)' : ' (name matches)')
                    );
                }
            }
        }

        return $matches;
    }

    /** A barred company anywhere in the ownership chain — the parent behind the new registration. */
    private function chainMatches(Supplier $supplier): array
    {
        $entities = SupplierEntity::query()
            ->where('supplier_id', $supplier->id)
            ->where('is_root', false)
            ->get();

        if ($entities->isEmpty()) {
            return [];
        }

        $entries = SupplierBlacklistEntry::query()->live()->get();
        $matches = [];

        foreach ($entities as $entity) {
            foreach ($entries as $entry) {
                if ($entry->normalised_name && $entry->normalised_name === $entity->normalised_name) {
                    $matches[] = $this->describe($entry, 'ownership_chain', $entity->name);
                }
            }
        }

        return $matches;
    }

    private function describe(SupplierBlacklistEntry $entry, string $kind, string $matched): array
    {
        return [
            'kind' => $kind,
            'matched' => $matched,
            'reason' => $entry->reason,
            'entry_id' => $entry->id,
            'decided_at' => $entry->decided_at?->toDateString(),
        ];
    }
}
