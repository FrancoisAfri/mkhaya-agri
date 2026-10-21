<?php

namespace App\Modules\HR\Hr\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Attendance\Support\Sites;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The time-and-attendance engine's screens: workers, crews, the roster, timesheets, piecework, the
 * payroll handoff and their settings — for the whole organisation, and on a farm for its people.
 *
 * Who may do what is asked in the words of either role set: a T&A permission (HR's) or the farm
 * permission a farm role carries. A farm manager reaches their farms' people with farm permissions;
 * someone who runs attendance for the organisation reaches everybody with T&A's. Which records each
 * sees is the engine's site scope (SiteAccessScope), and changing a record on a site needs write
 * access to that site.
 */
abstract class EngineController extends Controller
{
    /** farm permission => the T&A permissions that allow the same */
    private const EQUIVALENT = [
        'agri.workforce.view' => ['attendance.records.view', 'attendance.roster.view'],
        'agri.workforce.manage' => ['attendance.records.edit'],
        'agri.roster.manage' => ['attendance.roster.manage'],
        'agri.attendance.record' => ['attendance.records.create', 'attendance.clocking.approve'],
        'agri.attendance.approve' => ['attendance.records.approve'],
        'agri.piecework.approve' => ['attendance.records.approve'],
        'agri.payroll.handoff' => ['attendance.records.approve'],
        'agri.labour.rules' => ['attendance.settings.edit'],
    ];

    protected function allow(Request $request, string ...$permissions): void
    {
        abort_unless($this->may($request, ...$permissions), 403, 'You do not have permission to do that.');
    }

    /** Holds any of the permissions, or a T&A equivalent of one. */
    protected function may(Request $request, string ...$permissions): bool
    {
        $user = $request->user();
        foreach ($permissions as $permission) {
            foreach ([$permission, ...(self::EQUIVALENT[$permission] ?? [])] as $p) {
                if ($user?->can($p)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A site (farm) by its public id, among those the person reaches; 404 otherwise. */
    protected function farm(?string $uuid): Model
    {
        $site = Sites::byUuid($uuid);
        abort_unless($site, 404, 'That farm was not found.');

        return $site;
    }

    /** The site work is being recorded on — none for the organisation's own staff — which the person may change. */
    protected function workingFarm(Request $request, ?string $uuid): ?Model
    {
        if (blank($uuid)) {
            return null;
        }
        $site = $this->farm($uuid);
        abort_unless(Sites::canWrite($request->user(), (int) $site->getKey()), 403, 'Your access to this farm is read-only.');
        Sites::assertOperational($site);

        return $site;
    }

    protected function assertWritable(Request $request, ?int $farmId): void
    {
        abort_unless(Sites::canWrite($request->user(), $farmId), 403, 'Your access to this farm is read-only.');
    }

    /** A field that must be on the given site. */
    protected function fieldOn(?Model $site, ?string $uuid): ?Model
    {
        if (blank($uuid) || ! $site) {
            return null;
        }
        $field = (Sites::model('field'))::query()->where('farm_id', $site->getKey())->where('uuid', $uuid)->first();
        abort_unless($field, 422, 'That field is not on this farm.');

        return $field;
    }

    protected function farmOptions(bool $operationalOnly = false): array
    {
        return Sites::options($operationalOnly);
    }

    protected function farmFilter(Request $request): ?Model
    {
        return Sites::byUuid($request->query('farm'));
    }

    protected static function num($v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
