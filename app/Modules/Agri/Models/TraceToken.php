<?php

namespace App\Modules\Agri\Models;

/** The public, unguessable id a consumer's QR code carries for one produce lot, and what its page may show. */
class TraceToken extends FarmOwnedModel
{
    public const ACTIVE = 'active';
    public const REVOKED = 'revoked';

    protected $table = 'agri_trace_tokens';

    protected $casts = ['visibility' => 'array', 'revoked_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'last_viewed_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'views' => 'integer'];

    public function lot(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProduceLot::class, 'produce_lot_id');
    }
}
