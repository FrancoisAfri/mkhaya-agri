<?php

namespace App\Core\Ribbon\Observers;

use App\Core\HowTo\Services\HowToGenerator;
use App\Core\Ribbon\Models\Ribbon;

/**
 * Keeps how-to coverage automatic: whenever a new ribbon (feature) is added to any
 * module, a baseline how-to is generated for it so the Mkhaya AI bot and the /how-to
 * page can answer questions about it immediately — no manual authoring step required.
 */
class RibbonObserver
{
    public function __construct(private HowToGenerator $generator)
    {
    }

    public function created(Ribbon $ribbon): void
    {
        // Bulk ribbon creation (seeders, migrations, tests) is covered in one pass by
        // HowToCoverageSeeder — only auto-generate for runtime (admin-UI) additions so
        // seeding stays fast and test row-counts aren't perturbed.
        if (app()->runningInConsole()) {
            return;
        }

        $module = $ribbon->module;
        if (! $module) {
            return;
        }

        // Never let how-to generation block ribbon creation.
        try {
            $this->generator->ensureForRibbon($module, $ribbon);
        } catch (\Throwable) {
        }
    }
}
