<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaHighPotential extends Model
{
    protected $table = 'pa_high_potentials';

    protected $fillable = [
        'employee_id', 'cycle_id', 'composite_score', 'potential_band',
        'factors', 'is_ai_generated', 'identified_by',
    ];

    protected $casts = [
        'factors'          => 'array',
        'is_ai_generated'  => 'boolean',
        'composite_score'  => 'decimal:2',
    ];

    public function employee(): BelongsTo   { return $this->belongsTo(User::class, 'employee_id'); }
    public function cycle(): BelongsTo      { return $this->belongsTo(PaCycle::class); }
    public function identifier(): BelongsTo { return $this->belongsTo(User::class, 'identified_by'); }
}
