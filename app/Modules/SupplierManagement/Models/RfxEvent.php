<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RfxEvent extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'rfx_events';

    protected $fillable = [
        'uuid', 'rfx_number', 'title', 'type', 'description', 'status',
        'closing_date', 'published_at', 'closed_at',
        'awarded_supplier_id', 'awarded_by', 'awarded_at', 'award_reason', 'created_by',
    ];

    protected $casts = [
        'closing_date' => 'date',
        'published_at' => 'datetime',
        'closed_at'    => 'datetime',
        'awarded_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (RfxEvent $rfx) {
            $rfx->uuid ??= (string) Str::uuid();

            if (empty($rfx->rfx_number)) {
                $maxId = (int) static::withTrashed()->max('id');
                $rfx->rfx_number = 'RFX-' . str_pad((string) ($maxId + 1), 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(RfxInvitation::class, 'rfx_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(RfxResponse::class, 'rfx_id');
    }

    public function awardedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'awarded_supplier_id');
    }

    /** Who signed off the award — half of what makes the award record evidence rather than a value. */
    public function awardedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'awarded_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['closed', 'awarded', 'cancelled']);
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
