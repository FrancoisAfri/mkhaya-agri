<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A journal (double-entry document). Immutable once posted — corrections are made
 * with a reversing journal, never by editing. See the Ledger service.
 */
class FinJournal extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_journals';

    protected $fillable = [
        'entity_id', 'uuid', 'seq', 'company_id', 'journal_no', 'journal_date', 'period_id', 'source_module',
        'source_ref', 'event_type', 'currency', 'description', 'status',
        'reverses_journal_id', 'idempotency_key', 'metadata', 'prev_hash', 'hash', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'journal_date' => 'date',
        'posted_at'    => 'datetime',
        'metadata'     => 'array',
    ];

    protected static function booted(): void
    {
        // Immutability: once posted (or reversed), the only permitted mutation is
        // the posted -> reversed transition (status field only). Everything else
        // — including any change to an already-reversed journal — is blocked.
        static::updating(function (FinJournal $j) {
            $original = $j->getOriginal('status');
            $isReversalTransition = $original === 'posted'
                && array_keys($j->getDirty()) === ['status']
                && $j->status === 'reversed';

            if (in_array($original, ['posted', 'reversed'], true) && ! $isReversalTransition) {
                throw new RuntimeException('Posted journals are immutable; reverse instead.');
            }
        });

        static::deleting(function () {
            throw new RuntimeException('Journals cannot be deleted; reverse instead.');
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinJournalLine::class, 'journal_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }
}
