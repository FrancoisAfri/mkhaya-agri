<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PolicyUser extends Model
{
    use HasFactory;

    protected $table = 'policy_users';

    // The table has created_at and updated_at columns
    public $timestamps = true;

    protected $fillable = [
        'policy_id',
        'user_id',
        'read_understood',
        'read_not_understood',
        'read_not_sure',
        'status',
        'date_read',
        'date_added',
        'client_documents',
    ];

    protected $casts = [
        'policy_id' => 'integer',
        'user_id' => 'integer',
        'read_understood' => 'integer',
        'read_not_understood' => 'integer',
        'read_not_sure' => 'integer',
        'status' => 'integer',
        'date_read' => 'integer',
        'date_added' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Accessors to convert Unix timestamps to Carbon instances
    public function getDateReadAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value) : null;
    }

    public function getDateAddedAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value) : null;
    }

    // Mutators to store Carbon instances as Unix timestamps
    public function setDateReadAttribute($value)
    {
        $this->attributes['date_read'] = $value instanceof \Carbon\Carbon ? $value->timestamp : $value;
    }

    public function setDateAddedAttribute($value)
    {
        $this->attributes['date_added'] = $value instanceof \Carbon\Carbon ? $value->timestamp : $value;
    }

    // Relationships (adjust model names as needed)
    public function policy()
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
