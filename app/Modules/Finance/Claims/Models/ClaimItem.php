<?php

namespace App\Modules\Finance\Claims\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClaimItem extends Model
{
    use SoftDeletes;
    protected $table = 'claim_items';

    protected $fillable = [
        'claim_id', 'item_date', 'description', 'unit_price', 'quantity',
        'amount', 'item_type', 'receipt_required', 'receipt_uploaded', 'notes',
    ];

    protected $casts = [
        'item_date'        => 'date',
        'unit_price'       => 'decimal:2',
        'amount'           => 'decimal:2',
        'receipt_required' => 'boolean',
        'receipt_uploaded' => 'boolean',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClaimAttachment::class, 'claim_item_id');
    }
}
