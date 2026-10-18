<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaNineBoxPlacement extends Model
{
    protected $table = 'pa_nine_box_placements';

    protected $fillable = [
        'cycle_id', 'employee_id', 'performance_band', 'potential_band', 'box',
        'performance_score', 'potential_score', 'is_manual', 'notes', 'placed_by',
    ];

    protected $casts = [
        'performance_score' => 'float',
        'potential_score'   => 'float',
        'box'               => 'integer',
        'is_manual'         => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaCycle::class, 'cycle_id');
    }

    /** Box number 1..9 from performance(x) & potential(y) bands. */
    public static function boxFor(string $performance, string $potential): int
    {
        $map = ['low' => 0, 'medium' => 1, 'high' => 2];
        $x = $map[$performance] ?? 1;
        $y = $map[$potential] ?? 1;
        // box 1 = bottom-left (low/low) … box 9 = top-right (high/high)
        return ($y * 3) + $x + 1;
    }

    public static function bandFor(float $score, float $max = 5.0): string
    {
        $pct = $max > 0 ? ($score / $max) : 0;
        return $pct >= 0.75 ? 'high' : ($pct >= 0.45 ? 'medium' : 'low');
    }
}
