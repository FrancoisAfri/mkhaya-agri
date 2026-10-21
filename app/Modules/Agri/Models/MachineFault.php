<?php

namespace App\Modules\Agri\Models;

use App\Modules\Agri\Casts\FarmDateTime;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fault on a machine: reported, repaired through a work order, and returned to service by someone authorised (§7.3). */
class MachineFault extends FarmOwnedModel
{
    public const SEVERITIES = ['minor', 'major', 'critical'];
    public const OPEN = ['open', 'in_repair', 'awaiting_return'];

    use \App\Support\Eloquent\AliasesColumns;

    /** Fleet's table: a farm machine's record lives with its asset. */
    protected $table = 'fleet_faults';

    protected function columnAliases(): array
    {
        return ['machine_id' => 'vehicle_id'];
    }

    protected $casts = ['stops_machine' => 'boolean', 'down_from' => FarmDateTime::class, 'down_to' => FarmDateTime::class, 'affected_work_orders' => 'array'];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(FarmMachine::class, 'vehicle_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FarmWorkOrder::class, 'work_order_id');
    }

    /** Hours the machine stood, to now if it is still down. */
    public function downtimeHours(): ?float
    {
        if (! $this->down_from) {
            return null;
        }

        return round($this->down_from->diffInMinutes($this->down_to ?? now()) / 60, 1);
    }
}
