<?php

namespace App\Modules\Agri\Models;

/**
 * A field operation read from a machine platform — an application, a seeding or a harvest — with
 * its raw payload kept exactly as it came (for audit) beside what we understood of it and what it
 * became here: a field activity with its inputs, or harvest figures and, when someone records it,
 * a harvest record. Keyed on the platform's operation id, so importing it again updates it.
 */
class MachineOperation extends AgriModel
{
    public const TYPES = ['application', 'seeding', 'harvest'];

    protected $table = 'agri_machine_operations';

    protected $casts = ['products' => 'array', 'zones' => 'array', 'rate_points' => 'array', 'raw' => 'array', 'started_at' => 'datetime', 'ended_at' => 'datetime',
        'area_ha' => 'float', 'total' => 'float', 'yield_per_ha' => 'float', 'moisture_pct' => 'float'];

    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        return parent::getActivitylogOptions()->logExcept(['updated_at', 'created_at', 'raw', 'rate_points']);
    }

    public function connection()
    {
        return $this->belongsTo(MachineConnection::class, 'connection_id');
    }
}
