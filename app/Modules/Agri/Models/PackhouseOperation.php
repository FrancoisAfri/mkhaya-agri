<?php

namespace App\Modules\Agri\Models;

/** Grading, sorting, packing, repacking or processing: input lots in, output lots out, with what was lost and used (§8.2). */
class PackhouseOperation extends FarmOwnedModel
{
    public const KINDS = ['grading', 'sorting', 'packing', 'repacking', 'processing'];

    protected $table = 'packhouse_operations';

    protected $casts = ['performed_on' => 'date', 'input_quantity' => 'float', 'output_quantity' => 'float', 'loss_quantity' => 'float',
        'packaging' => 'array', 'quality_checks' => 'array', 'quality_passed' => 'boolean', 'labour_cost' => 'float', 'machine_hours' => 'float',
        'input_value' => 'float', 'output_value' => 'float'];
}
