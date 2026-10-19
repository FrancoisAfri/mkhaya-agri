<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The business rules of the farm hierarchy (spec §3–4), in one place so the web screens, imports
 * and later the API all apply the same ones.
 */
class FarmRegistry
{
    /**
     * Tables whose rows give a farm a history it cannot lose. A farm referenced by any of them is
     * archived, never deleted (FR-FARM-007, §4.3). Later modules add their own tables here.
     */
    public const HISTORY_TABLES = [
        'farm_fields', 'farm_locations',
        'crop_plans', 'field_activities', 'crop_observations', 'harvest_records',
        'livestock_groups', 'animals', 'animal_health_records',
        'farm_budget_lines', 'farm_transactions', 'farm_machines', 'farm_tasks', 'farm_documents',
    ];

    public function __construct(private FarmAccess $access) {}

    public function createFarm(array $data, User $actor): Farm
    {
        $this->assertCodeFree($data['code']);

        return DB::transaction(function () use ($data, $actor) {
            $farm = Farm::create($data + [
                'status' => Farm::ACTIVE,
                'created_by' => $actor->id,
            ]);

            // A person confined to the farms they are given would otherwise create a farm and then
            // be unable to open it. They become its manager; everybody unrestricted sees it anyway.
            if (! $this->access->isUnrestricted($actor)) {
                FarmMembership::create([
                    'company_id' => $farm->company_id,
                    'farm_id' => $farm->id,
                    'user_id' => $actor->id,
                    'role' => 'manager',
                    'status' => FarmMembership::ACTIVE,
                    'granted_by' => $actor->id,
                ]);
            }

            return $farm;
        });
    }

    public function updateFarm(Farm $farm, array $data): Farm
    {
        if (isset($data['code'])) {
            $this->assertCodeFree($data['code'], $farm->id);
        }
        $farm->update($data);

        return $farm;
    }

    public function setStatus(Farm $farm, string $status): Farm
    {
        $farm->update([
            'status' => $status,
            'archived_at' => $status === Farm::ARCHIVED ? now() : null,
        ]);

        return $farm;
    }

    /** True when the farm has records that must outlive it (fields, locations, and later crops, stock, livestock…). */
    public function hasHistory(Farm $farm): bool
    {
        foreach (self::HISTORY_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('farm_id', $farm->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function deleteFarm(Farm $farm): void
    {
        if ($this->hasHistory($farm)) {
            throw ValidationException::withMessages([
                'farm' => 'This farm has fields, locations or other records, so it cannot be deleted. Archive it instead: it leaves day-to-day work but its history stays.',
            ]);
        }

        DB::transaction(function () use ($farm) {
            FarmMembership::where('farm_id', $farm->id)->delete();
            $farm->delete();
        });
    }

    public function createField(Farm $farm, array $data, User $actor): FarmField
    {
        $this->assertOperational($farm);
        $this->assertFieldCodeFree($farm, $data['code']);

        return FarmField::create($data + [
            'company_id' => $farm->company_id,
            'farm_id' => $farm->id,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
    }

    public function updateField(FarmField $field, array $data): FarmField
    {
        if (isset($data['code'])) {
            $this->assertFieldCodeFree($field->farm, $data['code'], $field->id);
        }
        $field->update($data);

        return $field;
    }

    /**
     * Give a person access to a farm. The actor may only hand out access to a farm they can reach
     * themselves (spec 4.3), and only to somebody in the same organisation.
     */
    public function grant(Farm $farm, User $member, string $role, User $actor): FarmMembership
    {
        if (! $actor->can(RoleCatalog::MANAGE_ACCESS) || ! $this->access->canReach($actor, $farm->id)) {
            throw ValidationException::withMessages(['user_id' => 'You cannot give access to a farm outside your own authority.']);
        }
        if ((int) $member->company_id !== (int) $farm->company_id) {
            throw ValidationException::withMessages(['user_id' => 'That person is not part of this organisation.']);
        }

        $membership = FarmMembership::firstOrNew(['farm_id' => $farm->id, 'user_id' => $member->id]);
        $membership->fill([
            'company_id' => $farm->company_id,
            'role' => $role,
            'status' => FarmMembership::ACTIVE,
            'granted_by' => $actor->id,
            'suspended_at' => null,
            'suspended_by' => null,
        ])->save();

        return $membership;
    }

    public function suspend(FarmMembership $membership, User $actor): FarmMembership
    {
        $membership->update([
            'status' => FarmMembership::SUSPENDED,
            'suspended_at' => now(),
            'suspended_by' => $actor->id,
        ]);

        return $membership;
    }

    public function assertOperational(Farm $farm): void
    {
        if (! $farm->isOperational()) {
            throw ValidationException::withMessages([
                'farm' => 'This farm is '.$farm->status.'. Reactivate it before adding new work to it.',
            ]);
        }
    }

    private function assertCodeFree(string $code, ?int $exceptId = null): void
    {
        // Farm::query() is confined to reachable farms; a code clash with a farm the user cannot
        // see is still a clash, so check the whole organisation.
        $taken = Farm::withoutGlobalScope('farm_access')
            ->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => 'Another farm in this organisation already uses the code "'.$code.'".']);
        }
    }

    private function assertFieldCodeFree(Farm $farm, string $code, ?int $exceptId = null): void
    {
        $taken = FarmField::withoutGlobalScope('farm_access')
            ->where('farm_id', $farm->id)
            ->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => 'This farm already has a field with the code "'.$code.'".']);
        }
    }
}
