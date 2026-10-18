<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Document numbering (MFG-001.06). A NULL factory_id is a company-wide sequence.
 *
 * The number is handed out by NumberSequences inside a locking transaction rather than by reading
 * `next_number` here: two work orders released in the same second must not be given the same
 * number, and an Eloquent read-then-save is exactly how they would be.
 */
class NumberSequence extends Model
{
    use BelongsToCompany, HasPublicUuid;

    /**
     * Confined to the factories the signed-in person is assigned to (MFG-001.09). Company
     * isolation underneath is untouched; this only narrows it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('factory_id', true));
    }

    protected $table = 'mfg_number_sequences';

    protected $fillable = [
        'uuid', 'company_id', 'factory_id', 'document_type', 'prefix', 'suffix',
        'padding', 'next_number', 'reset_period', 'period_key', 'is_active',
    ];

    protected $casts = [
        'padding' => 'integer',
        'next_number' => 'integer',
        'is_active' => 'boolean',
    ];

    public const RESET_PERIODS = ['NONE', 'YEAR', 'MONTH'];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    /** What this sequence's next number would look like, without consuming it. */
    public function preview(): string
    {
        return $this->format((int) $this->next_number);
    }

    public function format(int $number): string
    {
        return $this->prefix.str_pad((string) $number, (int) $this->padding, '0', STR_PAD_LEFT).$this->suffix;
    }
}
