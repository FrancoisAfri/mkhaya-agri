<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierScreening extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'supplier_screenings';

    protected $fillable = [
        'supplier_id', 'status', 'risk_level', 'sanctions_hits', 'pep_hits',
        'adverse_media', 'summary', 'recommendations', 'provider', 'model',
        'raw_response', 'error', 'screened_by', 'screened_at',
    ];

    protected $casts = [
        'sanctions_hits'  => 'array',
        'pep_hits'        => 'array',
        'adverse_media'   => 'array',
        'recommendations' => 'array',
        'screened_at'     => 'datetime',
    ];

    /** Keep the heavy raw payload out of JSON/Inertia responses. */
    protected $hidden = ['raw_response'];

    public function getActivitylogOptions(): LogOptions
    {
        // Audit the fact + outcome of a screening, not the full payload.
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logOnly(['status', 'risk_level', 'screened_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
