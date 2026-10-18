<?php

namespace App\Modules\Policies\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleAction extends Model
{
    protected $fillable = [
        'rule_id',
        'type',
        'config',
        'message_template',
        'sort_order',
    ];

    protected $casts = [
        'config'      => 'array',
        'sort_order'  => 'integer',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PolicyRule::class, 'rule_id');
    }
}
