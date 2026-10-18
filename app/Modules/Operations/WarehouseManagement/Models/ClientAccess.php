<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A person explicitly granted one third-party client's stock (WMS-001). */
class ClientAccess extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_client_access';

    protected $fillable = ['company_id', 'user_id', 'party_id', 'granted_by'];
}
