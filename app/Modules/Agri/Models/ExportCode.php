<?php

namespace App\Modules\Agri\Models;

/** An export registration code of a farm (production unit) or its packhouse: PUC, PHC, FBO, an exporter code. */
class ExportCode extends FarmOwnedModel
{
    public const KINDS = ['PUC', 'PHC', 'FBO', 'EXPORTER', 'other'];

    protected $table = 'agri_export_codes';

    protected $casts = ['valid_until' => 'date'];
}
