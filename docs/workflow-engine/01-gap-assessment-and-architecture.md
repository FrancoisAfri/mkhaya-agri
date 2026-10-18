# Workflow Management & Automation Engine: gap assessment and architecture

Source: *Mkhaya Hub — Workflow Management & Automation Engine, PRS v1.0, October 2026*.
This maps the specification against the codebase as it stands (October 2026) and proposes the architecture and
Phase 1 scope. It is the "map the requirements against the existing codebase" step that §27 of the PRS asks for.

## 1. What already exists

### 1.1 Roughly twenty separate approval engines

Every module that needed approvals built its own. None is shared.

| Module | Engine | Notable strengths | Gaps |
|---|---|---|---|
| Procurement Governance | `ApprovalRouter`, `RulesEngine`, `ConditionMatcher`, `CustomExpressionHandler` | Six resolver types (user, role, permission, unit manager, budget owner, committee chair); quorum; value bands with specificity; structured expression tree (`all/any/not`, 13 operators, depth-limited, never executes strings); hash-chained `AuditLedger` | Delegation columns unused; per-step escalation target not honoured |
| SHEQ | `Approvals/ApprovalService`, `ApproverResolver` | Workflow **snapshot frozen per request**; one decision per person per step; signatures; line manager scoped by company; no workflow means blocked, not auto-approved | Sequential only |
| Budget / Project Management | `BudgetApprovalService`, PM `ApprovalService` (near copies) | `allow_self_approval` per step; scoped delegation (subject type, max amount) that adds an approver instead of transferring rights; one live request per subject | **Bug: reads `hr_people.line_manager_id`, which does not exist; a `manager` step fails on PostgreSQL** |
| Document Management | `ApprovalService` | All/any quorum; approval pinned to a document version; per-assignment delegation | |
| Leave | `LeaveApprovalService`, `ApproverDelegationService` | Parallel groups; delegation with circular-delegation prevention; SLA escalation | Fixed levels; no company scoping in the resolver |
| Claims, Fleet, Contracts, Warehouse, Manufacturing, Assets | per module | Amount-based matrices (`amount_min/max`, `min_amount`, `max_amount`, `levels[].min_value`) | Each has its own matrix shape |
| CRM / HelpDesk automations | `CrmWorkflowEngine`, `HdAutomationEngine` | Trigger, then conditions, then actions; visual graph on vue-flow; flow runs with current node | Not approval-aware; actions not permission-gated per designer |
| Recruitment, Finance shared `ApprovalWorkflowService` | configuration only | | **Never run**: cheap to replace |

"Line manager" is resolved separately in six places, from `hr_people.manager_id`, which holds an `hr_people.id` and not a user id.
Department head comes from `company_unit_members.is_head` (Leave) or `company_units.user_id` (procgov).

### 1.2 Platform infrastructure

| Need (PRS §) | Exists | Where | Verdict |
|---|---|---|---|
| Tenant isolation (§3, §18) | Yes | `CurrentCompany`, `TenantContext`, `BelongsToCompany`, RLS `tenant_isolation` (inline SQL per migration), job payload stamping | Reuse. Add a small RLS migration helper |
| Module registration, hubs, permissions (§3) | Yes | `ModuleHubs`/`HubRegistry`/`hubs:sync`, `module.active`/`module.tier`, Spatie, Tax seeder pattern | Reuse (Tax pattern) |
| Notifications (§11) | Partly | `['database','mail']`, `FaultTolerantMailChannel`, `SyncInAppChannelManager` (bell works without a worker), `SmsService`, Teams only in HelpDesk | Reuse delivery; **build** templates, preferences, digests, suppression |
| Scheduler / durable execution (§19) | Partly | `RunsPerTenant`, `--sync` convention, per-module outboxes (Tax, SHEQ, PM) | **Production has no queue worker.** Build a platform outbox and a scheduler-driven engine tick |
| Audit (§18) | Partly | Spatie activity log (general); hash-chained ledgers per module (Tax, procgov, WMS, PM) | Build `workflow_audit_events` on the TaxAudit pattern (hash chain, append-only trigger, RLS) |
| Attachments (§6, §18) | Yes | `DocumentService::store` + `HasDocuments` (type guard, ClamAV, quotas, encryption) | Reuse |
| AI (§15) | Yes | `BaseModuleAI`, `AgentRegistry`, `ai_usage_log`, `AiQuota` (callers must enforce) | Reuse: `WorkflowAI extends BaseModuleAI` |
| Business calendars (§10) | Yes | `WorkingCalendar`, `SouthAfricanHolidays`, `public_holidays`, HelpDesk `BusinessHoursCalculator` | Reuse for SLA due dates |
| Delegation (§8.4) | Per module only | 7 tables, 2 services | **Build** platform `workflow_delegations` |
| Org structure (§8.1) | Yes, fragmented | `hr_people.manager_id`, divisions 1–5 (`manager_id`), `company_units` | Build one `AssigneeResolver` |
| User deactivation (§3, §17) | No hook | `OffboardingFinalizeService`, SCIM, `users.status` | **Build** a `UserDeactivated` event and fire it from both |
| Form builder (§6) | No | Survey builder (conditions), CRM custom fields, DMS category fields | **Build** a versioned form schema and renderer |
| API, webhooks, service accounts (§21) | Partly | Sanctum, `ResolveCompanyFromToken`, problem+json + idempotency in PM/SHEQ, per-module HMAC webhook dispatchers | Phase 3: versioned `/api/v1/workflow`, system actor |
| Visual designer (§4) | Partly | `@vue-flow/core` 1.48.2 (CRM `FlowCanvas.vue` pattern) | Add `@vue-flow/minimap`, `controls`, `background`, `dagre` |
| Charts (§14) | Yes | apexcharts | Reuse |

## 2. Architecture

Three things are kept apart (§27):

1. **Definition**: `workflow_definitions`, then immutable `workflow_versions` (the graph as JSONB plus normalised `workflow_nodes` and `workflow_edges` for querying and validation), and `workflow_forms` / `workflow_form_versions`.
2. **Execution**: `workflow_instances` (pinned to a version), `workflow_node_runs` (every node execution: entered, completed, skipped, cancelled, failed), `workflow_tasks` and `workflow_task_assignments`, `workflow_timers`, `workflow_action_runs`, `workflow_comments`.
3. **Business record**: referenced, never copied: `subject_type` + `subject_id` + a display reference. The owning module validates and changes its own record through registered actions.

### 2.1 Execution without a queue worker

Production (cPanel) has no queue worker. The engine is therefore built so that **every state change commits in the
request that causes it**: submitting a task advances the graph synchronously, through non-blocking nodes, until it reaches the next wait
(a human task, a timer, a join, or an external action).
Anything that must happen later goes in a table and is processed by **`workflow:tick`**, scheduled every minute with
`--sync` semantics (per tenant, `withoutOverlapping`). The tick processes:

- due timers (delays, SLA warnings and breaches, reminders);
- external and integration action runs (with retries, backoff, timeouts and idempotency keys);
- outbox delivery of `workflow.*` events to module listeners.

Durability comes from the database, never from a job in memory. A restart loses nothing: the tick picks up whatever is due.
If a worker is added later, the same tables feed it; nothing is redesigned.

Concurrency: each instance is advanced under `SELECT … FOR UPDATE` on its row. Node runs carry a unique
`(instance_id, node_key, activation)` key, so a duplicate event or a re-delivered tick cannot run a node twice.

### 2.2 Integration contract (§13)

Each module registers a **provider** class, in the same way modules register hubs:

```php
interface WorkflowProvider {
    public function subjects(): array;      // record types it exposes: label, exposed fields (read-only allow-list), reference, URL
    public function triggers(): array;      // events it emits: 'finance.invoice.submitted', with the payload schema
    public function actions(): array;       // validated business actions: 'finance.invoice.mark_approved', with input/output schema, permission, risk
    public function onOutcome(WorkflowOutcome $o): void; // completed, rejected, cancelled or failed, delivered through the outbox
}
```

The engine only reads fields a provider exposes, and only changes records through a provider's actions, so module rules are never bypassed.
Designers can only pick registered actions, so there is no arbitrary code, SQL or HTTP (§12).

### 2.3 Reuse plan

- Conditions: generalise procgov `CustomExpressionHandler` (tree of `all/any/not`, leaf `{field, operator, value}`) and add the PRS operators it lacks: ends_with, not_contains, date before/after, between dates, regex-like `matches` from an allow-listed pattern set.
- Approvals: SHEQ's freeze-at-start, procgov's resolver types and one-person-one-level rule, and Budget's self-approval flag and scoped delegation.
- Audit: TaxAudit pattern, giving `workflow_audit_events` with a hash chain and an append-only trigger.
- Attachments: `DocumentService` + `HasDocuments`.
- SLA: `WorkingCalendar` + `public_holidays`.
- AI: `WorkflowAI extends BaseModuleAI` with `AiQuota::ensureWithinQuota()`.

The existing module engines keep running. Modules move to the central engine one at a time, behind a per-company switch,
so nothing changes for a company until it chooses to switch.

## 3. Proposed phases

Phases follow PRS §25, adjusted for what exists.

**Phase 1: core engine (first milestone)**
- Definitions, versioning (draft, in review, published, suspended, retired), validation with node-level messages, publication approval.
- Visual designer (vue-flow with minimap, controls, auto-layout; node palette for start, end, user task, approval, review, decision, notification, automated action, form).
- Synchronous runner plus `workflow:tick`; node-run log; idempotent starts (`dedupe_key`).
- Assignment: named user, role, department, line manager (one resolver), record owner; an unresolvable assignee goes to an exception queue.
- Approval: approve, reject with reason, return for correction, request information; self-approval prevention; sequential steps.
- Forms v1: core field types, required, conditional visibility, validation, versioned schemas, drafts; attachments through Document Management; comments.
- My Work inbox, in-app and email notifications, hash-chained audit, tenant isolation, permissions.
- First integrations through the provider contract: one process each in Finance, HR and Procurement (to be chosen).

**Phase 2: enterprise automation.** Conditions with full operators, parallel split and join (all, any, quorum), SLA timers with business calendars and escalations, delegation and reassignment, deactivation handling, sub-workflows, templates library (5 standard templates), retries and failure queue, management dashboard.

**Phase 3: advanced orchestration.** Versioned REST API with service accounts, inbound and outbound webhooks, version comparison and explicit migration of running instances, cross-module workflows, analytics and bottlenecks, executive dashboard, export/import of definitions.

**Phase 4: AI.** Natural-language drafting (always a draft), workflow explanation, task summaries, form suggestions, natural-language reporting over permission-filtered data, bottleneck and automation recommendations.

## 4. Decisions (agreed 14 October 2026)

1. **Execution model**: scheduler-driven. State changes commit in the request; `workflow:tick` runs every minute (`--sync`
   semantics) for timers, SLAs, retries and event delivery. A queue worker can be added later without redesign.
2. **First integrations (Phase 1)**: Finance expense claims, HR leave requests, Procurement purchase requisitions, and
   generic form requests (company-designed forms with no module behind them).
3. **Existing module engines**: opt-in migration. Old engines keep working; each integrated module gets a per-company
   switch to route its approvals through the central engine.
