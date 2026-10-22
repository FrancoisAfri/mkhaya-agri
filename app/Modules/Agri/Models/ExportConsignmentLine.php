<?php

namespace App\Modules\Agri\Models;

/** A lot on an export consignment: its pallets, cartons, net and gross mass, HS code and price. */
class ExportConsignmentLine extends FarmOwnedModel
{
    protected $table = 'agri_export_consignment_lines';

    protected $casts = ['cartons' => 'float', 'net_kg' => 'float', 'gross_kg' => 'float', 'unit_price' => 'float'];
}
