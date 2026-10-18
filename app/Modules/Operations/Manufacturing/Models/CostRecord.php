<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the manufacturing cost subledger (MFG-021): an accounting-relevant event, its
 * treatment, its amount and its source document. Immutable in the database.
 */
class CostRecord extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_cost_records';

    protected $fillable = ['company_id', 'production_order_id', 'event_type', 'treatment', 'qty', 'amount', 'basis', 'stock_movement_id', 'journal_id',
        'source_type', 'source_id', 'reference', 'reversal_of_id', 'actor_id', 'booked_on', 'idempotency_key'];

    protected $casts = ['qty' => 'float', 'amount' => 'float', 'booked_on' => 'date'];

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }

    public function movement(): BelongsTo { return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockMovement::class, 'stock_movement_id')->withoutGlobalScopes(); }
}
