<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;

/**
 * An outbox row: a FinancialEvent awaiting (or completed) posting to the ledger.
 */
class FinPosting extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_postings';

    protected $fillable = [
        'entity_id', 'event_id', 'source_module', 'source_ref', 'idempotency_key', 'payload',
        'status', 'attempts', 'error', 'journal_id', 'processed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];
}
