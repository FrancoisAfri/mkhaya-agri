<?php

namespace App\Shared\Traits;

use App\Core\Category\Models\Category;
use App\Core\Ribbon\Models\Ribbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait BuildsNavigation
{
    public function getNavigation(): array
    {
        $user = Auth::user();

        if (!$user) {
            return [];
        }

        // Hide modules the company's plan (SaaS) or licence (self-hosted) doesn't
        // cover — matches the route-level gate (EnsureModuleIsActive).
        $entitlements = app(\App\Modules\Tenancy\Services\CompanyEntitlements::class);

        $categories = Category::whereIn('id', function ($query) use ($user) {
            $query->select('category_id')
                ->from('security_category_access')
                ->where('user_id', $user->id)
                ->where('access_level', '>', 0);
        })
            ->with([
                'modules' => function ($moduleQuery) use ($user) {
                    $moduleQuery
                        ->where('active', 1)
                        ->whereIn('id', function ($query) use ($user) {
                            $query->select('module_id')
                                ->from('security_module_access')
                                ->where('user_id', $user->id)
                                ->where('access_level', '>', 0);
                        })
                        ->with([
                            'moduleRibbons' => function ($query) use ($user) {
                                $query->whereIn(
                                    'id',
                                    Ribbon::select('security_module_ribbons.id')
                                        ->join('security_module_access', function ($join) use ($user) {
                                            $join->on(
                                                'security_module_ribbons.module_id',
                                                '=',
                                                'security_module_access.module_id'
                                            );
                                            $join->on(
                                                'security_module_access.user_id',
                                                '=',
                                                DB::raw($user->id)
                                            );
                                            $join->on(
                                                'security_module_ribbons.access_level',
                                                '<=',
                                                'security_module_access.access_level'
                                            );
                                        })
                                )
                                    ->orderBy('sort_order', 'ASC');
                            }
                        ])
                        ->orderBy('name');
                }
            ])
            ->orderBy('name')
            ->get();

        // Drop plan/licence-excluded modules, then any category left with none.
        $categories->each(function ($category) use ($entitlements) {
            $category->setRelation('modules', $category->modules->filter(
                fn ($module) => $entitlements->allowsNavModule($module->code_name)
            )->values());
        });

        return $categories->filter(fn ($category) => $category->modules->isNotEmpty())
            ->values()
            ->toArray();
    }
}
