<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One thing enhanced due diligence demanded, and what came back.
 *
 * `why` carries the trigger that produced it — "the bank account holder does not match" — so the
 * supplier being asked for financial statements can be told why, and the person reviewing it later
 * can see that the demand was a consequence rather than a habit.
 *
 * A task can be waived, and a waiver needs a reason. An EDD pack that can only be completed is one
 * people route around; one that can be waived silently is decoration.
 */
class DueDiligenceEddTask extends Model
{
    use BelongsToCompany;

    protected $table = 'dd_edd_tasks';

    protected $fillable = [
        'uuid', 'case_id', 'supplier_id', 'code', 'label', 'why', 'is_required',
        'status', 'response', 'document_id', 'completed_by', 'completed_at', 'waiver_reason',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public const STATUS_OUTSTANDING = 'outstanding';
    public const STATUS_PROVIDED = 'provided';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_FAILED = 'failed';

    protected static function booted(): void
    {
        static::creating(fn (self $task) => $task->uuid ??= (string) Str::uuid());
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DueDiligenceCase::class, 'case_id');
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_PROVIDED, self::STATUS_WAIVED], true);
    }
}
