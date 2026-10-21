<?php

namespace App\Modules\HR\Hr\Attendance\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Who sees which attendance records. Someone who manages attendance for the organisation sees
 * everybody's. Anyone else sees the records on the sites they may reach (a farm manager, their
 * farms) — and so no records at all when they reach no site. With no site limits (a console run,
 * an organisation administrator) nothing is filtered.
 */
class SiteAccessScope implements Scope
{
    public const ORGANISATION_WIDE = 'attendance.records.view';

    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if ($user && (($user->is_platform_admin ?? false) || $user->can(self::ORGANISATION_WIDE))) {
            return;
        }
        $ids = Sites::accessible();
        if ($ids === null) {
            return;
        }
        $ids === [] ? $builder->whereRaw('1 = 0') : $builder->whereIn($model->getTable().'.farm_id', $ids);
    }
}
