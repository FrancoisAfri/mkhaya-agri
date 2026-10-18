<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An auto-allocation rule: when a statement line's description matches, post it to
 * a preset GL account / customer / supplier — Sage-style bank feed matching.
 */
class FinBankRule extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_bank_rules';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'bank_account_id', 'name', 'priority', 'match_type',
        'match_value', 'direction', 'allocation_type', 'contra_account_id', 'party_id',
        'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean', 'priority' => 'integer'];

    public const MATCH_TYPES = ['contains', 'equals', 'starts', 'regex'];
    public const ALLOCATION_TYPES = ['account', 'customer', 'supplier'];

    protected static function booted(): void
    {
        static::creating(fn (FinBankRule $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function contraAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'contra_account_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinParty::class, 'party_id');
    }

    /** Does this rule apply to the given statement line? */
    public function matches(FinBankStatementLine $line): bool
    {
        $amount = (float) $line->amount;
        if ($this->direction === 'in' && $amount < 0) {
            return false;
        }
        if ($this->direction === 'out' && $amount >= 0) {
            return false;
        }

        $haystack = Str::lower((string) $line->description);
        $needle = Str::lower(trim($this->match_value));

        return match ($this->match_type) {
            'equals' => $haystack === $needle,
            'starts' => str_starts_with($haystack, $needle),
            'regex'  => @preg_match('/' . $this->match_value . '/i', (string) $line->description) === 1,
            default  => $needle !== '' && str_contains($haystack, $needle),
        };
    }
}
