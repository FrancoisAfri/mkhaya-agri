<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

class AttendanceProductivityTrend extends Model
{
    protected $fillable = [
        'scope', 'scope_key', 'metric', 'period_date', 'period_type',
        'value', 'prev_value', 'change_pct', 'trend_direction',
    ];

    protected $casts = ['period_date' => 'date'];

    const DIRECTION_ICONS = [
        'up'     => 'fa-arrow-trend-up',
        'down'   => 'fa-arrow-trend-down',
        'stable' => 'fa-minus',
    ];

    public function scopeForScope($q, string $scope, ?string $key = null)
    {
        return $q->where('scope', $scope)->when($key, fn($q) => $q->where('scope_key', $key));
    }

    public function scopeForMetric($q, string $metric) { return $q->where('metric', $metric); }
}
