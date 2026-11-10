<?php

namespace App\Modules\BI\Support;

use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\DB;

/** Which dashboards a person may open — used by the pages, the exports and the email schedules. */
final class BIDashboards
{
    /** Their own, shared with one of their roles, or organisation-wide — inside the current company. */
    public static function visible($user)
    {
        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->all() : [];

        return DB::table('bi_dashboards')->where('company_id', app(CurrentCompany::class)->id())->where(function ($q) use ($user, $roles) {
            $q->where('owner_id', $user->id)->orWhere('visibility', 'organisation')
                ->orWhere(fn ($s) => $s->where('visibility', 'shared')->where(function ($x) use ($roles) {
                    foreach ($roles ?: ['__none__'] as $role) {
                        $x->orWhereJsonContains('shared_roles', $role);
                    }
                }));
        });
    }

    /** A dashboard by key ("t:<template>" or a uuid) as ['key', 'name', 'widgets'], or null. */
    public static function find(string $key, $user): ?array
    {
        if (str_starts_with($key, 't:')) {
            $t = TemplateRegistry::get(substr($key, 2));

            return $t ? ['key' => $key, 'name' => $t['name'], 'widgets' => $t['widgets']] : null;
        }
        if (! preg_match('/^[0-9a-f-]{36}$/i', $key)) {
            return null;
        }
        $d = self::visible($user)->where('uuid', $key)->first();

        return $d ? ['key' => $key, 'name' => $d->name, 'widgets' => json_decode($d->widgets, true) ?: []] : null;
    }
}
