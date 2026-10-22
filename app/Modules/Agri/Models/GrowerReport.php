<?php

namespace App\Modules\Agri\Models;

/** Grower portal: A planting report or an issue a grower sent in from the portal (photo in the database). */
class GrowerReport extends AgriModel
{
    protected $table = 'agri_grower_reports';

    protected $casts = ['planted_on' => 'date', 'hectares' => 'float', 'answered_at' => 'datetime'];

    protected $hidden = ['photo_data'];

    /** Audited like every farm record, but never with the photo itself in the log. */
    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        return \Spatie\Activitylog\LogOptions::defaults()->useLogName('agri')->logAll()
            ->logExcept(['updated_at', 'created_at', 'data', 'photo_data'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
