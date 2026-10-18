# PM-ADR-010 — Derived figures use precedence, never a blend

**Status:** Accepted · **Date:** 2026-08-07 · **Backlog:** PM-1200, PM-1201, PM-1203

## Context

Several kinds of evidence can speak to the same number. A task's progress might be touched by a
verified milestone, a recorded completion, a measured quantity, and a percentage somebody typed into
a phone — all in the same week, all disagreeing.

Something has to decide what the task's progress *is*.

## Decision

Rules are ordered by how much the claim is worth, and **the strongest rule with any evidence behind
it wins outright**. No averaging, no weighting, no most-recent-wins.

```
1. Verified    someone independent checked it        milestone.verified
2. Definitive  recorded as finished or begun         task.completed, task.started
3. Measured    a quantity somebody counted           quantity.installed ÷ planned_quantity
4. Claimed     a person typed a percentage           progress.reported
```

The rules live in `Support\Derivation\DerivationRules` as data, not code, because they are the
answer to "why does this task say 62%?" and that answer has to be readable by a quantity surveyor
arguing about a payment certificate. The Figures page renders the same table.

## Why not blend

**Averaging is gameable.** If a measured 40% and a claimed 80% average to 60%, then anyone who wants
a better number types a bigger one. The measurement is evidence; the optimism is not, and splitting
the difference treats them as equals.

**Recency is not authority.** Letting the latest event win means a measurement taken on Monday is
undone by a guess typed on Tuesday. Progress would go up and down with who last opened the app.

**A blended number cannot be explained.** The drill-down lists the events a figure was built from. A
weighted combination of four sources has no honest answer to "which events produced this?" — and
without that answer the ledger is decoration.

## Consequences

**A rule that matches but cannot compute falls through.** `quantity.installed` with no
`planned_quantity` to measure against produces nothing, and the next-weakest rule answers. Reporting
`null` there would throw away a usable figure; that is why `planned_quantity` was added to
`pm_tasks` in the same change — without it the measured rule could never fire and every project
would silently fall back to what someone typed.

**Confidence is surfaced, not hidden.** A figure carries the rule's confidence reduced by the least
confident event behind it. A 0.6 on a self-reported percentage tells a reader exactly how much
weight to give it, which is more useful than a number that pretends to be certain.

**Overrides sit on top, never underneath.** A person may state a different number, with a reason of
at least a sentence and their name attached. The derived value and its evidence stay in the row
beneath: recomputation refreshes the trail but never overwrites the decision, and clearing the
override lets the evidence speak again.

**Method version travels with every figure.** `DerivationRules::VERSION` moves on *any* change to
the rules, including a confidence figure. Old rows keep the version that produced them, so a change
in method never silently reinterprets numbers that were reported to a client.

## Derivation does not run inside ingestion

A shift's capture is 500 records with a two-second budget (PM-1003). Deriving on every append would
put the cost of reading onto the one path that must stay fast — a bakkie reaching signal would time
out. Figures are brought up to date by `pm:derive-figures` nightly, and on demand from the project's
own page.

The trade is staleness between an event landing and the figure moving. `pm:derive-figures
--reconcile` runs first each night and reports any figure that had drifted from what the evidence
says, so the gap is measured rather than assumed. Drift is not treated as routine: it means evidence
arrived without derivation catching up, or that the rules changed, and both are worth an audit row.
