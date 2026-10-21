<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** A production type as it stood at one version: the definition a cycle started on keeps applying to it. */
class TypeVersion extends ProductionModel
{
    protected $table = 'prd_type_versions';

    protected $casts = ['definition' => 'array'];
}
