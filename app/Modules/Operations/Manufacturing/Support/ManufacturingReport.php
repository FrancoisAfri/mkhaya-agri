<?php

namespace App\Modules\Operations\Manufacturing\Support;

/**
 * The subject the management dashboards are authorised against (MFG-018). Not a record: each
 * dashboard is a policy ability, so a role sees the dashboards its permissions cover and no others.
 */
final class ManufacturingReport
{
    public const DASHBOARDS = ['executive', 'planning', 'inventory', 'costs', 'quality'];
}
