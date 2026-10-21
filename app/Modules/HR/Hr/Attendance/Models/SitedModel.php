<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use App\Modules\HR\Hr\Attendance\Support\SiteAccessScope;
use App\Modules\HR\Hr\Attendance\Support\Sites;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An engine record that may name a site, and so is seen by who may reach that site (SiteAccessScope). */
abstract class SitedModel extends EngineModel
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('farm_access', new SiteAccessScope);
    }

    /** The site (a farm) the record is on, if any. */
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Sites::model('site'), 'farm_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(Sites::model('field'), 'field_id');
    }
}
