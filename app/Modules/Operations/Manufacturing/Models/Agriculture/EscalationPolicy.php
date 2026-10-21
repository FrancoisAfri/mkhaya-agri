<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** Who is told about an incident of a severity, and who next if nobody acknowledges it. */
class EscalationPolicy extends ProductionModel
{
    protected $table = 'prd_escalation_policies';

    protected $casts = ['steps' => 'array', 'is_active' => 'boolean'];
}
