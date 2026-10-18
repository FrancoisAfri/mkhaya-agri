<?php

namespace App\Modules\HR\Offboarding\Services;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\Assets;
use App\Modules\HR\Offboarding\Models\OffboardingAssetReturn;
use App\Modules\HR\Offboarding\Models\OffboardingPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The terminal step of offboarding. Before this existed, "completing" a plan only
 * flipped its status — the departed employee kept their login, roles, and assets
 * indefinitely. finalize() performs the actual termination, atomically and
 * idempotently (only the active→completed transition runs it):
 *
 *  1. HRPerson deactivated (status 0, date_left stamped)
 *  2. Login revoked: user status 0, remember token rotated, roles removed,
 *     DB sessions purged
 *  3. Assigned assets recorded as returns on the plan and unassigned
 *  4. Plan marked completed + audit note (final leave payout flagged for payroll)
 */
class OffboardingFinalizeService
{
    public function finalize(OffboardingPlan $plan, int $performedBy): bool
    {
        return DB::transaction(function () use ($plan, $performedBy) {
            // Idempotency guard: only ever finalize an active plan (locked).
            $plan = OffboardingPlan::whereKey($plan->id)->lockForUpdate()->first();
            if (! $plan || $plan->status->value !== 'active') {
                return false;
            }

            $person = $plan->hrPerson()->lockForUpdate()->first();

            // 1. Deactivate the employee record.
            if ($person) {
                $lastDay = $plan->last_working_day ? strtotime((string) $plan->last_working_day) : time();
                $person->update(['status' => 0, 'date_left' => $lastDay]);
            }

            // 2. Revoke access: block login, rotate remember token, drop roles,
            //    and purge any live database sessions.
            $user = $person?->user_id ? User::find($person->user_id) : null;
            if ($user) {
                $user->status = 0;
                $user->remember_token = Str::random(60);
                $user->save();
                $user->syncRoles([]);

                if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
            }

            // 3. Reclaim assets: record a return on the plan and unassign each one.
            if ($person?->user_id) {
                Assets::where('user_id', $person->user_id)->get()->each(function ($asset) use ($plan, $performedBy) {
                    OffboardingAssetReturn::firstOrCreate(
                        ['plan_id' => $plan->id, 'asset_id' => $asset->id],
                        ['status' => 'returned', 'returned_at' => now(), 'received_by' => $performedBy, 'notes' => 'Auto-reclaimed at offboarding finalisation.'],
                    );
                    $asset->update(['user_id' => null]);
                });
            }

            // 4. Complete the plan; flag final leave payout for payroll follow-up.
            $plan->update([
                'status' => 'completed',
                'notes'  => trim(($plan->notes ? $plan->notes . "\n" : '')
                    . '[' . now()->format('Y-m-d H:i') . "] Finalised: access revoked, assets reclaimed. Final leave payout to be processed in Payroll."),
            ]);

            return true;
        });
    }
}
