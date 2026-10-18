<?php

namespace App\Console\Commands;

use App\Core\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Grant a module's access to people, after that module ships.
 *
 * A new module needs two separate things before anybody can open it, and neither is granted by
 * deploying the code:
 *
 *   1. The Spatie permissions its policies ask for (`pm.projects.view`, and so on).
 *   2. A row in `security_module_access` giving a hub tier — without one HubAccess falls back to
 *      READ, so every WRITE or ADMIN screen turns somebody away.
 *
 * Missing either produces "You do not have permission for this area", never a 404. If a page 404s
 * the routes are not registered, which is a deploy or route-cache problem and nothing this command
 * can help with.
 *
 * ── Why there is no "give everyone everything" ───────────────────────────────
 *
 * This is a multi-tenant SaaS with subcontractors and clients holding ordinary accounts. Granting
 * every permission to every user would hand a subcontractor the payroll, and hand one company's
 * staff the tier that reads another's. So the targets are always explicit: named users, or a role.
 * `--role=` is the one that scales — grant to "manager" once and everybody who holds it is covered.
 */
class GrantModuleAccessCommand extends Command
{
    protected $signature = 'hub:grant
        {module : security_modules.code_name, e.g. project_management}
        {--permission-prefix= : Permission namespace to grant, e.g. pm (defaults to the known map)}
        {--user=* : Email or id, repeatable}
        {--role= : Grant to every holder of this role instead}
        {--level=4 : Hub tier — 1 read, 2 write, 4 admin, 5 superuser}
        {--dry-run : Show what would change and write nothing}';

    protected $description = "Grant a module's permissions and hub tier to named users or a role";

    /**
     * Permission namespaces do not match module code names, and guessing wrongly grants nothing
     * while reporting success. Where a module is not listed, `--permission-prefix` is required.
     */
    private const PREFIXES = [
        'project_management' => 'pm',
        'attendance'         => 'attendance',
        'fleet_management'   => 'fleet',
        'help_desk'          => 'helpdesk',
        'assets'             => 'assets',
        'sheq'               => 'sheq',
        'governance'         => 'governance',
        'recruitment'        => 'recruitment',
    ];

    public function handle(): int
    {
        $moduleCode = (string) $this->argument('module');

        $module = DB::table('security_modules')->where('code_name', $moduleCode)->first(['id', 'name']);

        if (! $module) {
            $this->error("No module with code_name '{$moduleCode}'.");

            return self::FAILURE;
        }

        $prefix = (string) ($this->option('permission-prefix') ?: (self::PREFIXES[$moduleCode] ?? ''));

        if ($prefix === '') {
            $this->error("No permission prefix known for '{$moduleCode}'. Pass --permission-prefix=.");

            return self::FAILURE;
        }

        $permissions = Permission::query()->where('name', 'like', $prefix . '.%')->pluck('name')->all();

        if ($permissions === []) {
            $this->error("No permissions found matching '{$prefix}.*'. Has the module's seeder run?");

            return self::FAILURE;
        }

        $users = $this->resolveTargets();

        if ($users === null) {
            return self::FAILURE;   // resolveTargets already explained why
        }

        if ($users->isEmpty()) {
            // A role that exists but nobody holds is a different problem from naming no target,
            // and saying "name a --user" when they did name a --role sends them the wrong way.
            $this->warn($this->option('role')
                ? sprintf('Role "%s" exists but nobody holds it — nothing to grant.', $this->option('role'))
                : 'None of those users were found.');

            return self::SUCCESS;
        }

        $level = (int) $this->option('level');
        $dry = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s: %d permission(s) from "%s.*" and tier %d to %d user(s).%s',
            $module->name, count($permissions), $prefix, $level, $users->count(),
            $dry ? ' [dry run]' : '',
        ));

        foreach ($users as $user) {
            if (! $dry) {
                // givePermissionTo rather than syncPermissions: this adds a module's access without
                // silently removing whatever else somebody already holds.
                $user->givePermissionTo($permissions);

                DB::table('security_module_access')->updateOrInsert(
                    ['user_id' => $user->id, 'module_id' => $module->id],
                    ['access_level' => $level, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            $this->line('  ' . str_pad((string) $user->id, 6) . $user->email);
        }

        if (! $dry) {
            // The permission cache is what makes a grant take effect on the next request rather
            // than whenever the cache happens to expire.
            $this->call('permission:cache-reset');
        }

        $this->info($dry ? 'Nothing written.' : 'Granted.');

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>|null  null when the arguments were wrong
     */
    private function resolveTargets()
    {
        if ($role = $this->option('role')) {
            $found = Role::where('name', $role)->first();

            if (! $found) {
                $this->error("No role named '{$role}'. Available: "
                    . Role::orderBy('name')->pluck('name')->implode(', '));

                return null;
            }

            // withoutGlobalScope: a platform admin running this has no active company, and the
            // scope would otherwise hide the very users being granted access.
            return User::withoutGlobalScope('company')->role($role)->get();
        }

        $wanted = (array) $this->option('user');

        if ($wanted === []) {
            $this->error('Name at least one --user, or a --role.');

            return null;
        }

        return User::withoutGlobalScope('company')
            ->where(function ($q) use ($wanted) {
                foreach ($wanted as $one) {
                    is_numeric($one)
                        ? $q->orWhere('id', (int) $one)
                        : $q->orWhere('email', strtolower(trim($one)));
                }
            })
            ->get();
    }
}
