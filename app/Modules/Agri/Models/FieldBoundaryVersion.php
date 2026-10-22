<?php

namespace App\Modules\Agri\Models;

/**
 * One change to a boundary: the shape before and after, the area each measured and who changed it.
 * A field's history is its versions in order; with no field it is the farm's own boundary.
 * Written once and never edited, so the area a crop plan was costed on can always be traced.
 */
class FieldBoundaryVersion extends FarmOwnedModel
{
    public const DRAWN = 'drawn';
    public const EDITED = 'edited';
    public const IMPORTED = 'imported';
    public const REMOVED = 'removed';
    public const SEEDED = 'seeded';

    protected $table = 'field_boundary_versions';

    protected $casts = [
        'boundary' => 'array',
        'previous_boundary' => 'array',
        'area_ha' => 'decimal:4',
        'previous_area_ha' => 'decimal:4',
        'recorded_area_ha' => 'decimal:4',
    ];
}
