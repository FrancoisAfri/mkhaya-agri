<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A supplier payment run: draft → approved (maker-checker) → paid, with a bank
 * file export.
 */
class FinPaymentBatch extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_payment_batches';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'reference', 'bank_code', 'status', 'total', 'payment_date',
        'notes', 'created_by', 'approved_by', 'approved_at', 'paid_at',
    ];

    protected $casts = [
        'total'        => 'decimal:2',
        'payment_date' => 'date',
        'approved_at'  => 'datetime',
        'paid_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (FinPaymentBatch $b) {
            if (empty($b->uuid)) {
                $b->uuid = (string) Str::uuid();
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinPaymentBatchLine::class, 'batch_id');
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
