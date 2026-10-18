<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Emergency override (WMS-201): the only way round segregation of duties.
 *
 * A holder of `wms.override` opens a window of at most four hours, for named kinds of request, with
 * a reason. Inside it they may decide their own request; each such decision records the override on
 * its approval step and is written to the audit ledger. Opening one alerts the warehouse managers,
 * and the alert stays open until somebody other than the user reviews it.
 */
class WarehouseOverrides
{
    public const MAX_MINUTES = 240;

    public function open(int $companyId, User $user, string $reason, array $types, int $minutes): object
    {
        if (! $user->can(RoleCatalog::EMERGENCY_OVERRIDE)) {
            throw new RuntimeException('You do not hold the emergency override.');
        }
        if (mb_strlen(trim($reason)) < 20) {
            throw new RuntimeException('Say what the emergency is, in a sentence somebody reviewing it later will understand.');
        }
        $types = array_values(array_intersect($types, array_keys(WarehouseApprovals::SUBJECTS)));
        if ($types === []) {
            throw new RuntimeException('Name the kind of request the override is for.');
        }
        if ($minutes < 5 || $minutes > self::MAX_MINUTES) {
            throw new RuntimeException('An override lasts between 5 minutes and 4 hours.');
        }
        $id = DB::table('wms_overrides')->insertGetId(['company_id' => $companyId, 'user_id' => $user->id, 'reason' => $reason, 'subject_types' => json_encode($types),
            'starts_at' => now(), 'expires_at' => now()->addMinutes($minutes), 'created_at' => now(), 'updated_at' => now()]);
        app(WarehouseAudit::class)->record($companyId, 'override.opened', 'OVERRIDE', (string) $id, "Emergency override opened for ".implode(', ', $types).": {$reason}", null, ['minutes' => $minutes], $user);
        $this->alert($companyId);

        return DB::table('wms_overrides')->where('id', $id)->first();
    }

    public function active(User $user, string $type): ?object
    {
        if (! $user->can(RoleCatalog::EMERGENCY_OVERRIDE)) {
            return null;
        }

        return DB::table('wms_overrides')->where('user_id', $user->id)->whereNull('ended_at')->where('starts_at', '<=', now())->where('expires_at', '>', now())
            ->get()->first(fn ($o) => in_array($type, json_decode($o->subject_types, true), true));
    }

    public function used(object $override, string $type, string $ref): void
    {
        DB::table('wms_overrides')->where('id', $override->id)->increment('uses');
        app(WarehouseAudit::class)->record((int) $override->company_id, 'override.used', $type, $ref, "Own request decided under emergency override #{$override->id}", null, null);
    }

    public function end(object $override, User $user): void
    {
        if ((int) $override->user_id !== (int) $user->id) {
            throw new RuntimeException('Only the person who opened an override can close it early.');
        }
        DB::table('wms_overrides')->where('id', $override->id)->update(['ended_at' => now(), 'updated_at' => now()]);
    }

    public function review(object $override, User $reviewer, string $note): void
    {
        if ((int) $override->user_id === (int) $reviewer->id) {
            throw new RuntimeException('An override is reviewed by somebody other than the person who used it.');
        }
        if (! $reviewer->can(RoleCatalog::MANAGE_WAREHOUSES)) {
            throw new RuntimeException('Reviewing overrides needs the warehouse manager permission.');
        }
        if (mb_strlen(trim($note)) < 10) {
            throw new RuntimeException('Record what the review found.');
        }
        DB::table('wms_overrides')->where('id', $override->id)->update(['reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note,
            'ended_at' => DB::raw('COALESCE(ended_at, now())'), 'updated_at' => now()]);
        app(WarehouseAudit::class)->record((int) $override->company_id, 'override.reviewed', 'OVERRIDE', (string) $override->id, "Override reviewed: {$note}", null, null, $reviewer);
    }

    private function alert(int $companyId): void
    {
        $n = app(WarehouseNotifier::class);
        $n->raise($companyId, 'OVERRIDE', $n->detect($companyId, 'OVERRIDE'));
    }
}
