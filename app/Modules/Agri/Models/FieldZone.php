<?php

namespace App\Modules\Agri\Models;

/**
 * A management zone: a named part of one field, drawn inside its boundary, with a productivity
 * class and soil notes. Field-scoped on purpose — satellite statistics and variable-rate
 * prescriptions are later attached to the zone, and the zone never leaves its field.
 */
class FieldZone extends FarmOwnedModel
{
    public const CLASSES = ['high', 'medium', 'low', 'exclude', 'other'];

    public const ACTIVE = 'active';
    public const ARCHIVED = 'archived';

    protected $table = 'field_zones';

    protected $casts = [
        'boundary' => 'array',
        'area_ha' => 'decimal:4',
    ];
}
