<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProcurementPolicyException extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'procurement_policy_exceptions';

    protected $fillable = [
        'uuid', 'source_type', 'source_id', 'rule_type', 'reason', 'status',
        'requested_by', 'approved_by', 'acted_at',
    ];

    protected $casts = ['acted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (ProcurementPolicyException $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('Procurement')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
