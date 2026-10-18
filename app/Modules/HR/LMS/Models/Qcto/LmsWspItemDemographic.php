<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use Illuminate\Database\Eloquent\Model;

class LmsWspItemDemographic extends Model
{
    protected $table = 'lms_wsp_item_demographics';

    protected $fillable = ['wsp_item_id', 'demographic_key', 'beneficiary_count'];

    protected $casts = ['beneficiary_count' => 'integer'];

    public function wspItem()
    {
        return $this->belongsTo(LmsWspItem::class, 'wsp_item_id');
    }
}
