<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** This system's record and the other system's reference for the same thing. */
class IntegrationMapping extends Model
{
    use BelongsToCompany;

    public const ENTITIES = ['PRODUCT', 'UNIT', 'WAREHOUSE', 'ORDER', 'SUPPLIER', 'CUSTOMER', 'WORK_CENTRE'];

    protected $table = 'mfg_integration_mappings';

    protected $fillable = ['company_id', 'integration_id', 'entity', 'local_id', 'external_ref'];
}
