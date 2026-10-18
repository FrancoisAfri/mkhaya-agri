<?php

namespace App\Console\Commands;

use App\Core\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every current user every right, across the whole system.
 *
 * The deploy problem this solves: new code ships new permissions and sometimes whole new modules,
 * and nobody holds either until somebody grants them. Staff who could do their job on Friday are
 * locked out of the new screens on Monday. Run this after the seeders and everyone is current
 * again.
 *
 * ── The one population it leaves out, and why ────────────────────────────────
 *
 * Portal users. This platform gives subcontractors and clients ordinary `users` rows — they log in
 * with the same accounts table as staff — so "every user" taken literally would hand a
 * subcontractor payroll, HR records, and every other tenant's data. That is not a permissions
 * question, it is a breach, and it would be caused by a command run for convenience.
 *
 * So anybody holding an active client or contractor membership is skipped, and the count is
 * reported so the omission is visible rather than silent. `--include-external` overrides it for
 * whoever decides that is what they want.
 *
 * ── Idempotent and re-runnable ───────────────────────────────────────────────
 *
 * Only missing rows are written, so running it twice costs one pass of reads and changes nothing.
 * Rows are inserted in bulk rather than through Spatie's per-user API: 42 users × 381 permissions
 * is 16,000 writes, and doing that one Eloquent call at a time turns a deploy step into a coffee
 * break.
 */
class SyncAllAccessCommand extends Command
{
    protected $signature = 'access:sync-all
        {--level=4 : Hub tier for every module — 1 read, 2 write, 4 admin, 5 superuser}
        {--company= : Only users of this company id}
        {--include-external : Also grant portal users (subcontractors and clients). Read the warning first.}
        {--skip-modules : Grant permissions only, leave hub tiers alone}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Grant every existing permission, and every module tier, to all current users';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $level = (int) $this->option('level');

        $permissionIds = DB::table('permissions')->pluck('id')->all();
        $moduleIds = DB::table('security_modules')->pluck('id')->all();

        if ($permissionIds === []) {
            $this->error('No permissions exist yet. Run the permission seeders first.');

            return self::FAILURE;
        }

        $external = $this->externalUserIds();

        $users = User::withoutGlobalScope('company')
            ->when($this->option('company'), fn ($q, $c) => $q->where('company_id', (int) $c))
            ->when(! $this->option('include-external') && $external !== [],
                fn ($q) => $q->whereNotIn('id', $external))
            ->pluck('id')->all();

        if ($users === []) {
            $this->warn('No users matched.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Users: %d · permissions: %d · modules: %d · tier: %d%s',
            count($users), count($permissionIds), count($moduleIds), $level, $dry ? '  [dry run]' : '',
        ));

        if (! $this->option('include-external') && $external !== []) {
            $this->line(sprintf(
                '  skipping %d portal user(s) — subcontractors and clients. --include-external to override.',
                count($external),
            ));
        } elseif ($this->option('include-external')) {
            $this->warn('  INCLUDING portal users: subcontractors and clients will receive every permission.');
        }

        $permsAdded = $this->grantPermissions($users, $permissionIds, $dry);
        $tiersAdded = $this->option('skip-modules') ? 0 : $this->grantModuleTiers($users, $moduleIds, $level, $dry);

        $this->info(sprintf(
            '%s %d permission row(s) and %d module tier row(s).',
            $dry ? 'Would add' : 'Added', $permsAdded, $tiersAdded,
        ));

        if (! $dry && ($permsAdded > 0 || $tiersAdded > 0)) {
            // Without this the grant sits idle until the permission cache expires.
            $this->call('permission:cache-reset');
        }

        return self::SUCCESS;
    }

    /**
     * Users who are only ever an outside party.
     *
     * Membership is the signal because that is what the portal itself uses. Somebody who is both
     * staff and listed on a job is *not* excluded — they hold a PM permission, so they are staff.
     *
     * @return list<int>
     */
    private function externalUserIds(): array
    {
        if (! Schema::hasTable('pm_project_members')) {
            return [];
        }

        $memberUserIds = DB::table('pm_project_members')
            ->whereIn('role_key', ['client', 'contractor'])
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->distinct()->pluck('user_id')->all();

        if ($memberUserIds === []) {
            return [];
        }

        // Anybody in that list who already holds a staff permission is staff who happens to sit on
        // a job, and excluding them would take their own module away.
        $staff = DB::table('model_has_permissions as mp')
            ->join('permissions as p', 'p.id', '=', 'mp.permission_id')
            ->whereIn('mp.model_id', $memberUserIds)
            ->where('p.name', 'like', 'pm.%')
            ->distinct()->pluck('mp.model_id')->all();

        return array_values(array_diff($memberUserIds, $staff));
    }

    /** @param list<int> $users @param list<int> $permissionIds */
    private function grantPermissions(array $users, array $permissionIds, bool $dry): int
    {
        $modelType = User::class;

        // One read of what already exists, rather than a per-pair existence check.
        $existing = [];
        DB::table('model_has_permissions')
            ->where('model_type', $modelType)
            ->whereIn('model_id', $users)
            ->orderBy('model_id')
            ->select('model_id', 'permission_id')
            ->chunk(20000, function ($rows) use (&$existing) {
                foreach ($rows as $r) {
                    $existing[$r->model_id][$r->permission_id] = true;
                }
            });

        $rows = [];
        $added = 0;

        foreach ($users as $userId) {
            foreach ($permissionIds as $pid) {
                if (isset($existing[$userId][$pid])) {
                    continue;
                }

                $rows[] = ['permission_id' => $pid, 'model_type' => $modelType, 'model_id' => $userId];
                $added++;

                if (count($rows) >= 2000) {
                    if (! $dry) {
                        DB::table('model_has_permissions')->insertOrIgnore($rows);
                    }
                    $rows = [];
                }
            }
        }

        if ($rows !== [] && ! $dry) {
            DB::table('model_has_permissions')->insertOrIgnore($rows);
        }

        return $added;
    }

    /** @param list<int> $users @param list<int> $moduleIds */
    private function grantModuleTiers(array $users, array $moduleIds, int $level, bool $dry): int
    {
        $existing = [];
        DB::table('security_module_access')
            ->whereIn('user_id', $users)
            // chunk() needs a deterministic order or the builder refuses to page.
            ->orderBy('user_id')
            ->select('user_id', 'module_id')
            ->chunk(20000, function ($rows) use (&$existing) {
                foreach ($rows as $r) {
                    $existing[$r->user_id][$r->module_id] = true;
                }
            });

        $rows = [];
        $added = 0;
        $now = now();

        foreach ($users as $userId) {
            foreach ($moduleIds as $mid) {
                if (isset($existing[$userId][$mid])) {
                    // Left alone on purpose: somebody may have been given a deliberately lower
                    // tier on one module, and this command exists to unblock new access rather
                    // than to overwrite decisions already taken.
                    continue;
                }

                $rows[] = [
                    'user_id' => $userId, 'module_id' => $mid, 'access_level' => $level,
                    'created_at' => $now, 'updated_at' => $now,
                ];
                $added++;

                if (count($rows) >= 2000) {
                    if (! $dry) {
                        DB::table('security_module_access')->insertOrIgnore($rows);
                    }
                    $rows = [];
                }
            }
        }

        if ($rows !== [] && ! $dry) {
            DB::table('security_module_access')->insertOrIgnore($rows);
        }

        return $added;
    }
}
