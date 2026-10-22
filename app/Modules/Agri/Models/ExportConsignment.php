<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** An export load's consignment: who it goes to and how, the cold chain, and the lots on it — what the export document pack is made from. */
class ExportConsignment extends FarmOwnedModel
{
    public const INCOTERMS = ['EXW', 'FCA', 'FAS', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

    public const MODES = ['sea', 'air', 'road'];

    protected $table = 'agri_export_consignments';

    protected $casts = ['required_documents' => 'array', 'cold_treatment' => 'boolean', 'departure_on' => 'date', 'arrival_on' => 'date', 'inspection_on' => 'date',
        'temperature_setpoint_c' => 'float', 'temperature_min_c' => 'float', 'temperature_max_c' => 'float'];

    public function lines(): HasMany
    {
        return $this->hasMany(ExportConsignmentLine::class, 'consignment_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ExportDocument::class, 'consignment_id');
    }
}
