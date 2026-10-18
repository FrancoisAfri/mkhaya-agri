<?php

namespace App\Http\Controllers;

use App\Support\Hubs\HubAccess;
use App\Support\Hubs\HubRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders a hub — a landing page of feature cards for a hubbed module (CRM, Helpdesk, …), filtered
 * to the ones the current user's access tier allows. Pure navigation; every card links to an
 * existing feature route, whose tier is already enforced by the EnsureModuleTier middleware.
 *
 * The module code and hub group arrive as route defaults, so one controller serves every module.
 */
class ModuleHubController extends Controller
{
    public function show(string $module, string $group, Request $request): Response
    {
        $hubsClass = HubRegistry::for($module);
        abort_unless($hubsClass, 404);

        $hubs = $hubsClass::hubs();
        abort_unless(isset($hubs[$group]), 404);
        $hub = $hubs[$group];

        $level = HubAccess::level($request->user(), $module);
        $cards = collect($hub['cards'])
            ->filter(fn ($c) => $c['tier'] <= $level)
            // A card may also name a policy the page itself checks. Without this the tier would
            // card a page the controller then refuses, which looks like a broken link rather than
            // a boundary. Cards with no gate are unaffected.
            ->filter(fn ($c) => empty($c['gate']) || Gate::allows($c['gate']['ability'], $c['gate']['subject']))
            ->map(fn ($c) => [
                'title' => $c['title'], 'desc' => $c['desc'], 'icon' => $c['icon'],
                'path' => '/' . ltrim($c['path'], '/'), 'color' => $c['color'],
            ])->values();

        return Inertia::render('Modules/Shared/Hub', [
            'module'   => $module,
            'group'    => $group,
            'title'    => $hub['title'],
            'subtitle' => $hub['subtitle'],
            'cards'    => $cards,
        ]);
    }
}
