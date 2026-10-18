<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RfxInvitation extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'rfx_invitations';

    protected $fillable = [
        'rfx_id', 'supplier_id', 'status', 'invited_at',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
    ];

    public function rfx(): BelongsTo
    {
        return $this->belongsTo(RfxEvent::class, 'rfx_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logOnly(['status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
