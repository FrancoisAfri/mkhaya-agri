<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class ProcurementRequisitionHistory extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_requisition_history';

    protected $fillable = ['procurement_requisition_id', 'action', 'from_status', 'to_status', 'performed_by', 'note'];
}
