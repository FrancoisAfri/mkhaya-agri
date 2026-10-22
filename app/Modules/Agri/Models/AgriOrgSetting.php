<?php

namespace App\Modules\Agri\Models;

/** Organisation-wide farm settings: the peer-benchmark opt-in and what consumers may see on a trace page. */
class AgriOrgSetting extends AgriModel
{
    protected $table = 'agri_org_settings';

    protected $casts = ['benchmarks_opt_in' => 'boolean', 'benchmarks_opted_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'trace_defaults' => 'array', 'certifications' => 'array'];

    public static function for(int $companyId): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId], ['benchmarks_opt_in' => false]);
    }
}
