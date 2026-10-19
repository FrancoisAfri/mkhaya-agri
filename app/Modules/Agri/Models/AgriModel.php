<?php

namespace App\Modules\Agri\Models;

use App\Modules\Agri\Concerns\AuditsAgri;
use App\Modules\Agri\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An organisation-owned Mkhaya Agri record: tenant-scoped, audited, public by uuid. Catalogue
 * tables (crop types, seasons, species) extend this directly; farm-owned records extend
 * FarmOwnedModel, which adds farm access.
 */
abstract class AgriModel extends Model
{
    use AuditsAgri, BelongsToCompany, HasPublicUuid;

    protected $guarded = ['id'];
}
