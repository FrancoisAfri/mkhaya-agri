<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Procurement\Models\GoodsReceivedNote;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Freight, duty, insurance and handling spread over the stock they brought in (WMS-103). */
class LandedCost extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_landed_costs';

    protected $fillable = ['uuid', 'company_id', 'number', 'grn_id', 'party_id', 'invoice_reference', 'basis', 'components', 'total', 'status',
        'journal_id', 'to_inventory', 'to_cogs', 'created_by', 'posted_by', 'posted_at'];

    protected $casts = ['components' => 'array', 'total' => 'decimal:2', 'to_inventory' => 'decimal:2', 'to_cogs' => 'decimal:2', 'posted_at' => 'datetime'];

    public const KINDS = ['FREIGHT', 'DUTY', 'INSURANCE', 'HANDLING', 'CLEARING', 'OTHER'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function lines(): HasMany { return $this->hasMany(LandedCostLine::class, 'landed_cost_id'); }

    public function grn(): BelongsTo { return $this->belongsTo(GoodsReceivedNote::class, 'grn_id'); }
}
