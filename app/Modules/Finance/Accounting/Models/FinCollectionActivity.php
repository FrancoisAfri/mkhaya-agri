<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One collections touch-point on a debtor invoice: a note, a promise to pay, a
 * reminder that was sent, or a recorded non-payment reason + next action.
 */
class FinCollectionActivity extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_collection_activities';

    protected $fillable = [
        'entity_id', 'invoice_id', 'party_id', 'type', 'promised_date', 'promised_amount',
        'reason_code', 'next_action_date', 'body', 'created_by', 'channel', 'meta',
    ];

    protected $casts = [
        'promised_date'    => 'date',
        'promised_amount'  => 'decimal:2',
        'next_action_date' => 'date',
        'meta'             => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinInvoice::class, 'invoice_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
