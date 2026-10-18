<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FleetBooking extends Model
{
    use SoftDeletes, LogsActivity, HasFactory;

    protected static function newFactory(): \Database\Factories\Fleet\FleetBookingFactory
    {
        return \Database\Factories\Fleet\FleetBookingFactory::new();
    }

    protected $table = 'fleet_bookings';

    protected $fillable = [
        'uuid', 'reference', 'vehicle_id', 'driver_id', 'requester_id',
        'purpose', 'destination', 'departure_at', 'expected_return_at', 'actual_return_at',
        'start_odometer', 'end_odometer', 'km_travelled',
        'workflow_state', 'current_step_id',
        'approved_by', 'approved_at', 'cancelled_by', 'cancellation_reason',
        'security_checked_by', 'security_checked_at', 'notes', 'version',
        'usage_type', 'is_local_trip', 'project', 'passenger_count', 'passenger_ids', 'on_behalf_of_id', 'supporting_document_path',
        'vehicle_make_snapshot', 'vehicle_model_snapshot', 'vehicle_year_snapshot', 'vehicle_reg_snapshot',
        'collection_location', 'collection_document_path', 'collection_image_path', 'collected_at', 'collected_by',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'departure_at'        => 'datetime',
        'expected_return_at'  => 'datetime',
        'actual_return_at'    => 'datetime',
        'approved_at'         => 'datetime',
        'security_checked_at' => 'datetime',
        'collected_at'        => 'datetime',
        'is_local_trip'       => 'boolean',
        'passenger_ids'       => 'array',
        'start_odometer'      => 'decimal:1',
        'end_odometer'        => 'decimal:1',
        'km_travelled'        => 'decimal:1',
    ];

    protected $appends = ['supporting_document_file', 'collection_document_file', 'collection_image_file'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            $m->uuid      ??= (string) Str::uuid();
            $m->reference ??= 'BK-' . now()->format('Y') . '-' . str_pad(static::withTrashed()->count() + 1, 5, '0', STR_PAD_LEFT);
        });
    }

    public function vehicle(): BelongsTo    { return $this->belongsTo(FleetVehicle::class, 'vehicle_id'); }
    public function driver(): BelongsTo     { return $this->belongsTo(FleetDriver::class, 'driver_id'); }
    public function requester(): BelongsTo  { return $this->belongsTo(User::class, 'requester_id'); }
    public function approver(): BelongsTo   { return $this->belongsTo(User::class, 'approved_by'); }
    public function collectedBy(): BelongsTo { return $this->belongsTo(User::class, 'collected_by'); }

    public function inspections(): HasMany
    {
        return $this->hasMany(FleetBookingInspection::class, 'booking_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(FleetBookingApproval::class, 'booking_id')->orderBy('step_number');
    }

    public function tollgates(): HasMany
    {
        return $this->hasMany(FleetBookingTollgate::class, 'booking_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(FleetBookingIncident::class, 'booking_id');
    }

    public function scopePending($q)    { return $q->where('workflow_state', 'pending'); }
    public function scopeApproved($q)   { return $q->where('workflow_state', 'approved'); }
    public function scopeInUse($q)      { return $q->whereIn('workflow_state', ['security_out', 'security_in']); }
    public function scopeReturned($q)   { return $q->where('workflow_state', 'returned'); }
    public function scopeActive($q)
    {
        return $q->whereIn('workflow_state', ['pending', 'approved', 'collected', 'security_out', 'security_in']);
    }

    private function decodeFileData(?string $json): ?array
    {
        if (!$json) return null;
        $d = json_decode($json, true);
        if (!$d || !isset($d['data'])) return null;
        return [
            'name'     => $d['original_name'] ?? 'file',
            'mime'     => $d['mime'],
            'data_url' => 'data:' . $d['mime'] . ';base64,' . $d['data'],
        ];
    }

    public function getSupportingDocumentFileAttribute(): ?array
    {
        return $this->decodeFileData($this->supporting_document_path);
    }

    public function getCollectionDocumentFileAttribute(): ?array
    {
        return $this->decodeFileData($this->collection_document_path);
    }

    public function getCollectionImageFileAttribute(): ?array
    {
        return $this->decodeFileData($this->collection_image_path);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fleet')
            ->logOnly(['status', 'vehicle_id', 'driver_id', 'purpose', 'departure_at', 'expected_return_at', 'actual_return_at', 'km_start', 'km_end', 'approved_by', 'cancelled_by', 'cancel_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
