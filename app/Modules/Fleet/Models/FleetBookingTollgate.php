<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetBookingTollgate extends Model
{
    protected $table = 'fleet_booking_tollgates';

    protected $fillable = [
        'booking_id', 'vehicle_id', 'amount', 'toll_location',
        'toll_at', 'receipt_path', 'created_by',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'toll_at' => 'datetime',
    ];

    protected $appends = ['receipt_file'];

    public function booking(): BelongsTo   { return $this->belongsTo(FleetBooking::class); }
    public function vehicle(): BelongsTo   { return $this->belongsTo(FleetVehicle::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function getReceiptFileAttribute(): ?array
    {
        if (!$this->receipt_path) return null;
        $d = json_decode($this->receipt_path, true);
        if (!$d || !isset($d['data'])) return null;
        return [
            'name'     => $d['original_name'] ?? 'receipt',
            'mime'     => $d['mime'],
            'data_url' => 'data:' . $d['mime'] . ';base64,' . $d['data'],
        ];
    }
}
