<?php

namespace App\Modules\Operations\ProjectManagement\Support;

/**
 * The nine built-in roles (PM-1021) and the permissions each carries.
 *
 * This is the source of truth the per-tenant `pm_roles` rows are seeded from, and the matrix
 * documented in docs/PM_PERMISSION_MATRIX.md. Changing a built-in here and re-running the seeder
 * updates every tenant's copy; a tenant's own cloned roles are never touched (PM-1022).
 *
 * Two roles are external-facing and are the reason `pm.costs.view` exists as its own permission:
 * a Client or a Contractor may see progress and their own work, but must never see what the job
 * costs us or what we make on it. That is enforced in the presenter, not just by hiding a tab.
 */
class RoleCatalog
{
    // ── Permissions ─────────────────────────────────────────────────────────
    public const VIEW_PROJECTS = 'pm.projects.view';

    public const CREATE_PROJECTS = 'pm.projects.create';

    public const UPDATE_PROJECTS = 'pm.projects.update';

    public const DELETE_PROJECTS = 'pm.projects.delete';

    public const MANAGE_PROJECTS = 'pm.projects.manage';

    public const VIEW_TASKS = 'pm.tasks.view';

    public const CREATE_TASKS = 'pm.tasks.create';

    public const UPDATE_TASKS = 'pm.tasks.update';

    public const DELETE_TASKS = 'pm.tasks.delete';

    public const MANAGE_SCHEDULE = 'pm.schedule.manage';

    public const VIEW_EVIDENCE = 'pm.evidence.view';

    public const RECORD_EVIDENCE = 'pm.evidence.record';

    public const SUPERSEDE_EVIDENCE = 'pm.evidence.supersede';

    public const VERIFY_EVIDENCE = 'pm.evidence.verify';

    public const OVERRIDE_DERIVED = 'pm.derived.override';

    public const MANAGE_SETTINGS = 'pm.settings.manage';

    /** Internal cost, budget and margin. Absent from Client and Contractor by design. */
    public const VIEW_COSTS = 'pm.costs.view';

    /** Add or remove people on a project. */
    public const MANAGE_MEMBERS = 'pm.members.manage';

    /** Every permission the module defines. */
    public const ALL = [
        self::VIEW_PROJECTS, self::CREATE_PROJECTS, self::UPDATE_PROJECTS, self::DELETE_PROJECTS,
        self::MANAGE_PROJECTS, self::VIEW_TASKS, self::CREATE_TASKS, self::UPDATE_TASKS,
        self::DELETE_TASKS, self::MANAGE_SCHEDULE, self::VIEW_EVIDENCE, self::RECORD_EVIDENCE,
        self::SUPERSEDE_EVIDENCE, self::VERIFY_EVIDENCE, self::OVERRIDE_DERIVED,
        self::MANAGE_SETTINGS, self::VIEW_COSTS, self::MANAGE_MEMBERS,
    ];

    /**
     * Permissions that only make sense against a particular project. Asking whether someone "can
     * update tasks" with no project in mind is only answerable from a global grant.
     */
    public const PROJECT_SCOPED = [
        self::VIEW_PROJECTS, self::UPDATE_PROJECTS, self::DELETE_PROJECTS, self::MANAGE_PROJECTS,
        self::VIEW_TASKS, self::CREATE_TASKS, self::UPDATE_TASKS, self::DELETE_TASKS,
        self::MANAGE_SCHEDULE, self::VIEW_EVIDENCE, self::RECORD_EVIDENCE, self::SUPERSEDE_EVIDENCE,
        self::VIEW_COSTS, self::MANAGE_MEMBERS,
    ];

    /**
     * @return array<string, array{name:string, description:string, permissions:list<string>}>
     */
    public static function builtIn(): array
    {
        $readOnly = [self::VIEW_PROJECTS, self::VIEW_TASKS, self::VIEW_EVIDENCE];

        return [
            'portfolio_manager' => [
                'name'        => 'Portfolio Manager',
                'description' => 'Runs a portfolio of projects: full delivery control plus cost visibility and derived-value overrides.',
                'permissions' => array_values(array_diff(self::ALL, [self::MANAGE_SETTINGS])),
            ],
            'project_manager' => [
                'name'        => 'Project Manager',
                'description' => 'Runs a project end to end: scope, schedule, people, evidence and cost.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::CREATE_PROJECTS, self::UPDATE_PROJECTS, self::MANAGE_PROJECTS,
                    self::VIEW_TASKS, self::CREATE_TASKS, self::UPDATE_TASKS, self::DELETE_TASKS,
                    self::MANAGE_SCHEDULE, self::VIEW_EVIDENCE, self::RECORD_EVIDENCE,
                    self::SUPERSEDE_EVIDENCE, self::VIEW_COSTS, self::MANAGE_MEMBERS,
                ],
            ],
            'coordinator' => [
                'name'        => 'Coordinator',
                'description' => 'Keeps the plan current: maintains tasks and records evidence, but does not reschedule or change cost.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::VIEW_TASKS, self::CREATE_TASKS, self::UPDATE_TASKS,
                    self::VIEW_EVIDENCE, self::RECORD_EVIDENCE, self::VIEW_COSTS,
                ],
            ],
            'team_member' => [
                'name'        => 'Team Member',
                'description' => 'Does the work: sees their project, updates their tasks, records what happened.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::VIEW_TASKS, self::UPDATE_TASKS,
                    self::VIEW_EVIDENCE, self::RECORD_EVIDENCE,
                ],
            ],
            // ── The nine roles §26's list names that were not seeded ────────────
            // Each is a real, distinct combination somebody actually holds — not a rename of one
            // above it. Where two would have been identical, only one exists: a catalogue of
            // synonyms is a catalogue nobody can choose from.
            'pmo_admin' => [
                'name'        => 'PMO Administrator',
                'description' => 'Runs the method rather than the projects: templates, calendars, approval routing and the register standards.',
                'permissions' => array_values(array_diff(self::ALL, [self::DELETE_TASKS, self::OVERRIDE_DERIVED])),
            ],
            'site_manager' => [
                'name'        => 'Site Manager',
                'description' => 'Runs the site: the diary, instructions, inspections, snags and the people on the ground. No cost.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::UPDATE_PROJECTS,
                    self::VIEW_TASKS, self::CREATE_TASKS, self::UPDATE_TASKS,
                    self::VIEW_EVIDENCE, self::RECORD_EVIDENCE, self::VERIFY_EVIDENCE,
                    self::MANAGE_MEMBERS,
                ],
            ],
            'scrum_master' => [
                'name'        => 'Scrum Master',
                'description' => 'Runs the sprint: backlog order, sprint boundaries and the board. Does not set scope or cost.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::VIEW_TASKS, self::CREATE_TASKS, self::UPDATE_TASKS,
                    self::MANAGE_SCHEDULE, self::VIEW_EVIDENCE, self::RECORD_EVIDENCE,
                ],
            ],
            'product_owner' => [
                'name'        => 'Product Owner',
                'description' => 'Owns what gets built: priority, acceptance and the backlog\'s content. Not how it is scheduled.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::UPDATE_PROJECTS,
                    self::VIEW_TASKS, self::CREATE_TASKS, self::UPDATE_TASKS,
                    self::VIEW_EVIDENCE, self::VERIFY_EVIDENCE, self::VIEW_COSTS,
                ],
            ],
            'engineer' => [
                'name'        => 'Engineer',
                'description' => 'Technical authority: verifies work, signs off inspections and tests, updates the tasks they own.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::VIEW_TASKS, self::UPDATE_TASKS,
                    self::VIEW_EVIDENCE, self::RECORD_EVIDENCE, self::VERIFY_EVIDENCE,
                ],
            ],
            'consultant' => [
                'name'        => 'Consultant',
                'description' => 'Advises and records findings, without control of the plan or the money.',
                'permissions' => array_merge($readOnly, [self::RECORD_EVIDENCE]),
            ],
            'qa_manager' => [
                'name'        => 'QA Manager',
                'description' => 'Owns quality: inspections, non-conformances and their close-out. Verifies rather than delivers.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::VIEW_TASKS, self::UPDATE_TASKS,
                    self::VIEW_EVIDENCE, self::RECORD_EVIDENCE, self::VERIFY_EVIDENCE,
                    self::SUPERSEDE_EVIDENCE,
                ],
            ],
            'hs_officer' => [
                'name'        => 'Health & Safety Officer',
                'description' => 'Records incidents and safety findings on any project, and can stop work by raising them. No delivery control.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::VIEW_TASKS,
                    self::VIEW_EVIDENCE, self::RECORD_EVIDENCE, self::VERIFY_EVIDENCE,
                ],
            ],
            'procurement_officer' => [
                'name'        => 'Procurement Officer',
                'description' => 'Buys for projects: sees the cost position and raises purchases, without changing the plan.',
                'permissions' => array_merge($readOnly, [self::VIEW_COSTS, self::UPDATE_PROJECTS]),
            ],
            'finance' => [
                'name'        => 'Finance',
                'description' => 'Watches the money: full cost visibility and derived-value overrides, no delivery control.',
                'permissions' => array_merge($readOnly, [
                    self::VIEW_COSTS, self::OVERRIDE_DERIVED, self::VERIFY_EVIDENCE,
                ]),
            ],
            'executive' => [
                'name'        => 'Executive',
                'description' => 'Reads across the portfolio, including cost. Changes nothing.',
                'permissions' => array_merge($readOnly, [self::VIEW_COSTS]),
            ],
            'read_only' => [
                'name'        => 'Read Only',
                'description' => 'Internal observer. Sees delivery, not cost.',
                'permissions' => $readOnly,
            ],
            // ── The three that were assignable but resolved to nothing ─────────
            // Offered on the team screen since the module shipped, defined nowhere, so anyone
            // given one held no permission at all. Each is a real job, distinct from the roles
            // above it, and each is written here as what that person actually does.
            'planner' => [
                'name'        => 'Planner',
                'description' => 'Owns the programme: builds and reschedules the plan and the tasks in it. Not the money, not the people.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::VIEW_TASKS, self::CREATE_TASKS, self::UPDATE_TASKS,
                    self::DELETE_TASKS, self::MANAGE_SCHEDULE, self::VIEW_EVIDENCE,
                ],
            ],
            'supervisor' => [
                'name'        => 'Supervisor',
                'description' => 'Runs a crew: assigns and updates their work and records what was done. Cannot reschedule the programme or see cost.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::VIEW_TASKS, self::CREATE_TASKS, self::UPDATE_TASKS,
                    self::VIEW_EVIDENCE, self::RECORD_EVIDENCE, self::MANAGE_MEMBERS,
                ],
            ],
            'observer' => [
                'name'        => 'Observer',
                // Deliberately the same grant as Read Only. The two are one permission set with two
                // names, kept apart because "Observer" is the word used for someone attached to a
                // single project rather than reading across the portfolio.
                'description' => 'Watches one project. Sees delivery and evidence, changes nothing, and never sees cost.',
                'permissions' => $readOnly,
            ],
            'client' => [
                'name'        => 'Client',
                'description' => 'External customer. Sees progress on their own project and nothing about our costs or margin.',
                'permissions' => $readOnly,
            ],
            'contractor' => [
                'name'        => 'Contractor',
                'description' => 'External party doing work. Updates their tasks and records evidence; never sees cost or margin.',
                'permissions' => [
                    self::VIEW_PROJECTS, self::VIEW_TASKS, self::UPDATE_TASKS,
                    self::VIEW_EVIDENCE, self::RECORD_EVIDENCE,
                ],
            ],
        ];
    }

    /** Roles whose holders are outside the business and must never see internal money. */
    public const EXTERNAL_ROLES = ['client', 'contractor'];

    public static function isExternal(?string $roleKey): bool
    {
        return $roleKey !== null && in_array($roleKey, self::EXTERNAL_ROLES, true);
    }
}
