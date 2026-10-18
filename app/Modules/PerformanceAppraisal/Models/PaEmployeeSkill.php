<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaEmployeeSkill extends Model
{
    protected $table = 'pa_employee_skills';

    protected $fillable = [
        'employee_id', 'skill_id', 'current_level', 'target_level',
        'self_rated', 'verified_by', 'verified_at', 'evidence',
    ];

    protected $casts = [
        'self_rated'   => 'boolean',
        'verified_at'  => 'datetime',
    ];

    public function employee(): BelongsTo  { return $this->belongsTo(User::class, 'employee_id'); }
    public function skill(): BelongsTo     { return $this->belongsTo(PaSkill::class); }
    public function verifier(): BelongsTo  { return $this->belongsTo(User::class, 'verified_by'); }

    public function gapLevel(): int
    {
        return max(0, $this->target_level - $this->current_level);
    }
}
