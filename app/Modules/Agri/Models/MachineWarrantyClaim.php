<?php

namespace App\Modules\Agri\Models;

/** A claim against a machine's warranty or a supplier's service guarantee (§7.3). */
class MachineWarrantyClaim extends FarmOwnedModel
{
    public const STATUSES = ['submitted', 'accepted', 'rejected', 'settled'];

    use \App\Support\Eloquent\AliasesColumns;

    /** Fleet's table: a farm machine's record lives with its asset. */
    protected $table = 'fleet_warranty_claims';

    protected function columnAliases(): array
    {
        return ['machine_id' => 'vehicle_id'];
    }

    protected $casts = ['claimed_on' => 'date', 'amount' => 'float', 'recovered' => 'float'];
}
