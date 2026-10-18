<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FinDeliveryNote extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_delivery_notes';

    protected $fillable = ['entity_id', 'uuid', 'company_id', 'order_id', 'party_id', 'number', 'delivery_date', 'notes', 'created_by'];

    protected $casts = ['delivery_date' => 'date'];

    protected static function booted(): void
    {
        static::creating(fn (FinDeliveryNote $d) => $d->uuid ??= (string) Str::uuid());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(FinSalesOrder::class, 'order_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinParty::class, 'party_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinDeliveryNoteLine::class, 'delivery_note_id');
    }
}
