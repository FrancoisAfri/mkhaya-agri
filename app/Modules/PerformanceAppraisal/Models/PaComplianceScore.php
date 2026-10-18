<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;

class PaComplianceScore extends Model
{
    protected $table = 'pa_compliance_scores';
    protected $guarded = [];

    protected $casts = [
        'compliance_score' => 'float',
        'computed_at'      => 'datetime',
    ];

    public function gradeLabel(): string
    {
        return match (true) {
            $this->compliance_score >= 90 => 'Excellent',
            $this->compliance_score >= 75 => 'Good',
            $this->compliance_score >= 60 => 'Acceptable',
            $this->compliance_score >= 40 => 'At Risk',
            default                        => 'Critical',
        };
    }

    public function gradeColor(): string
    {
        return match (true) {
            $this->compliance_score >= 90 => 'green',
            $this->compliance_score >= 75 => 'blue',
            $this->compliance_score >= 60 => 'orange',
            $this->compliance_score >= 40 => 'deep-orange',
            default                        => 'error',
        };
    }
}
