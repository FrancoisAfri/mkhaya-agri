# Architecture Decision Records — Project Management

Decisions that must hold for the whole Project Management module (Operations). Each one was
raised in the delivery brief as "lock before Sprint 1"; each is recorded here with the decision
actually taken **in this codebase**, which is not always the brief's opening recommendation.

The brief was written for a greenfield service. Mkhaya Hub is not greenfield: it already enforces
row-level multi-tenancy on ~942 tables, has a module/ribbon entitlement system, a Spatie activity
log, and a 12-locale i18n catalogue. Where the brief's recommendation would have created a second,
parallel version of something the platform already does, the ADR adopts the platform mechanism and
records why. The brief's *intent* is preserved in every case — only the mechanism differs.

| ADR | Subject | Decision |
|-----|---------|----------|
| [PM-ADR-001](PM-ADR-001-tenancy-isolation.md) | Tenancy isolation | `company_id` + existing FORCE RLS, not a new `tenant_id` |
| [PM-ADR-002](PM-ADR-002-evidence-ledger-storage.md) | Evidence Ledger storage | Append-only, monthly range partitions, hash-chained per project |
| [PM-ADR-003](PM-ADR-003-id-strategy.md) | ID strategy | `bigint` PK internally, UUIDv7 `uuid` column as the public ID |
| [PM-ADR-004](PM-ADR-004-offline-id-generation.md) | Offline ID generation | Client-generated UUIDv7 accepted verbatim; server never remaps |
| [PM-ADR-005](PM-ADR-005-scheduling-engine-placement.md) | Scheduling engine | In-process pure library, no DB access inside the algorithm |
| [PM-ADR-006](PM-ADR-006-money-type.md) | Money type | `numeric(18,2)` + explicit currency, matching Finance |
| [PM-ADR-007](PM-ADR-007-soft-delete.md) | Soft delete | `deleted_at` everywhere; no hard deletes on project data |
| [PM-ADR-008](PM-ADR-008-event-transport.md) | Event transport | Transactional outbox drained by a scheduled worker |
| [PM-ADR-009](PM-ADR-009-recurring-tasks.md) | Recurring tasks | Occurrences materialised a horizon ahead, not computed on read |
| [PM-ADR-010](PM-ADR-010-derivation-precedence.md) | Derived figures | Strongest evidence wins outright; never averaged, never most-recent |
| [PM-ADR-011](PM-ADR-011-offline-field-loop.md) | Offline field loop | Renders from IndexedDB; writes conflict-free by construction; keys generated at queue time |
| [PM-ADR-012](PM-ADR-012-document-bytes-in-the-database.md) | Document storage | Bytes live in a tenant-scoped table, never on a disk; reads are streamed, not linked |
| [PM-ADR-013](PM-ADR-013-approval-routing.md) | Approval routing | Designed per tenant; no flow still routes; nothing is ever auto-approved by time |
| [PM-ADR-014](PM-ADR-014-integration-links.md) | Integrations | Links are explicit, never inferred; writes go through the owning module; the page says live/ready/off |

Status values: **Accepted** (in force), **Superseded by PM-ADR-xxx**, **Deprecated**. Never edit an
accepted ADR's decision — supersede it with a new one, exactly as ticket IDs are superseded rather
than renumbered.
