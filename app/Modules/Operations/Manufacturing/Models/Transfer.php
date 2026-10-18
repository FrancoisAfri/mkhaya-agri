<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stock moving from one of the company's factories to another (MFG-024). */
class Transfer extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const REQUESTED = 'REQUESTED';
    public const APPROVED = 'APPROVED';
    public const SHIPPED = 'SHIPPED';
    public const RECEIVED = 'RECEIVED';
    public const CANCELLED = 'CANCELLED';

    /** Where stock is kept at the destination while it is on its way. */
    public const IN_TRANSIT_STATUS = 'ON_HOLD';

    protected $table = 'mfg_transfers';

    protected $fillable = ['uuid', 'company_id', 'number', 'from_factory_id', 'to_factory_id', 'from_warehouse_id', 'to_warehouse_id', 'status', 'needed_by',
        'reason', 'production_order_id', 'requested_by', 'approved_by', 'approved_at', 'shipped_by', 'shipped_at', 'received_by', 'received_at', 'discrepancy'];

    protected $casts = ['needed_by' => 'date', 'approved_at' => 'datetime', 'shipped_at' => 'datetime', 'received_at' => 'datetime'];

    public function lines(): HasMany { return $this->hasMany(TransferLine::class, 'transfer_id'); }

    public function fromFactory(): BelongsTo { return $this->belongsTo(Factory::class, 'from_factory_id')->withoutGlobalScopes(); }

    public function toFactory(): BelongsTo { return $this->belongsTo(Factory::class, 'to_factory_id')->withoutGlobalScopes(); }

    public function fromWarehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'from_warehouse_id'); }

    public function toWarehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'to_warehouse_id'); }
}
