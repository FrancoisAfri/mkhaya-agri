<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant's own cost codes, for tenants who do not have an accounting system to take them from.
 *
 * PM never insists a cost carries a code from this list — the field on a cost entry is free text.
 * A tenant whose codes live in their accounting system types them, and this table stays empty. The
 * alternative, validating against a list PM owns, would reject the real codes.
 */
class CostCode extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_cost_codes';

    protected $fillable = ['uuid', 'company_id', 'code', 'name', 'category', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
