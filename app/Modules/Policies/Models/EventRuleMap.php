<?php

namespace App\Modules\Policies\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRuleMap extends Model
{
    protected $fillable = [
        'rule_id',
        'module',
        'action_pattern',
        'payload_filter',
        'active',
    ];

    protected $casts = [
        'payload_filter' => 'array',
        'active'         => 'boolean',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PolicyRule::class, 'rule_id');
    }
}
