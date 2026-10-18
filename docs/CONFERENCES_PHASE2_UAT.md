# Conferences Phase 2 — Testing & UAT (Task 2.10)

Phase 2 adds paid ticketing, payments, invoicing, refunds, finance reporting, QR
check-in, virtual meetings, the event hub and engagement tools. This document
covers the automated suite plus the manual UAT script that ends in a paid pilot
and a live hybrid session.

## 1. Automated tests

PHPUnit feature tests live in `tests/Unit/Conferences/Phase2/` — one file per
service, covering every Phase 2 acceptance criterion:

| File | Task | Key assertions |
|---|---|---|
| `TicketServiceTest` | 2.1 | availability (window/audience/quantity), promo %/fixed/floor |
| `PaymentServiceTest` | 2.2 | transactional confirm, **webhook replay doesn't double-count**, Stripe signature |
| `InvoiceServiceTest` | 2.3 | 15% VAT breakdown, sequential numbers, PO flow, immutability |
| `RefundServiceTest` | 2.4 | policy amounts (full/partial/none), same-gateway refund, **over-refund rejected** |
| `FinancialReportServiceTest` | 2.5 | net = gross − refunds per currency, reconciliation CSV balances |
| `CheckInServiceTest` | 2.6 | valid scan, duplicate, cancelled, manual, badge PDF |
| `VirtualAndEngagementTest` | 2.7–2.9 | identity-bound join links, host-only, recording embargo, moderation, one-vote |

> **Run in CI / an isolated test DB.** `phpunit.xml` points `DB_DATABASE` at the
> dev database, so `RefreshDatabase` would wipe it locally — run these in CI or
> against `mkhaya_hub_testing`. The same logic is already verified here via
> rolled-back transactions. The project uses **PHPUnit** (not Pest); Laravel
> **Dusk** is not installed, so browser E2E is out of scope for this suite.

```bash
# CI / isolated DB only
php artisan test tests/Unit/Conferences/Phase2
```

## 2. Sandbox payment matrix (needs gateway sandbox credentials)

Configure each gateway under **Conferences → Payment Gateways** (sandbox keys),
then for each gateway run:

- [ ] **Success** — pay a paid ticket; the **webhook** flips the registration to
      confirmed; a receipt PDF with the correct VAT is generated.
- [ ] **Failure / abandon** — close the gateway tab; the registration stays
      pending; after 30 min `conferences:release-abandoned` frees the held seat.
- [ ] **Webhook replay** — re-deliver the same webhook; no double-confirm and
      `quantity_sold` does not increment twice.
- [ ] **Refund** — cancel within the full-refund window; the money returns via
      the same gateway and the payment shows `refunded` / `partially_refunded`.
- [ ] **Multi-currency** — a ZAR ticket is charged in ZAR (no conversion).

Gateways: **Stripe** (card/international), **PayFast** (local/EFT). Peach is
stubbed pending implementation. Live provisioning of virtual meetings (Zoom/
Teams/Meet) likewise needs each provider's OAuth credentials.

## 3. Live hybrid session dry-run

- [ ] Mark sessions virtual/hybrid and **provision** a meeting (Zoom/Teams/Meet).
- [ ] Each online attendee receives a **distinct personal join link**; a link
      used by a **different logged-in user is rejected**.
- [ ] The **host URL** is visible only to the session's speaker.
- [ ] The **hub** shows the live session with a working join link; switching into
      a parallel registered session works; recordings appear after `available_from`.
- [ ] **Engagement** (needs Reverb running, `BROADCAST_DRIVER=reverb`): chat is
      real-time; a question is hidden until a moderator approves it; a poll takes
      one vote per attendee and closing it stops votes.
- [ ] **Check-in** on real devices: QR scan marks present; duplicate shows
      "already checked in"; manual search works; badges print.

## 4. Sign-off

- [ ] Paid pilot conference processed real sandbox payments end-to-end with
      correct invoices.
- [ ] Live hybrid session ran with working join links, hub and live Q&A/polls;
      no critical or high-severity bugs open.
- [ ] **UAT sign-off** recorded from a business stakeholder.
- [ ] **Finance sign-off** recorded (reconciliation CSV balances against the
      gateway statements).

---
*Realtime engagement requires Laravel Reverb running; scheduled jobs (abandoned-
checkout release, reminders) require the queue worker + scheduler.*
