<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Modules\HR\LMS\Models\LmsCourse;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsWspItem extends Model
{
    use LogsActivity;
    protected $table = 'lms_wsp_items';

    protected $fillable = [
        'wsp_plan_id', 'course_id', 'learnership_id', 'position_id',
        'training_type', 'training_description', 'target_group',
        'nqf_level', 'credits', 'ofo_code', 'saqa_id',
        'planned_beneficiaries', 'planned_cost_per_person', 'total_planned_cost',
    ];

    protected $casts = [
        'credits'               => 'decimal:1',
        'planned_cost_per_person'=> 'decimal:2',
        'total_planned_cost'    => 'decimal:2',
    ];

    public function wspPlan()
    {
        return $this->belongsTo(LmsWspPlan::class, 'wsp_plan_id');
    }

    public function course()
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function learnership()
    {
        return $this->belongsTo(LmsLearnership::class, 'learnership_id');
    }

    public function demographics()
    {
        return $this->hasMany(LmsWspItemDemographic::class, 'wsp_item_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS WSP Item')
            ->logOnly(['training_type', 'training_description', 'nqf_level', 'planned_beneficiaries', 'total_planned_cost'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " WSP item \"{$this->training_description}\" in plan #{$this->wsp_plan_id}.");
    }
}
