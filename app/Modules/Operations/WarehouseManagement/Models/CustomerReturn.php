<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A customer return (RMA): authorised, received, inspected and decided line by line (WMS-080). */
class CustomerReturn extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_customer_returns';

    protected $fillable = ['uuid', 'company_id', 'number', 'warehouse_id', 'order_id', 'invoice_id', 'reference', 'customer_party_id',
        'customer_name', 'status', 'reason', 'credit_note_id', 'credit_amount', 'created_by', 'received_by', 'received_at',
        'completed_at', 'cancel_reason'];

    protected $casts = ['credit_amount' => 'decimal:2', 'received_at' => 'datetime', 'completed_at' => 'datetime'];

    public const AUTHORISED = 'AUTHORISED';
    public const RECEIVED = 'RECEIVED';
    public const INSPECTING = 'INSPECTING';
    public const COMPLETED = 'COMPLETED';
    public const CANCELLED = 'CANCELLED';
    public const STATUSES = [self::AUTHORISED, self::RECEIVED, self::INSPECTING, self::COMPLETED, self::CANCELLED];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function order(): BelongsTo { return $this->belongsTo(FulfilmentOrder::class, 'order_id'); }

    public function invoice(): BelongsTo { return $this->belongsTo(FinInvoice::class, 'invoice_id'); }

    public function creditNote(): BelongsTo { return $this->belongsTo(FinInvoice::class, 'credit_note_id'); }

    public function lines(): HasMany { return $this->hasMany(CustomerReturnLine::class, 'return_id')->orderBy('id'); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
