<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\EmployeeProvider;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Putting people on a project (EPIC-030, PM-1023).
 *
 * Nothing else in the delivery chain works without this. You cannot assign a task to nobody, you
 * cannot book hours against nobody, and a labour-cost report over an empty team is a page of
 * zeroes. Until now membership could only be created in code — which is why the Delivery page's
 * People tab said "nobody has been added to this project yet" with no way to add anybody.
 *
 * A member is deliberately *not* a user account. Three kinds of person end up on a project:
 *
 *   an employee    HR knows them; PM links to the HR record and keeps a name it can render when
 *                  HR is uninstalled or the employee record is later removed;
 *   a colleague    they have a login here but HR is not installed; PM links the user;
 *   a name         a subcontractor's foreman with a phone number and no login at all.
 *
 * All three are ProjectMember rows. The third is why `user_id` is nullable and why this service
 * accepts a plain name: a module that only worked for people with logins would be useless on the
 * sites this platform is built for.
 */
class TeamService
{
    /**
     * Project roles a member can hold. Distinct from platform roles — this is scope, not identity.
     *
     * ── Every key here must exist in RoleCatalog::builtIn() ─────────────────
     *
     * A member's permissions are resolved by looking their `role_key` up in `pm_roles`, which
     * RoleProvisioner seeds from the catalogue. A key that is offered here but absent there is not
     * a weaker role — it is *no* role: PermissionEvaluator finds nothing and the member silently
     * holds nothing, on a screen that says they are a Supervisor.
     *
     * This list used to be a second, independent vocabulary and four of its seven keys —
     * planner, supervisor, subcontractor, observer — matched no catalogue role at all. The worst
     * of them was `subcontractor`: the portal looks for members with `role_key = 'contractor'`
     * (PortalController, RoleCatalog::EXTERNAL_ROLES), so the only role a real subcontractor could
     * be given was the one role that kept them out of the contractor portal entirely.
     *
     * `subcontractor` was renamed to `contractor` (2026_08_14 migration) because the two were
     * never different things and the rename is what lets that person into their own portal. The
     * displayed label is unchanged — the tenant still reads "Subcontractor".
     *
     * `planner`, `supervisor` and `observer` were orphaned for the same reason and are now
     * defined in RoleCatalog with the permissions the client chose: a Planner owns the programme,
     * a Supervisor runs a crew, an Observer reads one project. KNOWN_ORPHANED_ROLES is therefore
     * empty, and RoleVocabularyTest pins it so no *new* orphan can be added without the build
     * saying so.
     */
    public const ROLES = [
        'project_manager', 'planner', 'supervisor', 'team_member',
        'contractor', 'client', 'observer',
    ];

    /**
     * Retired keys and what each became, used to migrate rows written before the rename and to
     * translate a payload from a client that has not reloaded.
     */
    public const RENAMED_ROLES = [
        'subcontractor' => 'contractor',
    ];

    /**
     * Assignable roles that resolve to no catalogue role, and therefore to no permissions.
     *
     * Empty, and meant to stay that way. A member holding such a role would see a role name on the
     * team screen and hold nothing behind it — RoleVocabularyTest fails the moment one appears.
     *
     * @var list<string>
     */
    public const KNOWN_ORPHANED_ROLES = [];

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Add someone to a project.
     *
     * @param  array{
     *     display_name?:string, role_key?:string, user_id?:int|null,
     *     employee_id?:string|null, email?:string|null
     * }  $data
     */
    public function add(Project $project, array $data): ProjectMember
    {
        $role = self::normaliseRole($data['role_key'] ?? 'team_member');

        if (! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("'{$role}' is not a project role.");
        }

        return DB::transaction(function () use ($project, $data, $role) {
            $ref = null;
            $name = trim((string) ($data['display_name'] ?? ''));
            $email = $data['email'] ?? null;

            // Linked to an employee: the name and email come from the source of truth, and a
            // snapshot of them is kept so the row still reads if that source goes away.
            if (! empty($data['employee_id'])) {
                $ref = $this->linkEmployee($data['employee_id']);
                $name = $name !== '' ? $name : $ref->display_name;
                $email = $email ?: ($ref->snapshot['email'] ?? null);
            }

            if ($name === '') {
                throw new InvalidArgumentException('A member needs a name.');
            }

            // The login behind the person, without which they are a name on a card.
            $userId = $data['user_id'] ?? $this->resolveUserId($project, $data['employee_id'] ?? null, $email);

            $existing = $this->findDuplicate($project, $userId, $ref?->id, $name);

            if ($existing) {
                // Re-adding somebody who was taken off the project restores them rather than
                // creating a second row — otherwise their booked hours belong to a member nobody
                // can see and the labour report double-counts the person.
                if ($existing->trashed()) {
                    $existing->restore();
                }

                // Adopt the link if the existing row never got one; never overwrite one it has.
                $existing->update(array_filter([
                    'is_active' => true, 'role_key' => $role, 'display_name' => $name,
                    'user_id' => $existing->user_id ?: $userId,
                ], fn ($v) => $v !== null));

                return $existing;
            }

            $member = ProjectMember::create([
                'project_id'      => $project->id,
                'user_id'         => $userId,
                'external_ref_id' => $ref?->id,
                'role_key'        => $role,
                'display_name'    => $name,
                'email'           => $email,
                'is_active'       => true,
                'created_by'      => Auth::id(),
            ]);

            $this->audit->recordAction($member, 'member.added', [
                'project' => $project->uuid,
                'role'    => $role,
                'source'  => $ref ? $ref->source_module : 'name',
            ]);

            return $member;
        });
    }

    /**
     * The user account behind a member, when there is one to find.
     *
     * ── Why this exists ──────────────────────────────────────────────────────
     *
     * `user_id` was only ever set when a caller passed one, and the two paths people actually use
     * do not: adding somebody from the employee directory links their HR record and nothing else,
     * and adding them by name links nothing at all. So members were created with a display name, an
     * email, and no account.
     *
     * Everything that asks "what is assigned to me" reads `user_id` — My Work, task notifications,
     * the reminders. All of it looked past people who were plainly on the project, because the row
     * that says so had no way to point back at their login. The name was on the card; the link was
     * not in the row.
     *
     * ── How it decides ───────────────────────────────────────────────────────
     *
     * Two identifiers, both exact, strongest first. The employee record carries `hr_people.user_id`
     * — that is the link, stated by HR, and it needs no guessing. Failing that, an email that
     * matches exactly one active account in this company.
     *
     * Name matching is deliberately not here. TeamService matches on names when looking for
     * duplicates, and says in its own comment that it is the one identifier that can be wrong;
     * being wrong there merges two rows on one project, while being wrong here attaches somebody
     * else's login to a member and shows one person another person's work.
     */
    private function resolveUserId(Project $project, ?string $employeeId, ?string $email): ?int
    {
        $companyId = $project->company_id;

        if ($employeeId) {
            $userId = DB::table('hr_people')
                ->where('id', $employeeId)
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->value('user_id');

            if ($userId) {
                return (int) $userId;
            }
        }

        if (! filled($email)) {
            return null;
        }

        /*
         * At most one, because `users.email` carries a unique index across the whole table. That is
         * what makes an email safe to match on where a name is not.
         */
        $userId = DB::table('users')
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->where('status', 1)
            /*
             * This company's people, plus those belonging to no company at all.
             *
             * A platform administrator has no `users.company_id` — they operate above tenancy — and
             * they are very often the person running the first projects on a new install. Filtering
             * strictly by company excluded exactly the account most likely to be looked for.
             *
             * Another tenant's user is still excluded, which is the case this filter is for.
             */
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->value('id');

        return $userId ? (int) $userId : null;
    }

    /**
     * A retired key translated to its replacement; anything else returned untouched.
     *
     * Applied on write rather than only in the migration because a stale browser tab, a cached
     * Inertia payload or an integration built last month can still post `subcontractor`. Rejecting
     * it would be a validation error nobody can act on; silently storing it would recreate the
     * permissionless member the rename exists to abolish.
     */
    public static function normaliseRole(?string $roleKey): ?string
    {
        return $roleKey === null ? null : (self::RENAMED_ROLES[$roleKey] ?? $roleKey);
    }

    /**
     * What validation will accept: the current roles plus the retired keys.
     *
     * Deliberately wider than what the picker offers (ROLES). A form only ever presents current
     * roles; this exists so an old payload is corrected rather than refused.
     *
     * @return list<string>
     */
    public static function acceptedRoleKeys(): array
    {
        return array_values(array_unique(array_merge(self::ROLES, array_keys(self::RENAMED_ROLES))));
    }

    /** @param  array{role_key?:string, display_name?:string, email?:string|null}  $data */
    public function update(ProjectMember $member, array $data): ProjectMember
    {
        $role = self::normaliseRole($data['role_key'] ?? null);

        if ($role !== null && ! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("'{$role}' is not a project role.");
        }

        $member->update(array_filter([
            'role_key'     => $role,
            'display_name' => $data['display_name'] ?? null,
            'email'        => $data['email'] ?? null,
        ], fn ($v) => $v !== null));

        return $member;
    }

    /**
     * Take somebody off the project.
     *
     * Deactivated rather than deleted whenever they have left a trace. Hours they booked and tasks
     * they were assigned are facts about what happened, and a foreign key that vanishes turns a
     * costed timesheet into an orphan. Someone added by mistake five minutes ago, with nothing
     * attached, is genuinely deleted.
     */
    public function remove(ProjectMember $member): string
    {
        $hasHistory = TimeEntry::where('member_id', $member->id)->exists()
            || TaskAssignment::where('member_id', $member->id)->exists();

        if ($hasHistory) {
            $member->update(['is_active' => false]);
            $this->audit->recordAction($member, 'member.deactivated', ['reason' => 'has_history']);

            return 'deactivated';
        }

        $this->audit->recordAction($member, 'member.removed', []);
        $member->delete();

        return 'removed';
    }

    /**
     * Candidates for the picker: HR employees when HR is installed, otherwise nothing — and the
     * screen falls back to typing a name, which is always available.
     *
     * @return list<array<string,mixed>>
     */
    public function candidates(string $query = ''): array
    {
        $employees = $this->providers->for(EmployeeProvider::class);

        return array_map(fn ($record) => $record->toArray(), $employees->search($query, 25));
    }

    /** True when the picker has a real directory behind it rather than only free text. */
    public function hasDirectory(): bool
    {
        return $this->providers->isIntegrated(EmployeeProvider::class);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function linkEmployee(string $employeeId): ExternalRef
    {
        $record = $this->providers->for(EmployeeProvider::class)->get($employeeId);

        if (! $record) {
            throw new InvalidArgumentException('That person is no longer in the employee directory.');
        }

        return ExternalRef::updateOrCreate(
            [
                'local_type'    => 'project_member',
                'source_module' => $record->source,
                'source_id'     => $record->id,
            ],
            [
                'source_entity'  => 'employee',
                'display_name'   => $record->label,
                'snapshot'       => $record->meta + array_filter(['email' => $record->email]),
                'sync_status'    => 'ok',
                'last_synced_at' => now(),
            ],
        );
    }

    /**
     * The same person, already on this project.
     *
     * Matched on the strongest identifier present: a user account, then an employee link, then the
     * name. Name matching is last because it is the only one that can be wrong, but leaving it out
     * would let the same foreman be added three times with three sets of hours.
     */
    private function findDuplicate(Project $project, ?int $userId, ?int $refId, string $name): ?ProjectMember
    {
        $query = ProjectMember::withTrashed()->where('project_id', $project->id);

        if ($userId) {
            return (clone $query)->where('user_id', $userId)->first();
        }

        if ($refId) {
            return (clone $query)->where('external_ref_id', $refId)->first();
        }

        return $query->whereRaw('lower(display_name) = ?', [mb_strtolower($name)])->first();
    }
}
