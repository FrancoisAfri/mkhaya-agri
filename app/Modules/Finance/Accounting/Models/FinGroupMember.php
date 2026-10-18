<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class FinGroupMember extends Model
{
    protected $table = 'fin_group_members';

    protected $fillable = ['group_id', 'company_id', 'name', 'ownership_pct', 'fx_rate', 'fx_method', 'is_parent'];

    protected $casts = ['ownership_pct' => 'decimal:2', 'fx_rate' => 'decimal:6', 'is_parent' => 'boolean'];
}
