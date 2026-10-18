<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class CrmSurveyTemplate extends Model
{
    protected $table = 'crm_survey_templates';

    protected $fillable = ['key', 'name', 'category', 'description', 'questions'];

    protected $casts = ['questions' => 'array'];
}
