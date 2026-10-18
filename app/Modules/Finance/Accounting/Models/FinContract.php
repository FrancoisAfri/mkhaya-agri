<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

/**
 * A billable contract/project: progress claims recognise revenue and hold back
 * retention; retention is released later. Claims are receivable invoices.
 */
class FinContract extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_contracts';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'party_id', 'income_account_id', 'name', 'contract_value',
        'retention_percent', 'billed_net', 'retention_held', 'status', 'description', 'created_by',
    ];

    protected $casts = [
        'contract_value'    => 'decimal:2',
        'retention_percent' => 'decimal:3',
        'billed_net'        => 'decimal:2',
        'retention_held'    => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (FinContract $c) {
            if (empty($c->uuid)) {
                $c->uuid = (string) Str::uuid();
            }
        });
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinParty::class, 'party_id');
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'income_account_id');
    }

    public function remaining(): float
    {
        return round((float) $this->contract_value - (float) $this->billed_net, 2);
    }
}
