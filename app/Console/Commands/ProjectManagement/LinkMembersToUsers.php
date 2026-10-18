<?php

namespace App\Console\Commands\ProjectManagement;

use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Point existing project members at the accounts they belong to.
 *
 * A member row was created with a display name and an email and no `user_id`, because the two ways
 * people are actually added — from the employee directory, or by typing a name — never set one. The
 * cause is fixed in TeamService; this is for the rows made before it was.
 *
 * It matters more than a null column suggests. Everything that answers "what is assigned to me"
 * reads `user_id`: My Work, task notifications, due-date reminders. All of it looked past people
 * whose names were on the cards, because the row saying so could not point back at their login.
 *
 * Two identifiers, both exact: the employee record's own `hr_people.user_id`, then the email, which
 * is safe to match on because `users.email` is uniquely indexed. Names are not matched — being
 * wrong here attaches one person's login to another person's member row, and shows them work that
 * is not theirs.
 *
 * Dry by default, because it writes to every project's team.
 */
class LinkMembersToUsers extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:link-members-to-users
                            {--write : Apply the links; without this the command only reports}
                            {--company= : Limit the pass to one company}';

    protected $description = 'Link project members to the user accounts they represent';

    public function handle(TenantContext $tenants): int
    {
        $write = (bool) $this->option('write');
        $linked = 0;

        $this->eachTenant($tenants, function ($companyId) use ($write, &$linked) {
            $linked += $this->sweep($companyId, $write);
        });

        /*
         * Members belonging to no company, swept once at the end.
         *
         * `pm_project_members.company_id` is stamped from whoever is signed in, so a row created by
         * a console pass, an importer or a platform admin can carry none. A strictly per-tenant
         * pass would report success having skipped every one of them — and on a single-tenant
         * install that can be most of the team.
         *
         * Safe without a tenant, because a member owned by nobody cannot be another tenant's. The
         * account it is matched to is still required to belong to that member's own company or to
         * none at all.
         */
        if ($this->option('company') === null) {
            $linked += $this->sweep(null, $write);
        }

        $this->newLine();
        $this->info($write
            ? "Linked {$linked} member(s)."
            : "{$linked} member(s) would be linked. Re-run with --write to apply.");

        return self::SUCCESS;
    }

    private function sweep($companyId, bool $write): int
    {
        $linked = 0;
        $duplicates = 0;

        $members = DB::table('pm_project_members')
            ->whereNull('user_id')
            ->whereNull('deleted_at')
            ->when($companyId !== null,
                fn ($q) => $q->where('company_id', $companyId),
                fn ($q) => $q->whereNull('company_id'))
            ->get(['id', 'project_id', 'company_id', 'display_name', 'email', 'external_ref_id']);

        foreach ($members as $member) {
            [$userId, $how] = $this->matchFor($member, $companyId);

            if ($userId === null) {
                continue;
            }

            /*
             * Somebody added twice to the same project, once with an account and once without.
             *
             * `pm_project_members_unique` covers (company, project, user) for live rows, so linking
             * this one would collide — correctly, because the person is already on the project.
             * Reported rather than merged: moving the assignments and booked hours off a duplicate
             * is a decision with consequences for the labour report, and a backfill quietly doing
             * it is how somebody's month of hours goes missing.
             */
            $duplicate = DB::table('pm_project_members')
                ->where('project_id', $member->project_id)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->value('id');

            if ($duplicate) {
                $this->warn(sprintf(
                    '  #%d %s — already on this project as #%d, left alone.',
                    $member->id, $member->display_name, $duplicate,
                ));
                $duplicates++;

                continue;
            }

            $this->line(sprintf('  #%d %s → user %d (by %s)',
                $member->id, $member->display_name, $userId, $how));

            if ($write) {
                DB::table('pm_project_members')->where('id', $member->id)
                    ->update(['user_id' => $userId, 'updated_at' => now()]);
            }

            $linked++;
        }

        if ($duplicates > 0) {
            $this->warn("  {$duplicates} duplicate member row(s) need somebody to decide what to do with them.");
        }

        return $linked;
    }

    /** @return array{0: ?int, 1: string} the user id and how it was found, or null and why not */
    private function matchFor(object $member, $companyId): array
    {
        // The employee record states the link; nothing here has to guess at it.
        if ($member->external_ref_id) {
            $sourceId = DB::table('pm_external_refs')
                ->where('id', $member->external_ref_id)
                ->where('source_entity', 'employee')
                ->value('source_id');

            if ($sourceId) {
                $userId = DB::table('hr_people')
                    ->where('id', $sourceId)
                    ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                    ->value('user_id');

                if ($userId) {
                    return [(int) $userId, 'employee record'];
                }
            }
        }

        if (! filled($member->email)) {
            return [null, 'no identifier'];
        }

        /*
         * At most one, because `users.email` is uniquely indexed across the whole table — which is
         * what makes an email safe to match on where a name is not.
         *
         * This company's people, plus those belonging to no company at all: a platform admin has no
         * `users.company_id`, and is very often the person running the first projects on a new
         * install. The first pass of this matched nothing for exactly that reason. Another tenant's
         * user is still excluded, which is what the filter is for.
         */
        $userId = DB::table('users')
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($member->email))])
            ->where('status', 1)
            ->when($companyId !== null,
                fn ($q) => $q->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id')))
            ->value('id');

        return $userId ? [(int) $userId, 'email'] : [null, 'no match'];
    }
}
