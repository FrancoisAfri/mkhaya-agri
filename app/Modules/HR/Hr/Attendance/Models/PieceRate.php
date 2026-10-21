<?php

namespace App\Modules\HR\Hr\Attendance\Models;

/** What a unit of piecework pays, for an activity, unit and period — approved before it is used. */
class PieceRate extends EngineModel
{
    public const ACTIVITIES = ['harvesting', 'packing', 'weeding', 'planting', 'pruning', 'processing', 'grading', 'other'];
    public const UNITS = ['kg', 'crate', 'box', 'bin', 'row', 'ha', 'tree', 'animal', 'unit'];

    protected $table = 'attendance_piece_rates';

    protected $casts = ['rate' => 'float', 'daily_target' => 'float', 'valid_from' => 'date', 'valid_to' => 'date', 'approved_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class];
}
