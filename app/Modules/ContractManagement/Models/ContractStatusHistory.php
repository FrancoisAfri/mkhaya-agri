<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractStatusHistory extends Model
{
    use BelongsToCompany;

    protected $table = 'contract_status_histories';

    protected $fillable = [
        'contract_id',
        'from_status',
        'to_status',
        'changed_by',
        'changed_at',
        'days_in_status',
        'notes',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'days_in_status' => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
