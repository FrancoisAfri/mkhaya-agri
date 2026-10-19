<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalEvent;
use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\AnimalMovement;
use App\Modules\Agri\Models\AnimalWeight;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\LivestockBreed;
use App\Modules\Agri\Models\LivestockCountChange;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Models\LivestockSpecies;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules of the herd and flock register (spec Module C).
 *
 *  - A tag number identifies one animal of a species in the organisation, across all its farms —
 *    an animal moved between farms keeps its tag, so two farms cannot both hand it out.
 *  - Animals leave by an exit (sold, died, culled, slaughtered, missing, transferred); nothing is
 *    deleted, and an exited animal takes no new records.
 *  - A treatment with a withdrawal period stops the animal (or group) being sold or slaughtered
 *    until the period ends.
 *  - A group-tracked head count changes only through a recorded change with a reason, and can
 *    never go below zero.
 */
class Livestock
{
    public function __construct(
        private readonly FarmRegistry $registry,
        private readonly FarmStores $stores,
        private readonly StockLedger $stock,
        private readonly AgriLedger $ledger,
    ) {}

    // ── Groups ───────────────────────────────────────────────────────────────────────────────

    public function createGroup(Farm $farm, array $data, User $actor): LivestockGroup
    {
        $this->registry->assertOperational($farm);
        $taken = LivestockGroup::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)
            ->whereRaw('lower(code) = ?', [mb_strtolower(trim($data['code']))])->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => "This farm already has a group {$data['code']}."]);
        }
        $this->assertFieldOn($farm, $data['field_id'] ?? null);

        $opening = (int) ($data['head_count'] ?? 0);
        $species = LivestockSpecies::query()->findOrFail($data['species_id']);

        return DB::transaction(function () use ($farm, $data, $actor, $opening, $species) {
            // The opening count goes through changeCount like every later change, so it has a reason.
            $group = LivestockGroup::create(['head_count' => 0] + $data + [
                'company_id' => $farm->company_id, 'farm_id' => $farm->id,
                'status' => LivestockGroup::ACTIVE, 'created_by' => $actor->id,
            ]);
            if ($species->tracking === 'group' && $opening > 0) {
                $this->changeCount($group, $opening, 'count_correction', $data['started_on'] ?? today()->toDateString(), $actor, 'Opening count');
            }

            return $group;
        });
    }

    /**
     * A change to a group-tracked head count, with its reason. Sales and slaughter respect the
     * group's withdrawal period; a sale with a value posts to the shared ledger.
     */
    public function changeCount(LivestockGroup $group, int $delta, string $reason, string $date, User $actor,
        ?string $notes = null, ?float $value = null, string $settledBy = 'receivable'): LivestockCountChange
    {
        if (! in_array($reason, LivestockCountChange::REASONS, true)) {
            throw ValidationException::withMessages(['reason' => 'Choose why the count changed.']);
        }
        if ($delta === 0) {
            throw ValidationException::withMessages(['delta' => 'The change cannot be zero.']);
        }
        if (in_array($reason, LivestockCountChange::INCREASES, true) && $delta < 0
            || in_array($reason, LivestockCountChange::DECREASES, true) && $delta > 0) {
            throw ValidationException::withMessages(['delta' => ucfirst(str_replace('_', ' ', $reason)).($delta < 0 ? ' cannot reduce' : ' cannot increase').' the count.']);
        }
        if (in_array($reason, ['sale', 'slaughter'], true)) {
            $this->assertNoWithdrawal(null, $group, $date);
        }

        return DB::transaction(function () use ($group, $delta, $reason, $date, $actor, $notes, $value, $settledBy) {
            $locked = LivestockGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $after = (int) $locked->head_count + $delta;
            if ($after < 0) {
                throw ValidationException::withMessages(['delta' => "The group has {$locked->head_count} head; it cannot lose ".abs($delta).'.']);
            }
            $locked->update(['head_count' => $after]);
            $group->setRawAttributes($locked->getAttributes(), true);

            $change = LivestockCountChange::create([
                'company_id' => $group->company_id, 'farm_id' => $group->farm_id, 'group_id' => $group->id,
                'changed_on' => $date, 'delta' => $delta, 'reason' => $reason, 'count_after' => $after,
                'value' => $value, 'notes' => $notes, 'recorded_by' => $actor->id,
            ]);

            if ($reason === 'sale' && $value && $value > 0) {
                $journal = $this->ledger->sale($group->farm, $value, $date, "Sold ".abs($delta)." head from {$group->code}",
                    AgriLedger::dimensions($group->farm, null, $group, 'livestock_sale'), 'livestock', $settledBy, "agri-count-sale-{$change->uuid}");
                $change->update(['journal_id' => $journal->id]);
            }

            return $change;
        });
    }

    // ── Individual animals ───────────────────────────────────────────────────────────────────

    public function register(Farm $farm, array $data, User $actor): Animal
    {
        $this->registry->assertOperational($farm);
        $species = LivestockSpecies::query()->findOrFail($data['species_id']);
        $this->assertTagFree($species->id, $data['tag_number']);
        if (! empty($data['breed_id']) && ! LivestockBreed::query()->whereKey($data['breed_id'])->where('species_id', $species->id)->exists()) {
            throw ValidationException::withMessages(['breed_id' => "That breed is not a {$species->name} breed."]);
        }
        $this->assertGroupOn($farm, $data['group_id'] ?? null, $species->id);
        $this->assertFieldOn($farm, $data['field_id'] ?? null);
        foreach (['dam_id' => 'female', 'sire_id' => 'male'] as $key => $sex) {
            if (! empty($data[$key])) {
                $parent = Animal::query()->find($data[$key]);
                if (! $parent || (int) $parent->species_id !== $species->id || $parent->sex !== $sex) {
                    throw ValidationException::withMessages([$key => $key === 'dam_id' ? 'The dam must be a female of the same species.' : 'The sire must be a male of the same species.']);
                }
            }
        }
        if (! empty($data['birth_date']) && Carbon::parse($data['birth_date'])->isAfter(today())) {
            throw ValidationException::withMessages(['birth_date' => 'A birth date cannot be in the future.']);
        }

        return DB::transaction(function () use ($farm, $data, $actor) {
            $animal = Animal::create($data + [
                'company_id' => $farm->company_id, 'farm_id' => $farm->id,
                'status' => Animal::ACTIVE, 'created_by' => $actor->id,
            ]);
            if (($data['acquisition'] ?? 'born') === 'born' && ! empty($data['birth_date'])) {
                $this->event($animal, 'birth', $data['birth_date'], $actor, 'Registered at birth');
            }

            return $animal;
        });
    }

    public function move(Animal $animal, ?LivestockGroup $toGroup, ?FarmField $toField, string $date, ?string $reason, User $actor): AnimalMovement
    {
        $this->assertActive($animal);
        if ($toGroup && ((int) $toGroup->farm_id !== (int) $animal->farm_id || (int) $toGroup->species_id !== (int) $animal->species_id)) {
            throw ValidationException::withMessages(['group_id' => 'The group must be on the same farm and of the same species.']);
        }
        if ($toField && (int) $toField->farm_id !== (int) $animal->farm_id) {
            throw ValidationException::withMessages(['field_id' => 'That camp is not on this farm.']);
        }

        return DB::transaction(function () use ($animal, $toGroup, $toField, $date, $reason, $actor) {
            $movement = AnimalMovement::create([
                'company_id' => $animal->company_id, 'farm_id' => $animal->farm_id, 'animal_id' => $animal->id,
                'from_group_id' => $animal->group_id, 'to_group_id' => $toGroup?->id ?? $animal->group_id,
                'from_field_id' => $animal->field_id, 'to_field_id' => $toField?->id ?? $animal->field_id,
                'moved_on' => $date, 'reason' => $reason, 'recorded_by' => $actor->id,
            ]);
            $animal->update(array_filter(['group_id' => $toGroup?->id, 'field_id' => $toField?->id]));

            return $movement;
        });
    }

    /** Sold, died, culled, slaughtered, missing or transferred. A sale with a value posts to the ledger. */
    public function exit(Animal $animal, string $status, string $date, ?string $reason, ?float $value, User $actor, string $settledBy = 'receivable'): Animal
    {
        $this->assertActive($animal);
        if (! in_array($status, Animal::EXITS, true)) {
            throw ValidationException::withMessages(['status' => 'Choose how the animal left.']);
        }
        if (Carbon::parse($date)->isAfter(today())) {
            throw ValidationException::withMessages(['exited_on' => 'An exit cannot be recorded for a future date.']);
        }
        if (in_array($status, ['sold', 'slaughtered'], true)) {
            $this->assertNoWithdrawal($animal, $animal->group, $date);
        }

        return DB::transaction(function () use ($animal, $status, $date, $reason, $value, $actor, $settledBy) {
            $animal->update(['status' => $status, 'exited_on' => $date, 'exit_reason' => $reason, 'exit_value' => $value]);
            $this->event($animal, 'exit', $date, $actor, ucfirst($status).($reason ? ": {$reason}" : ''));

            if ($status === 'sold' && $value && $value > 0) {
                $journal = $this->ledger->sale($animal->farm, $value, $date, "Sold {$animal->tag_number}",
                    AgriLedger::dimensions($animal->farm, null, $animal->group, 'livestock_sale'), 'livestock', $settledBy, "agri-animal-sale-{$animal->uuid}");
                $animal->update(['exit_journal_id' => $journal->id]);
            }

            return $animal;
        });
    }

    // ── Health, weights and events ───────────────────────────────────────────────────────────

    /**
     * A vaccination, treatment or other health event for an animal or a whole group. A product
     * drawn from the farm store is issued through the stock ledger to the group's costs. The
     * withdrawal period, when there is one, runs from the treatment date.
     */
    public function treat(Farm $farm, ?Animal $animal, ?LivestockGroup $group, array $data, User $actor): AnimalHealthRecord
    {
        $this->registry->assertOperational($farm);
        if (! $animal && ! $group) {
            throw ValidationException::withMessages(['animal_id' => 'Choose the animal or group treated.']);
        }
        if ($animal) {
            $this->assertActive($animal);
            $group ??= $animal->group;
        }
        if (Carbon::parse($data['treated_on'])->isAfter(today())) {
            throw ValidationException::withMessages(['treated_on' => 'A treatment cannot be recorded before it happens. Use the next-due date to plan one.']);
        }
        $withdrawalUntil = ! empty($data['withdrawal_days'])
            ? Carbon::parse($data['treated_on'])->addDays((int) $data['withdrawal_days'])->toDateString() : null;

        try {
            return DB::transaction(function () use ($farm, $animal, $group, $data, $actor, $withdrawalUntil) {
                $record = AnimalHealthRecord::create([
                    'company_id' => $farm->company_id, 'farm_id' => $farm->id,
                    'animal_id' => $animal?->id, 'group_id' => $group?->id,
                    'event_type' => $data['event_type'], 'treated_on' => $data['treated_on'],
                    'condition' => $data['condition'] ?? null, 'product_name' => $data['product_name'] ?? null,
                    'stock_item_id' => $data['stock_item_id'] ?? null, 'quantity' => $data['quantity'] ?? null,
                    'dose' => $data['dose'] ?? null, 'head_count' => $animal ? 1 : max(1, (int) ($data['head_count'] ?? $group?->currentHeadCount() ?? 1)),
                    'withdrawal_days' => $data['withdrawal_days'] ?? null, 'withdrawal_until' => $withdrawalUntil,
                    'next_due_on' => $data['next_due_on'] ?? null, 'vet_name' => $data['vet_name'] ?? null,
                    'cost' => $data['cost'] ?? null, 'notes' => $data['notes'] ?? null, 'recorded_by' => $actor->id,
                ]);

                if (! empty($data['stock_item_id']) && (float) ($data['quantity'] ?? 0) > 0) {
                    $item = FinStockItem::query()->findOrFail($data['stock_item_id']);
                    $movement = $this->stock->issueTo($item, (float) $data['quantity'], $this->stores->storeFor($farm), [
                        'source_type' => 'agri_health', 'source_id' => $record->id,
                        'idempotency_key' => "agri-health-{$record->uuid}", 'date' => $data['treated_on'],
                        'reference' => "{$farm->code} ".($animal?->tag_number ?? $group?->code)." {$data['event_type']}",
                        'expense_account' => config('agri.accounts.animal_health'), 'reason_code' => 'AGRI_USE',
                        'dimensions' => AgriLedger::dimensions($farm, null, $group, 'animal_health'),
                    ]);
                    $record->update([
                        'stock_movement_id' => $movement->id,
                        'product_name' => $record->product_name ?: $item->name,
                        'cost' => $record->cost ?? abs((float) $movement->value),
                    ]);
                }

                return $record;
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }
    }

    public function weigh(Farm $farm, ?Animal $animal, ?LivestockGroup $group, array $data, User $actor): AnimalWeight
    {
        if ($animal) {
            $this->assertActive($animal);
        }
        if ((float) $data['weight_kg'] <= 0) {
            throw ValidationException::withMessages(['weight_kg' => 'Weight must be more than zero.']);
        }

        return DB::transaction(function () use ($farm, $animal, $group, $data, $actor) {
            $w = AnimalWeight::create([
                'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'animal_id' => $animal?->id,
                'group_id' => $group?->id ?? $animal?->group_id, 'weighed_on' => $data['weighed_on'],
                'weight_kg' => $data['weight_kg'], 'head_count' => $animal ? 1 : max(1, (int) ($data['head_count'] ?? 1)),
                'condition_score' => $data['condition_score'] ?? null, 'recorded_by' => $actor->id,
            ]);
            $animal?->update(['last_weight_kg' => $data['weight_kg']]);

            return $w;
        });
    }

    public function event(Animal $animal, string $type, string $date, User $actor, ?string $outcome = null, array $details = []): AnimalEvent
    {
        if ($type !== 'exit') {
            $this->assertActive($animal);
        }

        return AnimalEvent::create([
            'company_id' => $animal->company_id, 'farm_id' => $animal->farm_id, 'animal_id' => $animal->id,
            'event_type' => $type, 'occurred_on' => $date, 'outcome' => $outcome, 'details' => $details ?: null,
            'recorded_by' => $actor->id,
        ]);
    }

    /** The latest withdrawal date still running on an animal or its group, if any. */
    public function withdrawalUntil(?Animal $animal, ?LivestockGroup $group, ?string $onDate = null): ?Carbon
    {
        $on = Carbon::parse($onDate ?? today());
        $until = AnimalHealthRecord::query()
            ->where(fn ($q) => $q
                ->when($animal, fn ($w) => $w->orWhere('animal_id', $animal->id))
                ->when($group, fn ($w) => $w->orWhere(fn ($g) => $g->where('group_id', $group->id)->whereNull('animal_id'))))
            ->whereDate('withdrawal_until', '>', $on)
            ->max('withdrawal_until');

        return $until ? Carbon::parse($until) : null;
    }

    private function assertNoWithdrawal(?Animal $animal, ?LivestockGroup $group, string $date): void
    {
        $until = $this->withdrawalUntil($animal, $group, $date);
        if ($until) {
            throw ValidationException::withMessages(['status' => 'Still inside a treatment withdrawal period until '.$until->toDateString().
                '. It may not be sold or slaughtered before then.']);
        }
    }

    private function assertActive(Animal $animal): void
    {
        if (! $animal->isActive()) {
            throw ValidationException::withMessages(['animal_id' => "{$animal->tag_number} has left the herd ({$animal->status}) and takes no new records."]);
        }
    }

    private function assertTagFree(int $speciesId, string $tag): void
    {
        $taken = Animal::withoutGlobalScope('farm_access')->where('species_id', $speciesId)
            ->whereRaw('lower(tag_number) = ?', [mb_strtolower(trim($tag))])->exists();
        if ($taken) {
            throw ValidationException::withMessages(['tag_number' => "Tag {$tag} is already used in this organisation."]);
        }
    }

    private function assertGroupOn(Farm $farm, $groupId, int $speciesId): void
    {
        if (! $groupId) {
            return;
        }
        $group = LivestockGroup::query()->find($groupId);
        if (! $group || (int) $group->farm_id !== (int) $farm->id || (int) $group->species_id !== $speciesId) {
            throw ValidationException::withMessages(['group_id' => 'The group must be on this farm and of the same species.']);
        }
    }

    private function assertFieldOn(Farm $farm, $fieldId): void
    {
        if ($fieldId && ! FarmField::query()->whereKey($fieldId)->where('farm_id', $farm->id)->exists()) {
            throw ValidationException::withMessages(['field_id' => 'That camp is not on this farm.']);
        }
    }
}
