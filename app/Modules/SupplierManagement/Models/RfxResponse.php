<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RfxResponse extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'rfx_responses';

    protected $fillable = [
        'uuid', 'rfx_id', 'supplier_id', 'amount', 'currency',
        'proposal', 'ai_score', 'ai_summary', 'submitted_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'ai_score'     => 'decimal:2',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (RfxResponse $response) {
            $response->uuid ??= (string) Str::uuid();
        });
    }

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
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
