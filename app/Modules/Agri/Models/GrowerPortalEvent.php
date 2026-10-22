<?php

namespace App\Modules\Agri\Models;

/** Grower portal: The audit of a grower's portal and message use (sign-ins, failures, codes, revocations, downloads, commands). */
class GrowerPortalEvent extends AgriModel
{
    protected $table = 'agri_grower_portal_events';

    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];

    public const UPDATED_AT = null;

    /** Itself the audit trail: not written a second time to the activity log. */
    protected static $recordEvents = [];
}
