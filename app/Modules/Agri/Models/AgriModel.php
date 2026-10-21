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

    /** Record times read in the farm's zone too, like every other moment on a farm record. */
    public function getCasts()
    {
        return array_merge(['created_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'updated_at' => \App\Modules\Agri\Casts\FarmDateTime::class], parent::getCasts());
    }

    /** A moment held in a farm's zone is stored as the UTC it is (Laravel would write its wall clock as is). */
    public function fromDateTime($value)
    {
        if ($value instanceof \DateTimeInterface && $value->getTimezone()->getName() !== config('app.timezone')) {
            $value = \Illuminate\Support\Carbon::instance($value)->setTimezone(config('app.timezone'));
        }

        return parent::fromDateTime($value);
    }
}
