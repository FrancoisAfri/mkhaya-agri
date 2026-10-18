<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicySimulation extends Model
{
    protected $table = 'policy_simulations';

    protected $fillable = [
        'policy_id',
        'run_by',
        'simulation_config',
        'status',
        'results',
        'projected_violations',
        'impacted_departments',
        'completed_at',
    ];

    protected $casts = [
        'simulation_config'   => 'array',
        'results'             => 'array',
        'impacted_departments'=> 'array',
        'projected_violations'=> 'integer',
        'completed_at'        => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
