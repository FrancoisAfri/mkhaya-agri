<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubscriptionInvoice extends Model
{
    protected $table = 'subscription_invoices';

    protected $fillable = ['uuid', 'company_id', 'period', 'amount', 'status', 'due_date', 'paid_at', 'reference', 'gateway'];

    protected $casts = ['amount' => 'decimal:2', 'due_date' => 'date', 'paid_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (SubscriptionInvoice $i) => $i->uuid ??= (string) Str::uuid());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
