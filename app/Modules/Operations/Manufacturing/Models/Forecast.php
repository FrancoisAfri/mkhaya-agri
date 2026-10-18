<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Expected demand for a product in a month (MFG-004). A forecast is never a sales commitment: it is
 * planned against only where confirmed orders do not already cover it, and it never creates one.
 */
class Forecast extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_forecasts';

    protected $fillable = ['uuid', 'company_id', 'stock_item_id', 'factory_id', 'period_start', 'qty', 'status', 'notes', 'created_by', 'approved_by'];

    protected $casts = ['period_start' => 'date', 'qty' => 'decimal:4'];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'stock_item_id')
            ->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class);
    }

    public function factory(): BelongsTo { return $this->belongsTo(Factory::class, 'factory_id'); }
}
