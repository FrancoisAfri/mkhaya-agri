<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierScar extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'supplier_scars';

    protected $fillable = [
        'uuid', 'supplier_id', 'scar_number', 'title', 'description',
        'severity', 'status', 'root_cause', 'corrective_action',
        'due_date', 'assigned_to', 'raised_by', 'closed_at',
    ];

    protected $casts = [
        'due_date'  => 'date',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierScar $scar) {
            $scar->uuid ??= (string) Str::uuid();

            if (empty($scar->scar_number)) {
                $maxId = (int) static::withTrashed()->max('id');
                $scar->scar_number = 'SCAR-' . str_pad((string) ($maxId + 1), 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['closed', 'rejected']);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
