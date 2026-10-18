<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;

class FinBankMatch extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_bank_matches';

    protected $fillable = ['entity_id', 'statement_line_id', 'journal_line_id', 'amount', 'created_by'];

    protected $casts = ['amount' => 'decimal:2'];
}
