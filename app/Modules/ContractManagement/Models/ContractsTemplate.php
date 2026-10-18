<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class ContractsTemplate extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes, KeepsDatabaseDefaults;

    protected $table = 'contracts_templates';

    protected $fillable = ['uuid', 'name', 'content', 'status', 'owner_id'];

    protected $casts = ['status' => 'boolean'];

    /**
     * A null company_id here is the shipped library, shared by every tenant.
     *
     * Paired with the null allowance on the `tenant_isolation` policy added in
     * 2026_08_10_000001_share_contract_templates_across_tenants. Both are required: the scope
     * decides what Eloquent asks for and the policy decides what Postgres returns.
     */
    public static function sharesGlobalRecords(): bool
    {
        return true;
    }

    /** Is this one of the shared library templates rather than something the tenant authored? */
    public function isShared(): bool
    {
        return $this->company_id === null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Contract Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (ContractsTemplate $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }
}
