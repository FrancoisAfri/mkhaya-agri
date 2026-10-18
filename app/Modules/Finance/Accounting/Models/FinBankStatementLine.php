<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;

class FinBankStatementLine extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_bank_statement_lines';

    protected $fillable = [
        'entity_id', 'bank_account_id', 'statement_date', 'description', 'reference', 'amount', 'balance', 'hash', 'status', 'created_by',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'amount'         => 'decimal:2',
        'balance'        => 'decimal:2',
    ];

    public function matches()
    {
        return $this->hasMany(FinBankMatch::class, 'statement_line_id');
    }
}
