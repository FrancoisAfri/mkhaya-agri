<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Why a machine broke down, as a code -- so repeat failures are counted, not described. */
class FaultCode extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const CATEGORIES = ['MECHANICAL', 'ELECTRICAL', 'HYDRAULIC', 'PNEUMATIC', 'CONTROL', 'TOOLING', 'OTHER'];

    protected $table = 'mfg_fault_codes';

    protected $fillable = ['uuid', 'company_id', 'code', 'name', 'category', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
