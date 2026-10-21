<?php

namespace App\Modules\Agri\Models;

/** An approved agronomic reference, farm procedure or regulation — the only ground advice stands on (§10.4). */
class AgriReference extends AgriModel
{
    public const KINDS = ['agronomic', 'procedure', 'regulation'];

    protected $table = 'agri_references';

    protected $casts = ['stages' => 'array', 'topics' => 'array', 'approved_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'review_by' => 'date'];
}
