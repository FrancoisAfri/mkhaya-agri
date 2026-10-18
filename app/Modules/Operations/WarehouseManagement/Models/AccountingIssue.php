<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Something the stock ledger and the general ledger disagree about (WMS-102). */
class AccountingIssue extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_accounting_issues';

    protected $fillable = ['uuid', 'company_id', 'kind', 'severity', 'reference', 'message', 'amount', 'detail', 'status', 'resolution', 'resolved_by', 'resolved_at', 'last_seen_at'];

    protected $casts = ['amount' => 'decimal:2', 'detail' => 'array', 'resolved_at' => 'datetime', 'last_seen_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }
}
