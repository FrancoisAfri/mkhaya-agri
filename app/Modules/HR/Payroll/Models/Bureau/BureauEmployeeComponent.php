<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A catalogue component assigned to an employee (recurring or once-off input). */
class BureauEmployeeComponent extends Model
{
    protected $table = 'bureau_employee_components';

    protected $fillable = ['bureau_employee_id', 'bureau_component_id', 'amount', 'quantity', 'active'];

    protected $casts = ['amount' => 'decimal:2', 'quantity' => 'decimal:2', 'active' => 'boolean'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(BureauEmployee::class, 'bureau_employee_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(BureauComponent::class, 'bureau_component_id');
    }
}
