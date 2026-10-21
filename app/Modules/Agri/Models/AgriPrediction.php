<?php

namespace App\Modules\Agri\Models;

/** A prediction as it was made — basis, confidence, data date — and, once known, what happened (§10.2). */
class AgriPrediction extends AgriModel
{
    public const MODELS = ['yield', 'harvest_ready', 'stock_out', 'machine_breakdown'];

    protected $table = 'agri_predictions';

    protected $casts = ['predicted_on' => 'date', 'target_date' => 'date', 'predicted_date' => 'date', 'actual_date' => 'date', 'data_as_of' => 'date',
        'predicted_value' => 'float', 'actual_value' => 'float', 'error' => 'float', 'basis' => 'array', 'scored_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
