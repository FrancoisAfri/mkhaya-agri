# Workflow & Automation — administration and deployment

For developers and administrators who deploy and run the engine. Architecture and the gap assessment are
in `01-gap-assessment-and-architecture.md`.

## What ships

| Migration | Creates |
|---|---|
| `workflow/2026_11_10_100000_workflow_engine_foundation` | settings, calendars, forms (+versions), definitions, versions, instances, node runs, tasks, assignment history, timers, action runs, comments, delegations, drafts, the event outbox, and append-only hash-chained audit events (UPDATE/DELETE refused by a trigger). Every table has `company_id` under the platform's `tenant_isolation` row-level-security policy. |
| `workflow/2026_11_10_100100_register_workflow_module` | module, ribbons, `workflow.*` permissions, seven starter roles, sellable add-on (via `WorkflowModuleSeeder`). |

Code: `app/Modules/Workflow` (Services, Support, Providers, Http/Controllers, Models), routes in
`routes/modules/workflow.php` (prefix `workflow`, names `workflow.*`), screens in
`resources/js/Modules/Workflow`.

## Templates

Templates are code (`Support/TemplateLibrary.php` and `TemplateLibraryMore.php`), not seeded rows, so every
deployment has all of them with no seeder to run. Installing one copies it into the company being worked in
(the switched-into company, else the user's company, else the only company on a standalone install) as a
draft with its own forms, and adds its category to the company's list. A platform administrator with no
company is told to switch into one. `WorkflowTemplateLibraryTest` installs, validates and publishes every
template.

The form library works the same way (`Support/FormLibrary.php`, 46 standalone forms plus the forms the
templates carry). Adding a form copies it into the company. `WorkflowFormLibraryTest` checks every one. After
deploying, run `php artisan hubs:sync workflow` so the Design hub shows the Form library card.

## Deploying

The normal deploy runs `migrate`. Then, on the server:

```bash
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
$PHP artisan migrate --force
$PHP artisan hubs:sync workflow
$PHP artisan cache:clear
```

The front end needs the new packages `@vue-flow/minimap`, `@vue-flow/controls`, `@vue-flow/background` and
`@dagrejs/dagre` (in `package.json`); the CI deploy (`npm ci`, `npm run build`, then upload of `public/build`) installs them.

Then, per tenant: enable the `workflow` add-on and give people the workflow roles (Roles & permissions).

## Scheduled work

`workflow:tick` runs every minute (`routes/console.php`, `withoutOverlapping`, log
`storage/logs/workflow-tick.log`). Production has no queue worker, so the engine never needs one: every
change commits in the request that causes it, and anything due later is a database row the tick picks up.
Each run:

1. fires due timers: waits, SLA warnings, SLA breaches (each escalates once) and reminders;
2. retries automated actions that are due (backoff 2, 4, 8… minutes, up to the step's limit);
3. starts scheduled workflows (one per day, week or month; de-duplicated by period);
4. delivers the event outbox (`workflow.*` events) to listeners, retrying up to five times.

Each row is claimed before it is handled and runs under its own tenant context, so overlapping or repeated
runs cannot fire anything twice. **Administration → Engine health** shows the last tick and anything waiting
or failed. A worker can be added later without changing anything: the same tables feed it.

## Company settings

**Workflow → Administration** (`workflow.admin`). Every change needs a reason and is audited.

| Setting | Default | Meaning |
|---|---|---|
| Workflow categories | Finance, Procurement, HR, IT, Legal, Operations, SHEQ, Warehouse, Governance, Sales, General | The list a workflow's category is chosen from (and delegations are scoped by). Templates and imports add theirs; a category in use cannot be removed. |
| Publication review | on | A second person with `workflow.publish` approves a version before it is published. |
| Self-approval | never | `node_decides` lets a step allow it explicitly; otherwise nobody approves their own request. |
| No assignee found | process owner | Or the exception queue (everyone with `workflow.manage`). |
| Deactivated person's tasks | line manager | Or the process owner, or the exception queue. Triggered whenever a user is disabled or deleted. |
| Default task SLA | none | Hours, when a step sets none. |
| Permitted actions | none | Actions that change a business record (`mark_record`) must be listed here, and need an approval before them. |
| Modules on the engine | off | Per module: claims, leave, purchase requisitions. Needs a published workflow for that module first. |
| AI | on / no data | Whether AI assistance is offered, and whether it may read request content (never for confidential workflows). |
| Limits, retention, email | 150 steps, keep, on | |

**Business calendars**: working days and hours, time zone and company closure days. South African public
holidays, with Monday substitutes, are built in. Editing a calendar makes a new version; deadlines already
set keep the one they were set with.

## Permissions

| Permission | Allows |
|---|---|
| `workflow.view` | My Work, own requests, delegations, the requests list (own and participated) |
| `workflow.start` | starting manual requests |
| `workflow.design` | workflows, forms, templates, import/export, drafts and submission |
| `workflow.publish` | reviewing and publishing versions, rollback, suspend/retire |
| `workflow.manage` | all requests (except confidential ones), reassignment, cancellation, exceptions, migration |
| `workflow.reports` | dashboards, reports and exports |
| `workflow.audit` | the audit trail, chain verification and export |
| `workflow.admin` | company settings and calendars |

Deciding a task needs no permission: being its assignee, a claimant of its queue, or an active delegate is the
grant. Starter roles: Workflow Administrator, Designer, Publisher, Process Manager, Auditor, Executive, User.

## Integrating a module (PRS §13)

A module implements `App\Modules\Workflow\Contracts\WorkflowProvider` and is listed in
`ProviderRegistry::PROVIDERS`:

- `fields()`/`snapshot()`: the record fields a workflow may read (nothing else is read);
- `onOutcome($record, approved|rejected|cancelled, $actorId, $comment)`: applies the outcome through the
  module's own code. It must be idempotent: it is retried until it succeeds, and the request shows as
  finished only after it has;
- `canView()`: the module's own access rule, used for the record link.

In the module's submit method:

```php
$workflows = app(\App\Modules\Workflow\Services\Workflows::class);
if ($workflows->handles('finance.claims', $companyId)) {
    $workflows->emit('finance.claims.submitted', 'finance.claims', $claim->uuid, $claim->employee_id, [], 'claim:' . $claim->uuid);
    return; // the workflow decides; the module's own approval chain is not built
}
```

Shipped integrations:

| Module | Submit hook | Outcome |
|---|---|---|
| Claims | `ClaimsService::submitClaim` | `ClaimsService::finaliseByWorkflow`: approved claims join the payment queue |
| Leave | `LeaveRequestService::submit` | `LeaveRequestService::finaliseByWorkflow`: consumes the reservation (or credits overtime), syncs T&A; rejection releases it |
| Procurement | `PurchaseRequestService::submit` | `ProcurementApprovalService::finaliseByWorkflow`: reserves the budget on approval |

The module's own approval engines keep working for companies that have not switched.

## Events

Published to `workflow_events` inside the change's transaction and delivered by the tick as the Laravel
event `App\Modules\Workflow\Events\WorkflowEventOccurred` (name, payload, correlation id):
`workflow.instance.started|completed|rejected|cancelled|failed`, `workflow.task.assigned|completed`,
`workflow.approval.approved|rejected`, `workflow.sla.warning|breached`, `workflow.action.failed`.
Payloads carry identifiers only. Delivery is at least once, so listeners must be idempotent.

## Security notes

- Tenant isolation: row-level security on every table plus the company scope; cross-tenant requests resolve to
  nothing (tested).
- Every action checks its permission in the controller; task actions check assignment. Privileged refusals,
  self-approval attempts and repeat approvers are written to the audit trail as `denied`.
- Conditions are data, never code: a fixed operator set, named patterns instead of user regular expressions,
  and a nesting limit. Designers choose actions from a catalogue; there is no free-form code, SQL or HTTP.
- Files go through Document Management (type guard, malware scan, quotas, encryption at rest). A form can only
  link documents its submitter uploaded.
- Exports neutralise spreadsheet formulas; exports and audit views are themselves audited.
- AI never decides, publishes or names people; drafts are sanitised against real roles, steps and actions. It
  is metered through the platform's AI quota, and every feature has a non-AI fallback.

## Tests

`php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Workflow`: the PRS §24 acceptance matrix
(WF-001 to WF-020), module integrations (claims, leave, purchase requisitions), route-by-route access
refusal, cross-tenant isolation, and the AI draft safeguards.

## Known limitations

- Outbound webhooks, a public versioned REST API and service accounts (PRS §21, Phase 3) are not built yet.
  Modules integrate in-process through the provider contract.
- Notifications are in-app (written with the step) and email (sent after the response, so a slow mail server never holds
  up a decision; this relies on PHP-FPM/LiteSpeed finishing the response first, as on the cPanel host; under
  `php artisan serve` the wait is still felt). SMS, push and Teams channels are not wired to workflows yet.
- Notification wording is built in; per-company notification templates are not editable yet.
- Cost and effort metrics are not shown, because the engine holds no reliable cost data (PRS §14 allows
  this).
- Load and concurrency targets (PRS §20) have not been load-tested. Query counts were profiled; see the
  test report.
