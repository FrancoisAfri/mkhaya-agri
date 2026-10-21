<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** An instruction to equipment, and every state it went through on the way to being confirmed. */
class Command extends ProductionModel
{
    protected $table = 'prd_commands';

    protected $casts = ['parameters' => 'array', 'history' => 'array', 'approved_at' => 'datetime', 'sent_at' => 'datetime', 'accepted_at' => 'datetime', 'executed_at' => 'datetime', 'confirm_by' => 'datetime'];

    public const STATES = ['requested', 'pending_approval', 'approved', 'rejected', 'sent', 'accepted', 'executed', 'failed', 'unconfirmed', 'cancelled'];
    public const FINAL = ['rejected', 'executed', 'failed', 'cancelled'];

    public function device(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Device::class, 'device_id'); }
}
