<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequestStatus extends Model
{
    use BelongsToCompany;

    protected $table = 'purchase_request_statuses';

    protected $fillable = ['key', 'label', 'sort_order', 'is_terminal'];

    protected $casts = ['is_terminal' => 'boolean'];
}
