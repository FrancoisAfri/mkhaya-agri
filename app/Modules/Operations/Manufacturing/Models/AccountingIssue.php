<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something reconciliation could not make agree (MFG-021), waiting for somebody in finance. */
class AccountingIssue extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_accounting_exceptions';

    protected $fillable = ['uuid', 'company_id', 'kind', 'severity', 'production_order_id', 'reference', 'message', 'amount', 'detail', 'status',
        'resolved_by', 'resolved_at', 'resolution', 'dedupe_key', 'last_seen_at'];

    protected $casts = ['amount' => 'float', 'detail' => 'array', 'resolved_at' => 'datetime', 'last_seen_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }
}
