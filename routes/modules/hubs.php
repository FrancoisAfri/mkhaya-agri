<?php

use App\Http\Controllers\ModuleHubController;
use App\Support\Hubs\HubRegistry;
use Illuminate\Support\Facades\Route;

/**
 * Hub landing pages for the second wave of hubbed modules.
 *
 * The first-wave modules (CRM, Helpdesk, Payroll, …) declare their hub routes inside their own
 * route files because those groups already carry module prefixes and tier middleware. The modules
 * listed in HubRegistry::GENERIC_HUB_ROUTES have their feature routes spread across many files and
 * top-level paths, so their landings are registered here in one loop straight from the ModuleHubs
 * config — add a hub to a config and its route exists, with nothing to keep in sync by hand.
 *
 * Each landing is gated by `module.active` only. ModuleHubController already filters the cards to
 * the user's access tier, and every card's own route keeps whatever guard it has today; adding
 * `module.tier` to these modules' feature routes is a separate, deliberate change.
 *
 * ℹ️ Required inside the authenticated group in routes/web.php.
 */
foreach (HubRegistry::GENERIC_HUB_ROUTES as $code) {
    $hubsClass = HubRegistry::for($code);
    if (! $hubsClass) {
        continue;
    }

    foreach ($hubsClass::hubs() as $group => $hub) {
        Route::middleware("module.active:{$code}")
            ->get('/' . ltrim($hub['path'], '/'), [ModuleHubController::class, 'show'])
            ->defaults('module', $code)
            ->defaults('group', $group)
            ->name("hubs.{$code}.{$group}");
    }
}
