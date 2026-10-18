<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmStage extends Model
{
    protected $table = 'crm_stages';

    protected $fillable = [
        'pipeline_id', 'name', 'sort_order', 'probability', 'is_won', 'is_lost', 'color', 'sla_days',
    ];

    protected $casts = [
        'sort_order'  => 'integer',
        'probability' => 'integer',
        'is_won'      => 'boolean',
        'is_lost'     => 'boolean',
        'sla_days'    => 'integer',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'stage_id');
    }
}
