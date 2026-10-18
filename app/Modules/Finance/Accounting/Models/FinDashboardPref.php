<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class FinDashboardPref extends Model
{
    protected $table = 'fin_dashboard_prefs';

    protected $fillable = ['user_id', 'order', 'hidden'];

    protected $casts = ['order' => 'array', 'hidden' => 'array'];
}
