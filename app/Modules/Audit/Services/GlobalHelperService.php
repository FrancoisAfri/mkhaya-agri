<?php

namespace App\Modules\Audit\Services;

use Spatie\Activitylog\Models\Activity;

class GlobalHelperService
{
    public static function getAvailableModels(): array
    {
        $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();

        return Activity::query()
            ->select('log_name')
            ->distinct()
            ->whereNotNull('log_name')
            ->when($cid, fn ($q) => $q->where('company_id', $cid))
            ->orderBy('log_name')
            ->pluck('log_name')
            ->toArray();
    }
}
