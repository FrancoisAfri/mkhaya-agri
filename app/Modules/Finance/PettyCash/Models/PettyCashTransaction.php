<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PettyCashTransaction extends Model
{
    protected $table = 'petty_cash_transactions';

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->performed_at)) {
                $model->performed_at = now();
            }
        });
    }

    protected $fillable = [
        'uuid', 'float_id', 'request_id', 'performed_by', 'transaction_type',
        'amount', 'balance_before', 'balance_after', 'reference', 'description', 'performed_at',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after'  => 'decimal:2',
        'performed_at'   => 'datetime',
    ];

    public function float(): BelongsTo
    {
        return $this->belongsTo(PettyCashFloat::class, 'float_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequest::class, 'request_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
