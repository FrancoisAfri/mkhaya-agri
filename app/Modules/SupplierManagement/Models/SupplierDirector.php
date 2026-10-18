<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierDirector extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'supplier_directors';

    protected $fillable = [
        'uuid', 'supplier_id', 'first_name', 'surname', 'tel', 'email',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierDirector $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
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
