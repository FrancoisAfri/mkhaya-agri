<?php

namespace App\Modules\Agri\Models;

/** A machine file made from a prescription version, kept in the database (base64) as every file is. */
class PrescriptionFile extends FarmOwnedModel
{
    public const FORMATS = ['shapefile', 'isoxml', 'geojson'];

    protected $table = 'agri_prescription_files';

    protected $hidden = ['data'];

    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        return parent::getActivitylogOptions()->logExcept(['updated_at', 'created_at', 'data']);
    }

    public function bytes(): string
    {
        return (string) base64_decode((string) $this->data);
    }
}
