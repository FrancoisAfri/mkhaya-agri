<?php

namespace App\Providers;

use App\Core\Category\Models\Category;
use App\Core\Ribbon\Models\Ribbon;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ViewServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void {}

 public function boot(): void
{
    Inertia::share([
        'navigation' => function () {
            $user = Auth::user();
            if (!$user) return [];

            return Category::whereIn('id', function ($query) use ($user) {
                $query->select('category_id')
                    ->from('security_category_access')
                    ->where('user_id', $user->id)
                    // ->where('access_level', '>', 0)
                    ;
            })
            ->with([
                'modules' => function ($moduleQuery) use ($user) {
                    $moduleQuery
                        ->where('active', 1)
                        ->whereIn('id', function ($query) use ($user) {
                            $query->select('module_id')
                                ->from('security_module_access')
                                ->where('user_id', $user->id)
                                // ->where('access_level', '>', 0)
                                ;
                        })
                        ->with(['moduleRibbons' => function ($query) use ($user) {
                            $query->whereIn('id', Ribbon::select('security_module_ribbons.id')->join('security_module_access', function ($join) use ($user) {
                                $join->on('security_module_ribbons.module_id', '=', 'security_module_access.module_id');
                                $join->on('security_module_access.user_id', '=', DB::raw($user->id));
                                $join->on('security_module_ribbons.access_level', '<=', 'security_module_access.access_level');
                            })->get());
                            $query->orderBy('sort_order', 'ASC');
                        }])
                        ->orderBy('name');
                }
            ])
            ->orderBy('name')
            ->get()
            // Hide modules the company's plan (SaaS) or licence (self-hosted) doesn't
            // cover, then drop any category left with no visible modules.
            ->each(function ($category) {
                $ent = app(\App\Modules\Tenancy\Services\CompanyEntitlements::class);
                $category->setRelation('modules', $category->modules->filter(
                    fn ($module) => $ent->allowsNavModule($module->code_name)
                )->values());
            })
            ->filter(fn ($category) => $category->modules->isNotEmpty())
            ->values();
        },

        // The nav ribbon that owns the current page. Hub cards (e.g. disciplinary/corrective-actions)
        // are not ribbons themselves, so without this the menu has nothing to match and the open
        // branch collapses as soon as you open a card.
        'nav_active_path' => function () {
            $path = trim(request()->path(), '/');
            if ($path === '' || $path === '/') return null;

            // Most specific match wins: the same page can be a ribbon of two modules
            // (settings/user-access belongs to both Reports and User Management).
            $best = null;
            foreach (\App\Support\Hubs\HubRegistry::all() as $hubs) {
                $match = $hubs::ribbonMatchForPath($path);
                if ($match && strlen($match['base']) > strlen($best['base'] ?? '')) {
                    $best = $match;
                }
            }

            if (! $best) return null;

            // A module can be hub-configured before `hubs:sync` has installed its hub ribbons, so
            // the resolved ribbon may not be in the menu yet. Returning it anyway would leave the
            // whole branch unhighlighted; returning null lets the client fall back to matching the
            // URL against the ribbons that do exist — exactly the behaviour before hubbing.
            $exists = DB::table('security_module_ribbons')
                ->where('ribbon_path', $best['ribbon'])
                ->where('active', 1)
                ->exists();

            return $exists ? '/' . $best['ribbon'] : null;
        },

        'profile_completion' => function () {
            $user = Auth::user();
            if (!$user) return 0;

            $hr = HRPerson::where('user_id', $user->id)->first();

            return $hr?->profile_completion ?? 0;
        }
    ]);
}
}
