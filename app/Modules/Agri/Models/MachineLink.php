<?php

namespace App\Modules\Agri\Models;

/**
 * One thing paired across the two systems: a field, machine or product we matched (or could not
 * yet), or a prescription or boundary we sent, with its id there. For a field it also remembers
 * both boundaries as they were when the two last agreed, which is how a change on both sides is
 * told from a change on one.
 */
class MachineLink extends AgriModel
{
    public const FIELD = 'field';
    public const MACHINE = 'machine';
    public const PRODUCT = 'product';
    public const PRESCRIPTION = 'prescription';
    public const BOUNDARY = 'boundary';

    protected $table = 'agri_machine_links';

    protected $casts = ['data' => 'array', 'synced_at' => 'datetime'];

    public function connection()
    {
        return $this->belongsTo(MachineConnection::class, 'connection_id');
    }
}
