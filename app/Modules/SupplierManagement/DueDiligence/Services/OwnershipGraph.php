<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierEntity;
use App\Modules\SupplierManagement\Models\SupplierOwnership;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use Illuminate\Support\Collection;

/**
 * Walks a supplier's ownership chain to the natural persons at the end of it.
 *
 * The point of the exercise is that an immediate shareholder is rarely the answer. ABC Holdings is
 * 60% owned by a person and 40% by XYZ Investments; XYZ is 75% owned by somebody else. Storing the
 * immediate shareholders tells you about XYZ, which is a company, which cannot be sanctioned,
 * cannot be a PEP and cannot take a bribe. The person holding 30% of your supplier through that
 * chain is who you are actually trading with.
 *
 * Effective holding is the product along the path — 40% × 75% = 30% — and a person reachable by more
 * than one path holds the sum of them, which is exactly how control is split up to stay under a
 * disclosure threshold.
 *
 * Three things this deliberately reports rather than hides:
 *
 *  - Unallocated ownership. If the declared stakes in a company come to 70%, the missing 30% belongs
 *    to somebody nobody has named. That is a finding, not a rounding error.
 *  - Circular ownership. A owns B owns A is a real structure and a real red flag, and it will hang a
 *    naive walk. Visited-path tracking both survives it and names it.
 *  - Chains that end in a company. If the walk reaches an entity with no declared holders, the
 *    ultimate owner is unknown — which must not look like "no ultimate owners exist".
 */
class OwnershipGraph
{
    /** Below this effective percentage a person is a holder but not reported as a beneficial owner. */
    public const UBO_THRESHOLD = 5.0;

    /** Guards against a structure deep enough to be an attack rather than a company. */
    private const MAX_DEPTH = 12;

    public function __construct(private readonly FieldComparator $comparator) {}

    /**
     * The whole picture for one supplier.
     *
     * @return array{
     *   nodes: array<int, array<string,mixed>>,
     *   edges: array<int, array<string,mixed>>,
     *   ubos: array<int, array<string,mixed>>,
     *   unallocated: array<int, array<string,mixed>>,
     *   cycles: array<int, string>,
     *   dead_ends: array<int, string>,
     *   declared_total: float|null
     * }
     */
    public function forSupplier(Supplier $supplier, float $threshold = self::UBO_THRESHOLD): array
    {
        $root = SupplierEntity::rootFor($supplier);
        $entities = SupplierEntity::query()->where('supplier_id', $supplier->id)->get()->keyBy('id');
        $persons = SupplierPerson::query()->where('supplier_id', $supplier->id)->get()->keyBy('id');
        $edges = SupplierOwnership::query()->forSupplier($supplier->id)->current()->get();

        $bySubject = $edges->groupBy('subject_entity_id');

        $walk = $this->walk($root->id, 100.0, $bySubject, $entities, [], 0);

        return [
            'root' => $this->entityNode($root),
            'nodes' => $this->nodes($entities, $persons, $edges),
            'edges' => $this->edgeList($edges, $entities, $persons),
            'ubos' => $this->ultimateOwners($walk['owners'], $persons, $threshold),
            'unallocated' => $this->unallocated($bySubject, $entities),
            'cycles' => $walk['cycles'],
            'dead_ends' => $walk['deadEnds'],
            'declared_total' => $this->declaredTotal($bySubject, $root->id),
        ];
    }

    /** Just the beneficial owners, for callers that do not need the drawing. */
    public function ultimateBeneficialOwners(Supplier $supplier, float $threshold = self::UBO_THRESHOLD): array
    {
        return $this->forSupplier($supplier, $threshold)['ubos'];
    }

    /**
     * Depth-first from an entity through everyone who holds a stake in it.
     *
     * `$path` carries the entity ids already visited on THIS branch, which is what makes a cycle
     * detectable without a global visited set — a diamond (two paths to the same company) is legal
     * and common, and must not be mistaken for a loop.
     *
     * @return array{owners: array<string, array{kind:string,id:int,effective:float,paths:int}>, cycles: array<int,string>, deadEnds: array<int,string>}
     */
    private function walk(int $entityId, float $carried, Collection $bySubject, Collection $entities, array $path, int $depth): array
    {
        $owners = [];
        $cycles = [];
        $deadEnds = [];

        if ($depth > self::MAX_DEPTH) {
            $deadEnds[] = ($entities[$entityId]->name ?? "entity {$entityId}").' — chain too deep to resolve';

            return compact('owners', 'cycles', 'deadEnds');
        }

        if (in_array($entityId, $path, true)) {
            $cycles[] = ($entities[$entityId]->name ?? "entity {$entityId}").' is owned, directly or indirectly, by itself';

            return compact('owners', 'cycles', 'deadEnds');
        }

        $holders = $bySubject->get($entityId) ?? collect();

        if ($holders->isEmpty()) {
            // Only a dead end if we got here through somebody: the root having no holders is simply
            // an ownership structure nobody has captured yet.
            if ($path !== []) {
                $deadEnds[] = ($entities[$entityId]->name ?? "entity {$entityId}").' has no declared owners, so the chain stops here';
            }

            return compact('owners', 'cycles', 'deadEnds');
        }

        foreach ($holders as $edge) {
            // A stake of unstated size cannot be multiplied out. It is carried as zero effective and
            // surfaced separately, rather than guessed at.
            $share = $edge->percentage === null ? 0.0 : (float) $edge->percentage;
            $effective = $carried * ($share / 100);

            if ($edge->isPersonHolder()) {
                $key = 'person:'.$edge->holder_id;
                $owners[$key] ??= ['kind' => 'person', 'id' => (int) $edge->holder_id, 'effective' => 0.0, 'paths' => 0, 'direct' => false];
                // Reached by two routes: control split across paths is exactly how a holding is kept
                // under a disclosure threshold, so the routes are summed.
                $owners[$key]['effective'] += $effective;
                $owners[$key]['paths']++;
                $owners[$key]['direct'] = $owners[$key]['direct'] || $path === [];

                continue;
            }

            $below = $this->walk((int) $edge->holder_id, $effective, $bySubject, $entities, [...$path, $entityId], $depth + 1);

            foreach ($below['owners'] as $key => $owner) {
                $owners[$key] ??= ['kind' => $owner['kind'], 'id' => $owner['id'], 'effective' => 0.0, 'paths' => 0, 'direct' => false];
                $owners[$key]['effective'] += $owner['effective'];
                $owners[$key]['paths'] += $owner['paths'];
            }

            $cycles = array_merge($cycles, $below['cycles']);
            $deadEnds = array_merge($deadEnds, $below['deadEnds']);
        }

        return ['owners' => $owners, 'cycles' => array_values(array_unique($cycles)), 'deadEnds' => array_values(array_unique($deadEnds))];
    }

    /** @return array<int, array<string,mixed>> */
    private function ultimateOwners(array $owners, Collection $persons, float $threshold): array
    {
        return collect($owners)
            ->map(function (array $owner) use ($persons) {
                $person = $persons->get($owner['id']);

                return [
                    'person_id' => $owner['id'],
                    'name' => $person?->name() ?? 'Unknown person',
                    'role' => $person?->role,
                    'nationality' => $person?->nationality,
                    'has_identifier' => (bool) $person?->hasIdentifier(),
                    'effective_percentage' => round($owner['effective'], 3),
                    'paths' => $owner['paths'],
                    'direct' => $owner['direct'] ?? false,
                ];
            })
            ->filter(fn (array $owner) => $owner['effective_percentage'] >= $threshold)
            ->sortByDesc('effective_percentage')
            ->values()
            ->all();
    }

    /**
     * Companies whose declared stakes do not add up to 100%.
     *
     * The missing share belongs to somebody nobody has named, which is the single most useful
     * ownership finding there is — and the one a system that only stores what it was told will never
     * produce on its own.
     */
    private function unallocated(Collection $bySubject, Collection $entities): array
    {
        $gaps = [];

        foreach ($entities as $entity) {
            $holders = $bySubject->get($entity->id) ?? collect();

            if ($holders->isEmpty()) {
                continue;
            }

            $declared = (float) $holders->sum(fn ($edge) => (float) ($edge->percentage ?? 0));
            $unstated = $holders->whereNull('percentage')->count();

            // Half a percent of slack: real shareholdings are written as 33.33% three times.
            if ($declared >= 99.5 && $unstated === 0) {
                continue;
            }

            $gaps[] = [
                'entity_id' => $entity->id,
                'entity' => $entity->name,
                'declared' => round($declared, 3),
                'missing' => round(max(0, 100 - $declared), 3),
                'stakes_without_a_percentage' => $unstated,
                'over_allocated' => $declared > 100.5,
            ];
        }

        return $gaps;
    }

    private function declaredTotal(Collection $bySubject, int $rootId): ?float
    {
        $holders = $bySubject->get($rootId);

        return $holders && $holders->isNotEmpty()
            ? round((float) $holders->sum(fn ($e) => (float) ($e->percentage ?? 0)), 3)
            : null;
    }

    /** @return array<int, array<string,mixed>> */
    private function nodes(Collection $entities, Collection $persons, Collection $edges): array
    {
        $usedPersonIds = $edges->where('holder_kind', SupplierOwnership::KIND_PERSON)->pluck('holder_id')->unique();

        $nodes = $entities->map(fn (SupplierEntity $e) => $this->entityNode($e))->values()->all();

        foreach ($persons as $person) {
            // Only people who actually appear in the graph. A director with no shareholding belongs on
            // the people list, not as a floating node in an ownership drawing.
            if (! $usedPersonIds->contains($person->id)) {
                continue;
            }

            $nodes[] = [
                'key' => 'person:'.$person->id,
                'kind' => 'person',
                'id' => $person->id,
                'label' => $person->name(),
                'sub' => $person->position ?: ucfirst(str_replace('_', ' ', (string) $person->role)),
                'is_root' => false,
                'is_supplier' => false,
            ];
        }

        return $nodes;
    }

    private function entityNode(SupplierEntity $entity): array
    {
        return [
            'key' => 'entity:'.$entity->id,
            'kind' => 'entity',
            'id' => $entity->id,
            'label' => $entity->name,
            'sub' => $entity->registration_number ?: $entity->entity_type,
            'is_root' => (bool) $entity->is_root,
            'is_supplier' => $entity->supplier_ref_id !== null,
            'supplier_ref_id' => $entity->supplier_ref_id,
        ];
    }

    /** @return array<int, array<string,mixed>> */
    private function edgeList(Collection $edges, Collection $entities, Collection $persons): array
    {
        return $edges->map(function (SupplierOwnership $edge) use ($entities, $persons) {
            $holder = $edge->isPersonHolder() ? $persons->get($edge->holder_id) : $entities->get($edge->holder_id);
            $subject = $entities->get($edge->subject_entity_id);

            return [
                'id' => $edge->id,
                'holder_key' => $edge->holder_kind.':'.$edge->holder_id,
                'holder' => $edge->isPersonHolder() ? ($holder?->name() ?? '—') : ($holder->name ?? '—'),
                'holder_kind' => $edge->holder_kind,
                'subject_key' => 'entity:'.$edge->subject_entity_id,
                'subject' => $subject->name ?? '—',
                'percentage' => $edge->percentage === null ? null : (float) $edge->percentage,
                'ownership_type' => $edge->ownership_type,
                'as_at' => $edge->as_at?->toDateString(),
                'source' => $edge->source,
            ];
        })->values()->all();
    }

    /**
     * Other suppliers on this tenant connected to this one through a shared person or company.
     *
     * A director who sits on two of your suppliers is not automatically wrong — it is common, and
     * frequently innocent. It is also how a competitive tender turns out to have been bid by one
     * group under three names, which is why it has to be visible rather than discoverable only by
     * somebody who already suspected it.
     */
    public function relatedSuppliers(Supplier $supplier): array
    {
        $names = SupplierPerson::query()
            ->where('supplier_id', $supplier->id)
            ->whereNotNull('normalised_name')
            ->pluck('normalised_name')
            ->filter()
            ->unique();

        $related = [];

        if ($names->isNotEmpty()) {
            SupplierPerson::query()
                ->whereIn('normalised_name', $names->all())
                ->where('supplier_id', '!=', $supplier->id)
                ->with('supplier:id,company_name')
                ->get()
                ->each(function (SupplierPerson $person) use (&$related) {
                    $key = 'supplier:'.$person->supplier_id;
                    $related[$key] ??= [
                        'supplier_id' => $person->supplier_id,
                        'supplier' => $person->supplier->company_name ?? '—',
                        'via' => [],
                    ];
                    $related[$key]['via'][] = ['kind' => 'person', 'name' => $person->name(), 'role' => $person->role];
                });
        }

        // The same holding company appearing in two graphs is the other way this shows up.
        $entityNames = SupplierEntity::query()
            ->where('supplier_id', $supplier->id)
            ->where('is_root', false)
            ->whereNotNull('normalised_name')
            ->pluck('normalised_name')
            ->filter()
            ->unique();

        if ($entityNames->isNotEmpty()) {
            SupplierEntity::query()
                ->whereIn('normalised_name', $entityNames->all())
                ->where('supplier_id', '!=', $supplier->id)
                ->with('supplier:id,company_name')
                ->get()
                ->each(function (SupplierEntity $entity) use (&$related) {
                    $key = 'supplier:'.$entity->supplier_id;
                    $related[$key] ??= [
                        'supplier_id' => $entity->supplier_id,
                        'supplier' => $entity->supplier->company_name ?? '—',
                        'via' => [],
                    ];
                    $related[$key]['via'][] = ['kind' => 'entity', 'name' => $entity->name, 'role' => 'shared owner'];
                });
        }

        return array_values($related);
    }
}
