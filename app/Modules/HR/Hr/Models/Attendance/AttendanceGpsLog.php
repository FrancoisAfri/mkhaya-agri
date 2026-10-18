<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;

class AttendanceGpsLog extends Model
{
    protected $table = 'attendance_gps_logs';

    protected $fillable = [
        'employee_id', 'clocking_id',
        'latitude', 'longitude', 'accuracy_meters', 'altitude_meters',
        'address', 'is_within_geofence', 'geofence_name', 'work_site', 'captured_at',
    ];

    protected $casts = [
        'latitude'           => 'float',
        'longitude'          => 'float',
        'accuracy_meters'    => 'float',
        'altitude_meters'    => 'float',
        'is_within_geofence' => 'boolean',
        'captured_at'        => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function clocking()
    {
        return $this->belongsTo(AttendanceClocking::class, 'clocking_id');
    }

    public function distanceTo(float $lat, float $lng): float
    {
        $earthRadius = 6371000; // metres
        $dLat = deg2rad($lat - $this->latitude);
        $dLng = deg2rad($lng - $this->longitude);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
