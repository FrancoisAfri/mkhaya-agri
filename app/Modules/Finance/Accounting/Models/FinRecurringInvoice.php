<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

/**
 * A recurring-invoice template + schedule. Generated into real invoices by the
 * finance:generate-recurring job.
 */
class FinRecurringInvoice extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_recurring_invoices';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'party_id', 'name', 'currency', 'frequency', 'interval',
        'start_date', 'next_run_date', 'end_date', 'status', 'auto_email', 'description',
        'generated_count', 'created_by',
    ];

    protected $casts = [
        'start_date'    => 'date',
        'next_run_date' => 'date',
        'end_date'      => 'date',
        'auto_email'    => 'boolean',
    ];

    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    protected static function booted(): void
    {
        static::creating(function (FinRecurringInvoice $r) {
            if (empty($r->uuid)) {
                $r->uuid = (string) Str::uuid();
            }
        });
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinParty::class, 'party_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinRecurringInvoiceLine::class, 'recurring_id');
    }

    /** Advance a date by one cycle of this schedule. */
    public function advance(\Illuminate\Support\Carbon $date): \Illuminate\Support\Carbon
    {
        $n = max(1, (int) $this->interval);

        return match ($this->frequency) {
            'weekly'    => $date->copy()->addWeeks($n),
            'quarterly' => $date->copy()->addMonths(3 * $n),
            'yearly'    => $date->copy()->addYears($n),
            default     => $date->copy()->addMonths($n),
        };
    }
}
