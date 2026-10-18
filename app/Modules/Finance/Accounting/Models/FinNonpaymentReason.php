<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;

class FinNonpaymentReason extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_nonpayment_reasons';

    protected $fillable = ['entity_id', 'code', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
