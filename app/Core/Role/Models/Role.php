<?php

namespace App\Core\Role\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Thin app-level Role model. (Per-tenant role isolation via spatie "teams" was
 * evaluated and reverted — roles are global-by-name; tenant data is isolated by
 * row-level security instead.) Kept as a stable import target for app code.
 */
class Role extends SpatieRole
{
}
