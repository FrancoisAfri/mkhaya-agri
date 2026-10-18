<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class ProcurementPolicyRule extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'procurement_policy_rules';

    protected $fillable = [
        'uuid', 'name', 'type', 'min_amount', 'max_amount', 'role', 'params', 'active', 'sort_order',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'params'     => 'array',
        'active'     => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (ProcurementPolicyRule $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('Procurement')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
