<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** Why a cycle may not be sold, slaughtered or released: a withdrawal period, a quarantine, a QA hold. */
class Restriction extends ProductionModel
{
    protected $table = 'prd_restrictions';

    protected $casts = ['starts_on' => 'date', 'until_on' => 'date', 'lifted_at' => 'datetime'];

    public function isActive(?\Carbon\CarbonInterface $on = null): bool
    {
        $on ??= today();

        return $this->lifted_at === null && $this->starts_on->lte($on) && ($this->until_on === null || $this->until_on->gte($on));
    }
}
