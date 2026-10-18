<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A non-conformance report (MFG-009): what was wrong, how it was contained, why it happened, what
 * is being done so it does not happen again, and whether that worked.
 */
class Nonconformance extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasDocuments, HasPublicUuid;

    public const OPEN = 'OPEN';
    public const INVESTIGATING = 'INVESTIGATING';
    public const ACTION = 'ACTION';
    public const VERIFICATION = 'VERIFICATION';
    public const CLOSED = 'CLOSED';
    public const VOID = 'VOID';
    public const STATUSES = [self::OPEN, self::INVESTIGATING, self::ACTION, self::VERIFICATION, self::CLOSED, self::VOID];

    public const SOURCES = ['INCOMING', 'IN_PROCESS', 'FINAL', 'CUSTOMER', 'INTERNAL'];
    public const SEVERITIES = ['MINOR', 'MAJOR', 'CRITICAL'];

    protected $table = 'mfg_nonconformances';

    protected $fillable = [
        'uuid', 'company_id', 'number', 'source', 'inspection_id', 'stock_item_id', 'lot_id', 'production_order_id',
        'supplier_id', 'purchase_order_id', 'defect_code', 'description', 'qty_affected', 'severity', 'status',
        'containment', 'root_cause', 'owner_id', 'due_date', 'effectiveness', 'effectiveness_notes', 'verified_by',
        'verified_at', 'closed_at', 'void_reason', 'sheq_ncr_id', 'raised_by',
    ];

    protected $casts = [
        'qty_affected' => 'decimal:6', 'due_date' => 'date', 'verified_at' => 'datetime', 'closed_at' => 'datetime',
    ];

    public function actions(): HasMany { return $this->hasMany(NcrAction::class, 'ncr_id')->orderBy('id'); }

    public function inspection(): BelongsTo { return $this->belongsTo(Inspection::class, 'inspection_id'); }

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }

    public function owner(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'owner_id'); }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'stock_item_id')
            ->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockLot::class, 'lot_id')->withoutGlobalScopes();
    }

    public function isOpen(): bool { return ! in_array($this->status, [self::CLOSED, self::VOID], true); }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->copy()->endOfDay()->isPast();
    }
}
