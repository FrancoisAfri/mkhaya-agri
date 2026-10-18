# PM-ADR-014 — Integrations are explicit links, and the page says which ones are real

**Status:** Accepted · **Date:** 2026-08-08 · **Backlog:** PM-1041…1045, EPIC-020

## Context

The module registry and the ten provider seams were built early (PM-1041) and then largely left
unfilled. Two capabilities had integrated implementations; the other fifteen resolved to PM-local
stand-ins while the setup page printed "Available" beside all seventeen.

That is worse than having no integrations page. A tenant reading it learned that `finance.budgets`
was available and concluded a Finance budget was feeding the module. Nothing was.

A second problem sat underneath: none of `assets`, `contracts`, `purchase_orders`, `hd_tickets`,
`budgets` or `fin_invoices` carries a project column, and none of them should. They belong to other
modules. Without somewhere to record the association, "integration" could only ever mean PM
rendering another module's list — which is a page, not an integration.

## Decision

**Links are explicit and live on PM's side.** `pm_external_refs` with `local_type = 'project_link'`
holds the association: source module, entity, id, and a snapshotted `display_name`. A person says
*this* excavator is on *this* job; PM records it; the providers read it back.

Nothing is inferred. No matching on supplier and date, no "same month, similar amount". A guessed
link would attribute another job's steel to this project's committed cost, and the person reading
the figure would have no way to know which parts of it were guessed.

**Other modules' tables are read defensively, through `ModuleTable`.** A tenant can hold a module by
entitlement while the deployment never ran its migrations, and another team can rename a column.
Both degrade to "integration unavailable" — visible on the setup page — rather than to a stack trace
on a page that has nothing to do with that module. Tenancy is *not* re-checked: every one of these
tables carries `company_id` under FORCE RLS, and a second weaker copy of that rule in PHP would be
the thing that eventually disagrees with it.

**Writes go through the owning module's own front door.** Approved costs reach the ledger as a
`FinancialEvent` through `FinancePoster` — the same shape and the same outbox a payroll run uses —
and approved hours are published on PM's outbox for Payroll to consume. PM never inserts into
`payroll_earnings_lines` or `fin_journal_lines` directly: that would be writing into the middle of
somebody else's process, past their validation, their approval chain and their audit trail.

**A failed posting never unwinds an operational decision.** A cost is approved by a person on a
project screen. Whether the ledger accepted the journal is Finance's problem; it is logged and the
cost keeps its reference, and the approval stands.

## The setup page has three states, not two

| State | Meaning |
|---|---|
| **live** | The module is available *and* PM consumes it |
| **ready** | The module is available; PM's side is not built |
| **off** | The module is not available to this tenant |

`CapabilityCatalogue` also records, per capability, the PM routes that consume it — so the page
answers "where is this used?" with a link rather than a claim.

`built` is hand-maintained, which makes a stale `true` the dangerous failure: it would tell somebody
a number is fed by a module that is not feeding it. `IntegrationsAndStorageTest` therefore asserts
that every capability flagged built resolves to a real integrated implementation through
`ProviderRegistry` when its module is on. The flag cannot drift by anybody simply forgetting.

## What each integration changed

Not one of these is a list on a page; each changes a number or refuses an action:

- **hr.leave** — assigning work to somebody on approved leave is refused
- **lms.competencies** — assigning work to somebody with a *lapsed* competency is refused; having
  none recorded is not treated as unqualified, or the module would be unusable on day one
- **hr.labour_rates** — an hour is costed at the person's real rate, derived from payroll over the
  BCEA ordinary month, and left uncosted rather than guessed when there is no figure
- **hr.attendance** — attended hours shown beside booked hours, because the two disagreeing is the
  signal
- **finance.budgets** — Finance's budget wins over the project's own field
- **finance.invoices** — paid supplier invoices count as actual cost
- **finance.postings** — approved costs post to the general ledger
- **procurement.orders** — issued orders count as committed cost, so the budget stops looking intact
  until the month the invoices land
- **crm.opportunities** — the won deal behind the project, on the project page

## Cost

Reading another module's tables couples PM to their column names. `ModuleTable` bounds the blast
radius to "this capability goes quiet", and each provider names the columns it needs. The
alternative — an event bus between every pair of modules — is a larger system than this platform
has, and would answer questions like "what is this asset's tag" asynchronously, which is the wrong
shape for a picker.
