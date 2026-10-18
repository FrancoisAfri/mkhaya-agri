<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinBudgetLine extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_budget_lines';

    protected $fillable = ['entity_id', 'budget_id', 'account_id', 'period_no', 'amount', 'dimension'];

    protected $casts = ['period_no' => 'integer', 'amount' => 'decimal:2'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }
}
