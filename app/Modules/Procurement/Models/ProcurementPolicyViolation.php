<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProcurementPolicyViolation extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_policy_violations';

    protected $fillable = [
        'uuid', 'source_type', 'source_id', 'rule_type', 'severity', 'message', 'context', 'status', 'created_by',
    ];

    protected $casts = ['context' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn (ProcurementPolicyViolation $m) => $m->uuid ??= (string) Str::uuid());
    }
}
