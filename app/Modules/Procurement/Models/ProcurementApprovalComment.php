<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class ProcurementApprovalComment extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_approval_comments';

    protected $fillable = ['procurement_approval_id', 'step_id', 'user_id', 'comment'];
}
