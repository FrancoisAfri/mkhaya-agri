<?php

namespace App\Modules\Dashboard\Services;

use App\Core\Ribbon\Models\Ribbon;
use App\Core\User\Models\User;
use App\Modules\Dashboard\Models\QuickAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuickAccessService
{
    /**
     * Get all accessible ribbons for a user
     * A ribbon is accessible if:
     * 1. User has module access AND module ribbon access_level <= user access_level
     * 2. OR user has direct ribbon access
     */
    public function getAccessibleRibbonsForUser(int $userId): Collection
    {
        $user = User::findOrFail($userId);

        return Ribbon::where('active', 1)
            ->whereIn('id', function ($query) use ($userId) {
                $query->select('security_module_ribbons.id')
                    ->from('security_module_ribbons')
                    ->join('security_module_access', function ($join) use ($userId) {
                        $join->on('security_module_ribbons.module_id', '=', 'security_module_access.module_id');
                        $join->where('security_module_access.user_id', '=', $userId);
                        // Ribbon access level must be <= user access level
                        $join->whereRaw('security_module_ribbons.access_level <= security_module_access.access_level');
                    })
                    ->union(
                        Ribbon::select('security_module_ribbons.id')
                            ->from('security_module_ribbons')
                            ->join('security_ribbons_access', function ($join) use ($userId) {
                                $join->on('security_module_ribbons.id', '=', 'security_ribbons_access.ribbon_id');
                                $join->where('security_ribbons_access.user_id', '=', $userId);
                            })
                    );
            })
            ->with('module')
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * Get all QuickAccess items (admin management)
     */
    public function getAll(): Collection
    {
        return QuickAccess::with('ribbons', 'ribbons.module', 'users')->get();
    }

    /**
     * Get QuickAccess items selected by user for their quick links bar
     */
    // public function getForUser(int $userId): Collection
    // {
    //     return Ribbon::whereHas('users', function ($query) use ($userId) {
    //         $query->where('user_id', $userId);
    //     })
    //         ->whereHas('ribbons', function ($query) {
    //             $query->where('active', 1);
    //         })
    //         ->with('ribbons', 'ribbons.module')
    //         ->get();
    // }

public function getForUser(int $userId): Collection
{
    return User::findOrFail($userId)
        ->ribbons()
        ->where('active', 1)
        ->with('module')
        ->get();
}


    public function create(array $data): QuickAccess
    {
        return DB::transaction(function () use ($data) {
            $ribbonId = $data['ribbon_id'];

            // Verify ribbon exists and is accessible
            $ribbon = Ribbon::findOrFail($ribbonId);

            // Check if quick access already exists for this ribbon
            $existing = QuickAccess::where('ribbon_id', $ribbonId)->first();
            if ($existing) {
                throw new \Exception("Quick access already exists for this ribbon");
            }

            $quickAccess = QuickAccess::create([
                'ribbon_id' => $ribbonId,
            ]);

            Log::info('QuickAccess created', [
                'id'        => $quickAccess->id,
                'ribbon_id' => $quickAccess->ribbon_id,
                'ribbon'    => $ribbon->ribbon_name,
            ]);

            return $quickAccess->load('ribbon', 'ribbon.module');
        });
    }

    /**
     * Update QuickAccess (essentially just updating the ribbon_id if needed)
     */
    public function update(int $id, array $data): QuickAccess
    {
        return DB::transaction(function () use ($id, $data) {
            $quickAccess = QuickAccess::findOrFail($id);

            if (isset($data['ribbon_id']) && $data['ribbon_id'] !== $quickAccess->ribbon_id) {
                $existing = QuickAccess::where('ribbon_id', $data['ribbon_id'])->first();
                if ($existing) {
                    throw new \Exception("Quick access already exists for this ribbon");
                }

                $ribbon = Ribbon::findOrFail($data['ribbon_id']);
                $quickAccess->update(['ribbon_id' => $data['ribbon_id']]);

                Log::info('QuickAccess updated', [
                    'id'        => $quickAccess->id,
                    'ribbon_id' => $data['ribbon_id'],
                    'ribbon'    => $ribbon->ribbon_name,
                ]);
            }

            return $quickAccess->load('ribbon', 'ribbon.module');
        });
    }

    /**
     * Delete a QuickAccess
     */
    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $quickAccess = QuickAccess::findOrFail($id);
            $ribbon = $quickAccess->ribbon;

            // Detach all users
            $quickAccess->users()->detach();

            Log::info('QuickAccess deleted', [
                'id'        => $quickAccess->id,
                'ribbon_id' => $quickAccess->ribbon_id,
                'ribbon'    => $ribbon?->ribbon_name,
            ]);

            return $quickAccess->delete();
        });
    }

    /**
     * Add QuickAccess items to user's selection (can add multiple)
     */
    public function addToUser(int $userId, int|array $ribbonIds, array $names = []): void
    {
        $ids = (array) $ribbonIds;
        $user = User::findOrFail($userId);

        $existing = $user->ribbons()->whereIn('ribbon_id', $ids)->pluck('ribbon_id')->toArray();
        $toAttach = array_diff($ids, $existing);

        $attachData = [];
        foreach ($toAttach as $id) {
            $attachData[$id] = ['name' => $names[$id] ?? null];
        }

        if (!empty($attachData)) {
            $user->ribbons()->attach($attachData);

            Log::info('QuickAccess added to user', [
                'user_id' => $userId,
                'ids'     => array_keys($attachData),
            ]);
        }

        foreach ($existing as $id) {
            if (array_key_exists($id, $names)) {
                $user->ribbons()->updateExistingPivot($id, ['name' => $names[$id]]);

                Log::info('QuickAccess label updated for user', [
                    'user_id'   => $userId,
                    'ribbon_id' => $id,
                    'name'      => $names[$id],
                ]);
            }
        }
    }

    /**
     * Remove QuickAccess items from user's selection
     */
    public function removeFromUser(int $userId, int|array $ribbonIds): void
    {
        $ids = (array) $ribbonIds;
        $user = User::findOrFail($userId);

        $user->ribbons()->detach($ids);

        Log::info('QuickAccess removed from user', [
            'user_id' => $userId,
            'ids'     => $ids,
        ]);
    }
}
