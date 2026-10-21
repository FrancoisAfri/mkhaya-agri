<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One measurement from a device, at the time it was measured (not when it arrived). */
class Reading extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'prd_readings';

    protected $guarded = ['id'];

    protected $casts = ['measured_at' => 'datetime', 'received_at' => 'datetime', 'value' => 'float', 'raw_value' => 'float'];
}
