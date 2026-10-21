<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A record of the agricultural production engine: tenant-scoped (and held there by row-level
 * security underneath), public by uuid.
 */
abstract class ProductionModel extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $guarded = ['id'];
}
