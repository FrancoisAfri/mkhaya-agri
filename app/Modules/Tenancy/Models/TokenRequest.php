<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A company's request/purchase for more AI tokens or SMS. `mode`:
 *  - instant       → paid, granted immediately (self-service buy)
 *  - subscription  → paid, added to the next subscription invoice (admin approves)
 *  - request       → free ask, admin grants or rejects
 */
class TokenRequest extends Model
{
    protected $table = 'token_requests';

    protected $fillable = [
        'uuid', 'company_id', 'type', 'amount', 'mode',
        'unit_price', 'total_price', 'status', 'note',
        'requested_by', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'unit_price' => 'decimal:4',
        'total_price' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (TokenRequest $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
