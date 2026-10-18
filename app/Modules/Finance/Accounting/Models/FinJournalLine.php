<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One side of a journal entry. Debit or credit (one is zero). Immutable once its
 * journal is posted.
 */
class FinJournalLine extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_journal_lines';

    protected $fillable = [
        'entity_id', 'journal_id', 'account_id', 'company_id', 'debit', 'credit', 'currency',
        'fx_rate', 'base_debit', 'base_credit', 'narration', 'dimensions',
    ];

    protected $casts = [
        'debit'       => 'decimal:2',
        'credit'      => 'decimal:2',
        'fx_rate'     => 'decimal:8',
        'base_debit'  => 'decimal:2',
        'base_credit' => 'decimal:2',
        'dimensions'  => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Journal lines are immutable; reverse the journal instead.');
        });
        static::deleting(function () {
            throw new RuntimeException('Journal lines cannot be deleted; reverse the journal instead.');
        });
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }
}
