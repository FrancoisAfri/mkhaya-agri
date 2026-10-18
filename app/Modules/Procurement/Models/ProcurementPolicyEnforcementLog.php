<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class ProcurementPolicyEnforcementLog extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_policy_enforcement_logs';

    public $timestamps = false;

    protected $fillable = ['source_type', 'source_id', 'rule_type', 'outcome', 'detail', 'created_at'];

    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];
}
