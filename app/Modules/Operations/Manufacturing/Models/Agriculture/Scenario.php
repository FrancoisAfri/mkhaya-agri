<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** Options compared for a cycle or a type, with the model that compared them and which came out best. */
class Scenario extends ProductionModel
{
    protected $table = 'prd_scenarios';

    protected $casts = ['inputs' => 'array', 'results' => 'array', 'best' => 'array'];
}
