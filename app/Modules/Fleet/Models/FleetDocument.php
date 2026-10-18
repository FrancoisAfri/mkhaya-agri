<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class FleetDocument extends Model
{
    use SoftDeletes, LogsActivity, HasFactory, KeepsDatabaseDefaults;

    protected static function newFactory(): \Database\Factories\Fleet\FleetDocumentFactory
    {
        return \Database\Factories\Fleet\FleetDocumentFactory::new();
    }

    protected $table = 'fleet_documents';

    protected $fillable = [
        'uuid', 'vehicle_id', 'document_type', 'document_category', 'supplier',
        'contact_person', 'contact_number', 'contact_email', 'contact_address',
        'value_covered', 'payment_frequency', 'premium_amount',
        'document_number', 'issued_date', 'expiry_date', 'issuing_authority', 'file_path',
        'status', 'renewal_reminder_days', 'notes', 'version',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'issued_date'    => 'date',
        'expiry_date'    => 'date',
        'value_covered'  => 'decimal:2',
        'premium_amount' => 'decimal:2',
    ];

    public function vehicle(): BelongsTo { return $this->belongsTo(FleetVehicle::class, 'vehicle_id'); }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->expiry_date ? now()->diffInDays($this->expiry_date, false) : null;
    }

    public function scopeExpiringSoon($q, int $days = 30)
    {
        return $q->whereNotNull('expiry_date')
                 ->where('expiry_date', '<=', now()->addDays($days))
                 ->where('expiry_date', '>=', now());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fleet')
            ->logOnly(['vehicle_id', 'document_type', 'document_number', 'issued_date', 'expiry_date', 'status', 'issuing_authority'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
