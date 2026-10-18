<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** What one fund is owed for one pay run, and whether it has been paid. */
class Remittance extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_remittances';

    protected $guarded = ['id'];

    protected $casts = [
        'schedule' => 'array',
        'period_start' => 'date', 'period_end' => 'date', 'due_on' => 'date',
        'paid_at' => 'datetime',
        'employee_portion' => 'decimal:2', 'employer_portion' => 'decimal:2', 'total' => 'decimal:2',
    ];

    public const STATUSES = ['due', 'paid', 'failed', 'cancelled'];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_id');
    }

    /** Owed, past its date, and still not paid — which is the only state that matters. */
    public function isOverdue(): bool
    {
        return $this->status === 'due' && $this->due_on !== null && $this->due_on->isPast();
    }
}
