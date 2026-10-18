<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PolicyRefreshed extends Model
{
    use HasFactory;

    protected $table = 'policy_refreshed';

    // The table has created_at and updated_at columns (timestamp without time zone)
    public $timestamps = true;

    protected $fillable = [
        'policy_id',
        'hr_id',
        'status',
        'date_refreshed',
    ];

    protected $casts = [
        'policy_id' => 'integer',
        'hr_id' => 'integer',
        'status' => 'integer',
        'date_refreshed' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Optional: Accessor to convert date_refreshed (Unix timestamp) to a Carbon instance
    public function getDateRefreshedAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value) : null;
    }

    // Optional: Mutator to store Carbon instance as Unix timestamp
    public function setDateRefreshedAttribute($value)
    {
        $this->attributes['date_refreshed'] = $value instanceof \Carbon\Carbon ? $value->timestamp : $value;
    }

    // Define relationships (example – adjust model names as needed)
    public function policy()
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function hr()
    {
        return $this->belongsTo(User::class, 'hr_id');
    }
}
