# PM-ADR-013 — Approval routing is designed per tenant, and never approves anything itself

**Status:** Accepted · **Date:** 2026-08-08 · **Backlog:** EPIC-090, PM-1022, PM-1024

## Context

"Submit for approval" set a status and stopped. Hours moved to `submitted` and waited for whoever
next opened the Delivery page. Nobody was notified, nobody was accountable for clearing the queue,
and there was no record of who decided what — which is not an approval process, and is not one that
survives being described to an auditor.

The obvious fix — a single hard-coded rule, "the project manager approves" — fails on contact with
real companies. A two-person contractor wants the owner to see everything. A construction group
wants site supervisor → contracts manager → finance above R50,000 and nothing at all below it. One
rule means most tenants work around it, and a control people work around is worse than none because
it looks like one.

## Decision

**Flows are configuration.** `pm_approval_flows` + `pm_approval_steps` per tenant and subject type
(timesheet, expense, document, project stage), with an optional amount threshold and an optional
narrowing to one project. A project-specific flow beats the tenant-wide one, because somebody wrote
it precisely for that job.

**Approvers are named by rule, not by person.** `role`, `pm_role`, `user`, `manager`. "Whoever holds
Finance Manager" survives a resignation; "Thandi" does not, and a flow that stops working the week
somebody leaves is a flow that gets bypassed rather than fixed. `user` exists for the company that
genuinely wants one name.

**No flow still means routing.** With nothing designed, timesheets and expenses go to whoever may
update the project, and those people are notified. The alternative — treating "no flow" as "no
approval needed" — would have meant switching this engine on silently auto-approved every timesheet
on every tenant that had never opened the designer, which is a change in the direction of *less*
control that nobody asked for.

**A threshold is the only thing that skips the queue.** Below it, the subject is approved on
submission. Above it, or with no threshold set, it queues. A null amount always routes: "we do not
know what this costs" is not a reason to skip the person whose job is to ask.

**Nothing is ever auto-approved by the passage of time.** `escalate_after_hours` re-notifies the
same approvers and stamps `escalated_at`. It does not reassign and it does not decide. Approving
something because nobody looked at it would defeat the entire point of having asked.

## Separation of duties is the default

The person who submitted cannot approve their own step unless the step was configured to allow it.
The switch exists because a two-person business needs it; the default is off because a rule that has
to be remembered is not a rule. One authorisation predicate — `ApprovalService::canAct()` — decides
who may act, who appears in the inbox, and who is notified, so nobody is ever told about a decision
they cannot make.

A step may require several signatures. `min_approvals` counts *distinct people*, enforced by a
partial unique index on `(request, position, user)` — a two-signature rule that one person could
satisfy by clicking twice is not a two-signature rule.

## The decision and the thing decided move together

`pm_approval_actions` has no soft delete and nothing that updates it: its whole value is that it
cannot be quietly edited afterwards, and `actor_name` is stamped at the time so the trail still
reads after an account is deactivated.

When the ladder is fully climbed, the controller applies the decision to the subject in the same
request. A request that says "approved" while the timesheet still says "submitted" is two systems
disagreeing about one fact, and the cost report would be built from whichever one it happened to
read. An *intermediate* signature deliberately changes nothing about the subject — it approves that
rung, not the thing.

## What this does not do

It does not replace the platform's own approval features in other modules. PM routes PM's
submissions. When Accounting is installed, an approved expense still goes to its `ExpenseSink` and
is posted under that module's rules — this decides who says yes, not where the money lands.
