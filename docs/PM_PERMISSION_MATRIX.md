# Project Management — permission matrix

The nine built-in roles (PM-1021) and what each one grants. Generated from
`App\Modules\Operations\ProjectManagement\Support\RoleCatalog`, which is the single source of truth
for the roles, the Spatie permission seeding and the policies — change it there and this table is
what you should regenerate, not edit.

Every tenant gets its own copy of these roles in `pm_roles` (`pm:provision-roles`), so a tenant can
clone and edit one without affecting anyone else (PM-1022).

## How a decision is made

`PermissionEvaluator` answers every "may this person do this?" in the module (PM-1020), in this
order:

1. Platform and master admins pass.
2. An explicit **deny** in `pm_permission_overrides` — for this project or globally — refuses,
   whatever else grants it.
3. An explicit **allow** grants.
4. Otherwise: the **union** of the tenant-wide grant (Spatie) and the role the user holds on this
   project (`pm_project_members` → `pm_roles`). Most permissive wins (PM-1023).

A permission asked without a project can only be answered by a tenant-wide grant — a project role
says nothing about projects the user is not on. This is what makes "a user with project-scoped
access cannot list projects outside their scope" true of the query itself (`Project::visibleTo()`),
not just of one screen.

## The matrix

| Permission | Portfolio Manager | Project Manager | Coordinator | Team Member | Finance | Executive | Read Only | Client | Contractor |
|---|---|---|---|---|---|---|---|---|---|
| `pm.projects.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `pm.projects.create` | ✓ | ✓ | · | · | · | · | · | · | · |
| `pm.projects.update` | ✓ | ✓ | · | · | · | · | · | · | · |
| `pm.projects.delete` | ✓ | · | · | · | · | · | · | · | · |
| `pm.projects.manage` | ✓ | ✓ | · | · | · | · | · | · | · |
| `pm.tasks.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `pm.tasks.create` | ✓ | ✓ | ✓ | · | · | · | · | · | · |
| `pm.tasks.update` | ✓ | ✓ | ✓ | ✓ | · | · | · | · | ✓ |
| `pm.tasks.delete` | ✓ | ✓ | · | · | · | · | · | · | · |
| `pm.schedule.manage` | ✓ | ✓ | · | · | · | · | · | · | · |
| `pm.evidence.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `pm.evidence.record` | ✓ | ✓ | ✓ | ✓ | · | · | · | · | ✓ |
| `pm.evidence.supersede` | ✓ | ✓ | · | · | · | · | · | · | · |
| `pm.evidence.verify` | ✓ | · | · | · | ✓ | · | · | · | · |
| `pm.derived.override` | ✓ | · | · | · | ✓ | · | · | · | · |
| `pm.settings.manage` | · | · | · | · | · | · | · | · | · |
| `pm.costs.view` | ✓ | ✓ | ✓ | · | ✓ | ✓ | · | · | · |
| `pm.members.manage` | ✓ | ✓ | · | · | · | · | · | · | · |

## Notes on the shape of it

**`pm.costs.view` is why Client and Contractor exist as separate roles.** Both may see progress and
their own work; neither may see what the job costs or what we make on it. That is enforced in
`ProjectPresenter::redactFor()` — where the payload is built — so an external party calling the API
directly gets the same answer as one looking at a screen. Hiding a tab would not be enforcement.

**`pm.settings.manage` is held by no built-in role.** Working calendars and the integration surface
change how every project schedules, so the grant is deliberately left for a tenant to assign to
whoever administers the module, rather than arriving switched on for a role they may hand out
freely.

**`pm.projects.delete` is Portfolio Manager only.** A Project Manager runs their project but does
not archive it; that is a portfolio decision. Deletion is a soft delete and reversible
(PM-ADR-007), but it removes the project from every list and every rollup.

**`pm.evidence.verify` and `pm.derived.override` sit with Finance, not delivery.** Verifying the
hash chain and overriding a derived figure are both assurance acts: the people who report the
numbers should not be the only ones who can check or restate them.
