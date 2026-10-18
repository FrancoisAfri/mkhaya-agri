<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequestHistory extends Model
{
    use BelongsToCompany;

    protected $table = 'purchase_request_history';

    protected $fillable = ['purchase_request_id', 'action', 'from_status', 'to_status', 'performed_by', 'note'];
}
