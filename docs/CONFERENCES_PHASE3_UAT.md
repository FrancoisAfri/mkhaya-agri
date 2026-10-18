# Conferences Phase 3 — Testing, Security & Full UAT (Task 3.10)

Phase 3 adds the AI & analytics layer: transcription, translation, summaries,
the concierge chatbot, recommendations, content generation, sentiment,
predictions and the advanced analytics dashboard. This is also the final
acceptance across all three phases (31 tasks).

## 1. Automated tests

Phase 3 PHPUnit feature tests live in `tests/Unit/Conferences/Phase3/` and run
the AI pipeline against the deterministic `mock` provider:

| File | Tasks | Key assertions |
|---|---|---|
| `TranscriptionTranslationTest` | 3.1, 3.2 | interim→final (no dup), live order; translation cached + interim skipped |
| `SummaryChatbotTest` | 3.3, 3.4 | summary w/ takeaways, empty→failed; chatbot grounding + **data isolation** |
| `AnalyticsAndAiTest` | 3.5–3.9 | reasoned recs, content types, aggregate sentiment + min-sample, prediction confidence, attendance rate, **ROI == Task 2.5 net** |

Plus the Phase 1 (`tests/Unit/Conferences/`) and Phase 2
(`tests/Unit/Conferences/Phase2/`) suites — the **full regression** across all
three phases.

```bash
# CI / isolated DB only — phpunit.xml points DB_DATABASE at the dev DB, so do NOT
# run RefreshDatabase against it locally. Same logic is verified here via
# rolled-back transactions.
php artisan test tests/Unit/Conferences
```

The project uses **PHPUnit** (not Pest) and **Dusk** is not installed, so the
spec's Pest + browser E2E are delivered as PHPUnit feature tests; the live AI,
sandbox payments and human UAT below remain.

## 2. Security & penetration review

- [ ] **Authentication / authorization** — every organizer/finance action is
      role-gated; module routes sit behind `auth` + `module.active:conferences`.
- [ ] **Payment flows** — the webhook (not the browser return) confirms payment;
      gateway signatures are verified; webhook replay is idempotent (Task 2.2).
- [ ] **Join-link identity binding** — a personal join link is rejected for any
      other logged-in user; host URLs reach only assigned speakers (Task 2.7).
- [ ] **Chatbot data isolation** — the concierge answers only from conference
      data + the asking attendee's own record; it never exposes another
      attendee's details, and a conversation id can't be loaded by another
      attendee (Task 3.4).
- [ ] **AI prompt-injection / scope** — the concierge system prompt refuses
      out-of-scope questions and world knowledge; content generation never
      invents quotes from real named people (Task 3.6).
- [ ] **POPIA** — files (tickets, badges, banners, invoices) are stored in the
      DB via the FileStorage trait; sentiment is aggregate-only (no per-person);
      personal data is scoped per registration; honour the breach/consent
      registers in the POPIA module.
- [ ] **Webhook endpoints** are CSRF-exempt by path only; unsigned/invalid
      payloads are rejected and logged.

Any high/critical finding is fixed and re-tested before sign-off.

## 3. Full end-to-end UAT (all 31 tasks)

Run one complete real conference, hybrid, with AI active:

- [ ] Create → publish; build agenda, rooms, speakers; configure tickets +
      promo codes + a payment gateway (sandbox).
- [ ] Invite (bulk/CSV), register (free + paid), RSVP, pick sessions; waitlist a
      capped session and confirm promotion on a cancel/refund.
- [ ] Pay a paid ticket end-to-end (webhook confirms); receipt + invoice correct;
      refund returns via the same gateway; finance reconciliation balances.
- [ ] On-site: QR check-in + badges; manual fallback.
- [ ] Online/hybrid: provision a meeting; personal join links; the hub (live/
      next/agenda/recordings); chat, moderated Q&A, polls (Reverb running).
- [ ] AI: live captions + translation; a session recap emailed after the session;
      the concierge answers schedule + personal-join-link questions; session &
      connection recommendations; AI-drafted copy; sentiment + attendance
      forecast + the analytics dashboard with a saved custom report and ROI.

## 4. Sign-off

- [ ] Complete automated suite (Phases 1–3) passes in CI.
- [ ] Penetration test cleared; high/critical findings fixed + re-tested.
- [ ] Full hybrid conference completed with no critical/high bugs open.
- [ ] Final UAT sign-off from a **business stakeholder**, **Finance** and a
      **security reviewer**.

---
*Live AI features (transcription stream, real translations/summaries/chatbot
answers) require provider keys (OPENAI_API_KEY is configured); realtime chat
needs Reverb running; scheduled AI jobs (summaries, sentiment, forecasts) need
the queue worker + scheduler. Everything else is exercised by the suite above.*
