<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A stock-holding location. Quantity is tracked per warehouse (fin_stock_balances) while item
 * costing/value stays company-wide. Exactly one warehouse per company is the default (used for
 * sales relief and as the pre-selected location in the UI). Tenant-scoped (company_id + RLS).
 */
class FinWarehouse extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_warehouses';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'code', 'name', 'address', 'is_default', 'is_active',
        // WHMI (WMS-003). Configuration of the physical warehouse; none of it changes valuation.
        'warehouse_type', 'timezone', 'work_calendar_id', 'manager_user_id', 'sheq_site_id',
        'sync_from_site', 'site_synced_at', 'capacity_basis', 'capacity_value', 'opening_time',
        'closing_time', 'dispatch_cutoff_time', 'permitted_categories', 'policies', 'is_wms_managed',
    ];

    protected $casts = [
        'is_default' => 'boolean', 'is_active' => 'boolean', 'sync_from_site' => 'boolean',
        'is_wms_managed' => 'boolean', 'site_synced_at' => 'datetime', 'capacity_value' => 'decimal:4',
        'permitted_categories' => 'array', 'policies' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FinWarehouse $w) => $w->uuid ??= (string) Str::uuid());
    }

    public function balances(): HasMany
    {
        return $this->hasMany(FinStockBalance::class, 'warehouse_id');
    }

    public function bins(): HasMany
    {
        return $this->hasMany(FinStockBin::class, 'warehouse_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
