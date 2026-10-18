<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $table = 'payments';

    protected $fillable = ['uuid', 'company_id', 'gateway', 'purpose', 'amount', 'currency', 'status', 'our_ref', 'gateway_ref', 'meta', 'paid_at'];

    protected $casts = ['amount' => 'decimal:2', 'meta' => 'array', 'paid_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (Payment $p) {
            $p->uuid ??= (string) Str::uuid();
            $p->our_ref ??= 'MK-'.strtoupper(Str::random(10));
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
