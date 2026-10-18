<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** How one inventory event reaches the books, as approved by finance (WMS-101). */
class AccountingRule extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_accounting_rules';

    protected $fillable = ['company_id', 'event', 'owner', 'treatment', 'debit', 'credit', 'description', 'approved_by', 'approved_at', 'suspended_at', 'suspended_reason'];

    protected $casts = ['approved_at' => 'datetime', 'suspended_at' => 'datetime'];
}
