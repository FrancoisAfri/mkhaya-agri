<?php

namespace App\Modules\Agri\Models;

/** A farm's self-assessment answer to one control point of an installed scheme. */
class SelfAssessment extends FarmOwnedModel
{
    public const ANSWERS = ['compliant', 'non_compliant', 'not_applicable'];

    protected $table = 'agri_self_assessments';

    protected $casts = ['answered_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
