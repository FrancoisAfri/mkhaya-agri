<?php

namespace App\Modules\Agri\Models;

use Spatie\Activitylog\LogOptions;

/**
 * A boundary file someone uploaded (KML, KMZ, GeoJSON or a zipped shapefile), kept with the
 * features read from it so the person can preview and match them to fields before anything changes.
 */
class FieldMapImport extends FarmOwnedModel
{
    public const PREVIEWED = 'previewed';
    public const APPLIED = 'applied';
    public const DISCARDED = 'discarded';

    protected $table = 'field_map_imports';

    protected $hidden = ['data'];

    protected $casts = [
        'features' => 'array',
        'summary' => 'array',
        'applied_at' => \App\Modules\Agri\Casts\FarmDateTime::class,
    ];

    /** The file and every parsed coordinate stay out of the audit trail: the row itself is the record. */
    public function getActivitylogOptions(): LogOptions
    {
        return parent::getActivitylogOptions()->logExcept(['updated_at', 'created_at', 'data', 'features']);
    }
}
