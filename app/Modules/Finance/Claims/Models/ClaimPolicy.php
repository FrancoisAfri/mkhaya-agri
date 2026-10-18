<?php

namespace App\Modules\Finance\Claims\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimPolicy extends Model
{
    protected $table = 'claim_policies';

    protected $fillable = [
        'name', 'rule_key', 'category_id', 'condition_type',
        'condition_value', 'approval_role', 'action',
        'description', 'active', 'created_by',
    ];

    protected $casts = [
        'condition_value' => 'array',
        'active'          => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ClaimCategory::class, 'category_id');
    }

    public function getLimitAttribute(): float
    {
        return (float) ($this->condition_value['limit'] ?? 0);
    }

    public function getDaysAttribute(): int
    {
        return (int) ($this->condition_value['days'] ?? 30);
    }
}
