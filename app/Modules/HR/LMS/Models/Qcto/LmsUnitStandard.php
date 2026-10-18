<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsUnitStandard extends Model
{
    use LogsActivity;
    protected $table = 'lms_unit_standards';

    protected $fillable = [
        'saqa_id', 'title', 'credits', 'nqf_level',
        'field_of_study', 'sub_field', 'seta_id',
        'type', 'status', 'registered_at', 'expires_at', 'purpose',
    ];

    protected $casts = [
        'credits'       => 'decimal:1',
        'registered_at' => 'date',
        'expires_at'    => 'date',
    ];

    public function seta()
    {
        return $this->belongsTo(LmsSeta::class, 'seta_id');
    }

    public function poeSections()
    {
        return $this->hasMany(LmsPoeSection::class, 'unit_standard_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS Unit Standard')
            ->logOnly(['saqa_id', 'title', 'nqf_level', 'credits', 'status', 'expires_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " unit standard \"{$this->title}\" (SAQA: {$this->saqa_id}).");
    }
}
