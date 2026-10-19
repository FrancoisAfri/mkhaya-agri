<?php

namespace App\Modules\Agri\Models;


class MachineService extends FarmOwnedModel
{
    public const TYPES = ['service', 'repair', 'inspection'];

    protected $table = 'machine_services';

    protected $casts = ['serviced_on' => 'date', 'hours_at_service' => 'decimal:1', 'cost' => 'decimal:2'];
}
