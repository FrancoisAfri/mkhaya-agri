# Board Management Module — Assessment, Competitive Comparison & AI Roadmap

**Project:** mkhaya-hub
**Module:** `app/Modules/Governance/Board/`
**Date:** 2026-06-05
**Status:** Reference document — assessment only, no code changes

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current Module Inventory (Full)](#2-current-module-inventory-full)
3. [Competitive Comparison vs. World-Leading Systems](#3-competitive-comparison-vs-world-leading-systems)
4. [Current AI Implementation](#4-current-ai-implementation)
5. [AI Improvement Opportunities (Full Roadmap)](#5-ai-improvement-opportunities-full-roadmap)
6. [AI Guardrails](#6-ai-guardrails)
7. [Bottom Line](#7-bottom-line)
8. [Sources](#8-sources)

---

## 1. Executive Summary

The board management module in mkhaya-hub is **far more complete than a typical board portal**. It spans **36 models across 19 feature areas** and is, on feature breadth, in the same league as commercial portals.

**Standout strengths:**
- **King IV compliance tracker** (South African governance code, 16 principles + evidence) — a real local differentiator.
- **Offline board-pack access + bidirectional annotation sync** — something even some Tier-1 vendors charge extra for.
- **Voting integrity** — encrypted vote casts, secret ballot, chair casting vote, conflict-of-interest exclusion with override, configurable thresholds.

**Main gaps vs. global leaders (Diligent, Nasdaq Boardvantage, OnBoard, BoardEffect, Convene):**
1. Native iOS/Android apps (biometric login, remote wipe, purge-on-resignation).
2. D&O questionnaires & board self-assessments as a first-class module.
3. Skills matrix & board composition analytics.
4. Native calendar/video/email integration (Outlook, Google Calendar, Teams, Zoom).
5. Enterprise legal-entity management depth.
6. AI document intelligence (risk scanning, data room).
7. A polished agenda→board-book→minutes AI pipeline.

**Highest-leverage AI move:** a **Smart Risk Scanner + RAG-grounded assistant** — both extend the service that already exists, both map directly onto King IV, and both are exactly what market leaders charge premium prices for in 2025–26.

---

## 2. Current Module Inventory (Full)

**Module path:** `app/Modules/Governance/Board/`
**Routes:** `routes/modules/governance.php`
**Migrations:** `database/migrations/governance/`

### Coverage matrix

| Area | Present? | Notes |
|---|---|---|
| Director register + term/rotation tracking | ✅ | Term expiry alerts, max-terms, history audit (`BoardTermService`) |
| Committees (audit/risk/etc.) + quorum config | ✅ | Quorum type, voting thresholds, chair/secretary |
| Meeting lifecycle | ✅ | 10-state machine incl. `inquorate`, `adjourned`, `reconvened`, round-robin |
| Agendas + templates + versioning | ✅ | Hierarchical items, standing items, presenter/duration |
| Board packs | ✅ | PDF compile, watermark, classification, distribution + read receipts |
| Offline pack access + annotations sync | ✅ | Genuinely advanced — many SMB competitors lack this |
| Minutes workflow + e-signature | ✅ | draft→submitted→chair_approved→signed→distributed |
| Resolutions + voting | ✅ | Secret ballot, encrypted casts, casting vote, COI exclusion, thresholds |
| Action/resolution register | ✅ | Escalation, reminders, evidence upload, % progress |
| Conflict of interest + Gift register | ✅ | |
| King IV compliance tracker | ✅ | 16 principles + evidence — SA-specific differentiator |
| Document library + access logs | ✅ | Versioning, classification, watermark |
| AI: transcription, minutes, actions, summaries, assistant | ✅ (basic) | `GovernanceAiService` — OpenAI-compatible, degrades gracefully |
| Security: Azure AD, TOTP/2FA, session revocation, signed download tokens | ✅ | |
| Scheduled reports + push notifications + dashboards | ✅ | |

### 2.1 Director Management

**Purpose:** Maintain a registry of board directors with term tracking and rotation management.

**Database tables:** `governance_directors`, `governance_director_history`

**Key fields (`governance_directors`):**
- `uuid`, `user_id` (optional, for external directors)
- `first_name`, `last_name`, `id_number`, `email`, `phone`, `nationality`
- `director_type` (enum: executive, non_executive, independent_non_executive, alternate)
- `independence_status` (enum: independent, not_independent, to_be_determined)
- `appointment_date`, `resignation_date`, `disqualification_date`, `disqualification_reason`
- `current_term_start`, `current_term_end`, `rotation_due_date`
- `max_terms`, `terms_served`, `is_active`
- `notes`, `created_by`, timestamps, soft deletes

**Model:** `GovernanceDirector` · **Controller:** `DirectorController`

**Endpoints:**
- `GET /governance/board/members` — List all directors
- `POST /governance/board/directors` — Create director
- `PATCH /governance/board/directors/{director}` — Update director
- `DELETE /governance/board/directors/{director}` — Soft delete director

### 2.2 Committee Management

**Purpose:** Define and manage board committees (Audit, Risk, Finance, Procurement, Ethics, Disciplinary, Oversight, Ad Hoc).

**Database tables:** `governance_committees`, `governance_committee_members`

**Key fields (`governance_committees`):**
- `uuid`, `name`, `type` (enum: board, audit, risk, finance, procurement, ethics, disciplinary, oversight, ad_hoc)
- `description`, `mandate`, `objectives`, `terms_of_reference`
- `quorum_type` (enum: number, percentage), `quorum_value`, `voting_threshold`
- `chair_user_id`, `secretary_user_id`
- `is_active`, `established_date`, `dissolved_date`
- `created_by`, timestamps, soft deletes

**Committee member fields:** `role` (enum: chair, secretary, member, observer), `appointed_at`, `expires_at`, `is_active`

**Models:** `GovernanceCommittee`, `GovernanceCommitteeMember` · **Controller:** `CommitteeController`

**Endpoints:**
- `GET /governance/board/committees` — List committees
- `POST/PATCH/DELETE /governance/board/committees/{committee}` — CRUD
- `POST /governance/board/committees/{committee}/members` — Add member
- `DELETE /governance/board/committees/{committee}/members/{member}` — Remove member

### 2.3 Board Meetings

**Purpose:** Manage board and committee meeting lifecycle from scheduling through archival.

**Database tables:** `governance_meetings`, `governance_meeting_attendees`, `governance_meeting_notices`, `governance_meeting_transcripts`

**Key fields (`governance_meetings`):**
- `uuid`, `committee_id` (nullable for board meetings), `title`
- `meeting_type` (enum: board, committee, annual_general, extraordinary, special, round_robin)
- `status` (enum: scheduled, pack_issued, open, inquorate, adjourned, reconvened, closed, minutes_drafted, minutes_approved, archived)
- `scheduled_at`, `ends_at`, `location`, `virtual_link`, `is_virtual`
- `is_recurring`, `recurrence_rule`
- `notice_sent_at`, `pack_issued_at`
- `quorum_met`, `quorum_count`
- `adjourned_to`, `adjourned_reason`, `reconvened_from`
- `chairperson_user_id`, `created_by`, timestamps, soft deletes

**Attendee fields:** `attendance_status` (enum: pending, accepted, declined, present, apology, absent), `rsvp_at`, `checked_in_at`, `apology_reason`, `is_chair`

**Meeting transcript fields:**
- `status` (enum: uploaded, transcribing, transcribed, reviewed, failed)
- `file_name`, `mime_type`, `file_size_bytes`, `file_data` (blob storage)
- `transcript_text`, `segments` (JSON speaker/timing), `speakers` (JSON)
- `ai_model`, `ai_generated`, `processed_at`
- `reviewed_by`, `reviewed_at`, `uploaded_by`

**Models:** `GovernanceMeeting`, `GovernanceMeetingAttendee`, `GovernanceMeetingNotice`, `GovernanceMeetingTranscript`
**Controllers:** `MeetingController`, `AttendanceController`, `MeetingTranscriptionController`

**Endpoints:**
- `GET/POST/PATCH/DELETE /governance/board/meetings` — Meeting CRUD
- `POST /governance/board/meetings/{meeting}/transition` — Status change
- `POST /governance/board/meetings/{meeting}/notice` — Send meeting notice
- `GET /governance/board/meetings/{meeting}/attendance` — Attendance view
- `POST /governance/board/meetings/{meeting}/attendees` — Add/update attendees
- `POST /governance/board/meetings/{meeting}/rsvp` — RSVP endpoint
- `POST /governance/board/transcription` — Upload recording
- `POST /governance/board/transcription/{transcript}/transcribe` — AI transcription
- `POST /governance/board/transcription/{transcript}/minutes` — Auto-generate minutes

### 2.4 Agenda Management

**Purpose:** Create, manage, and version meeting agendas; support templates for recurring item types.

**Database tables:** `governance_agenda_items`, `governance_agenda_templates`, `governance_agenda_versions`

**Key fields (`governance_agenda_items`):**
- `meeting_id` (nullable for templates), `template_id`, `parent_id` (hierarchical)
- `sort_order`, `item_number`, `title`
- `type` (enum: standard, standing, information, decision, discussion, procedural)
- `presenter_user_id`, `duration_mins`
- `description`, `outcome`
- `is_standing`, `is_confidential`, timestamps

**Template fields:** `name`, `committee_type`, `description`, `is_active`, `created_by`

**Models:** `GovernanceAgendaItem`, `GovernanceAgendaTemplate`, `GovernanceAgendaVersion`
**Controllers:** `AgendaController`, `AgendaTemplateController`

**Endpoints:**
- `GET /governance/board/meetings/{meeting}/agenda` — View agenda
- `POST /governance/board/meetings/{meeting}/agenda/items` — Add item
- `PATCH/DELETE /governance/board/meetings/{meeting}/agenda/items/{item}` — Edit/delete item
- `PATCH /governance/board/meetings/{meeting}/agenda/reorder` — Reorder items
- `POST /governance/board/meetings/{meeting}/agenda/apply-template` — Apply template
- `POST /governance/board/meetings/{meeting}/agenda/restore-version` — Restore version
- `GET/POST/PATCH/DELETE /governance/board/agenda-templates` — Manage templates

### 2.5 Board Pack Management

**Purpose:** Compile, version, distribute, and track board packs (PDF bundles with documents, agendas, resolutions).

**Database tables:** `governance_board_packs`, `governance_pack_items`, `governance_pack_distributions`, `governance_pack_annotations`, `governance_offline_packs`

**Key fields (`governance_board_packs`):**
- `uuid`, `meeting_id`, `status` (enum: draft, compiled, issued, revised)
- `description`, `classification` (enum: public, internal, confidential, restricted)
- `watermark_text`
- `compiled_at`, `issued_at`, `version`, `total_pages`
- `compiled_by`, `pdf_path`, `pdf_data` (blob storage), `file_size_bytes`
- timestamps

**Pack item fields:**
- `document_id` (nullable), `title`, `section_number`
- `item_type` (enum: cover, toc, agenda, document, minutes, resolution, report, appendix, separator)
- `sort_order`, `file_path`, `file_name`, `file_size_bytes`, `mime_type`
- `include_in_toc`, `is_watermarked`, `page_count`, `uploaded_by`

**Distribution fields:** `user_id`, `director_id` (external directors), `distributed_at`, `downloaded_at`, `read_confirmed_at`

**Annotation fields:**
- `pack_id`, `pack_item_id` (nullable), `user_id`
- `page_number`, `type` (enum: note, highlight, question)
- `content`, `position` (JSON coordinates)
- `is_private`, `client_created_at`

**Models:** `GovernanceBoardPack`, `GovernancePackItem`, `GovernancePackDistribution`, `GovernancePackAnnotation`, `GovernanceOfflinePack`
**Controllers:** `BoardPackController`, `OfflinePackController`

**Endpoints:**
- `GET/POST/PATCH/DELETE /governance/board/pack` — Pack CRUD
- `GET /governance/board/pack/{pack:uuid}` — Pack details
- `POST /governance/board/pack/{pack:uuid}/items` — Add item
- `DELETE/PATCH /governance/board/pack/{pack:uuid}/items` — Manage items
- `POST /governance/board/pack/{pack:uuid}/compile` — Compile to PDF
- `POST /governance/board/pack/{pack:uuid}/issue` — Issue to recipients
- `POST /governance/board/pack/{pack:uuid}/revise` — Create revision
- `POST /governance/board/pack/{pack:uuid}/confirm-read` — Track read confirmation
- `POST /governance/board/pack/{pack:uuid}/recipients` — Add/remove recipients
- `GET /governance/pack/{pack:uuid}/download/{token}` — Secure public download link
- `GET/POST /governance/offline-packs/{pack}/payload` — Offline sync
- `POST/DELETE /governance/offline-packs/{pack}/annotations/sync` — Annotation sync

### 2.6 Meeting Minutes

**Purpose:** Draft, review, approve, sign, and distribute meeting minutes with action item tracking.

**Database tables:** `governance_minutes`, `governance_minute_items`, `governance_minutes_action_items`, `governance_minutes_versions`

**Key fields (`governance_minutes`):**
- `meeting_id`, `status` (enum: draft, submitted, chair_approved, signed, distributed, amended)
- `opening_statement`, `closing_statement`, `next_meeting_details`
- `drafted_by`, `drafted_at`
- `submitted_by`, `submitted_at`
- `chair_approved_by`, `chair_approved_at`
- `signed_at`, `chair_signature`
- `distributed_at`
- `version`, `notes`, timestamps, soft deletes

**Minute item fields:** `minutes_id`, `agenda_item_id` (link), `sort_order`, `item_number`, `discussion_summary`, `voting_outcome`, `decisions`, timestamps

**Action item fields:** `minutes_id`, `title`, `description`, `owner_user_id`, `due_date`, `status` (enum: pending, in_progress, completed, overdue, cancelled), `assigned_to`

**Models:** `GovernanceMinutes`, `GovernanceMinuteItem`, `GovernanceMinutesActionItem`, `GovernanceMinutesVersion`
**Controller:** `MinutesController`

**Endpoints:**
- `GET /governance/board/minutes` — List minutes
- `POST /governance/board/minutes` — Create from meeting
- `GET /governance/board/minutes/{minutes}` — View minute details
- `PATCH /governance/board/minutes/{minutes}/header` — Update header/statements
- `PATCH /governance/board/minutes/{minutes}/items/{item}` — Edit minute item
- `POST /governance/board/minutes/{minutes}/action-items` — Add action item
- `PATCH /governance/board/minutes/{minutes}/action-items/{action}` — Edit action item
- `POST /governance/board/minutes/{minutes}/transition` — Workflow transition (submit/approve/sign)
- `DELETE /governance/board/minutes/{minutes}` — Soft delete

### 2.7 Resolutions & Voting

**Purpose:** Create, propose, vote on, and track resolutions; support multiple voting mechanisms (show of hands, secret ballot, round-robin).

**Database tables:** `governance_resolutions`, `governance_resolution_actions`, `governance_votes`, `governance_vote_casts`

**Key fields (`governance_resolutions`):**
- `uuid`, `resolution_number` (auto-generated: RES-YYYY-###)
- `meeting_id`, `committee_id` (context), `proposer_id`, `seconder_id`
- `title`, `description`
- `resolution_type` (enum: ordinary, special, written, round_robin)
- `status` (enum: draft, proposed, voted, passed, failed, withdrawn, superseded)
- `voting_result` (JSON), `passed_at`
- `effective_date`, `implementation_due`
- `implementation_status` (enum: pending, in_progress, completed, overdue, not_required)
- `created_by`, timestamps, soft deletes

**Resolution action fields:**
- `title`, `description`
- `owner_user_id`, `assigned_to`
- `due_date`, `status` (enum: pending, in_progress, completed, overdue, cancelled)
- `progress_percentage` (0-100), `priority` (enum: low, medium, high, critical)
- `completed_at`, `completion_notes`
- `escalated_at`, `escalated_to`, `escalation_reason`
- `last_reminder_at`, `reminder_count`
- `approved_by`, `approved_at`, `created_by`

**Vote fields:**
- `meeting_id`, `resolution_id` (nullable), `agenda_item_id` (nullable)
- `title`, `is_secret_ballot`
- `status` (enum: open, closed, cancelled)
- `threshold_type` (enum: simple_majority, two_thirds, three_quarters, unanimous)
- `threshold_pct` (decimal)
- `votes_for`, `votes_against`, `votes_abstain`
- `chair_casting_vote` (enum: for, against, abstain)
- `result` (enum: passed, failed, tied)
- `opened_at`, `closed_at`, `created_by`

**Vote cast fields:**
- `vote_id`, `user_id`, `director_id`
- `choice` (enum: for, against, abstain)
- `is_excluded`, `exclusion_reason`
- `cast_at`, `encrypted_payload`, timestamps

**Models:** `GovernanceResolution`, `GovernanceResolutionAction`, `GovernanceVote`, `GovernanceVoteCast`, `GovernanceActionEvidence`
**Controllers:** `ResolutionController`, `ResolutionRegisterController`, `VotingController`

**Endpoints:**
- `GET/POST/PATCH/DELETE /governance/board/resolutions` — Resolution CRUD
- `POST /governance/board/resolutions/{resolution}/vote` — Open vote on resolution
- `POST /governance/board/resolutions/{resolution}/vote/{vote}/cast` — Cast vote
- `POST /governance/board/resolutions/{resolution}/vote/{vote}/close` — Close vote
- `POST /governance/board/resolutions/{resolution}/vote/{vote}/remind` — Send reminders
- `GET /governance/board/resolution-register` — View all resolutions
- `POST /governance/board/resolution-register/{resolution}/actions` — Add action
- `PATCH /governance/board/resolution-register/{resolution}/actions/{action}` — Edit action
- `POST /governance/board/resolution-register/{resolution}/actions/{action}/approve` — Approve completion
- `POST /governance/board/resolution-register/{resolution}/actions/{action}/escalate` — Escalate
- `POST /governance/board/resolution-register/{resolution}/actions/{action}/remind` — Send reminder
- `POST /governance/board/resolution-register/{resolution}/actions/{action}/evidence` — Upload evidence
- `GET /governance/board/action-register` — Cross-resolution action view
- `GET/POST/PATCH/DELETE /governance/board/voting` — Standalone voting CRUD
- `POST /governance/board/voting/{vote}/cast` — Cast vote (standalone votes)
- `POST /governance/board/voting/{vote}/casting` — Set chair casting vote
- `POST /governance/board/voting/{vote}/exclude` — Exclude voter (conflict of interest)
- `POST /governance/board/voting/{vote}/override` — Override exclusion (admin)

### 2.8 Conflict of Interest Declarations

**Purpose:** Track COI declarations, required recusal, and approval workflows.

**Database table:** `governance_coi_declarations`

**Key fields:**
- `user_id`, `director_id` (external directors)
- `declaration_type` (enum: annual, ad_hoc, meeting_specific)
- `meeting_id` (nullable, for meeting-specific declarations)
- `has_conflict` (boolean), `conflict_description`, `conflict_category`
- `recusal_required`, `recusal_approved_by`
- `declared_at`, `reviewed_at`, `reviewed_by`
- `status` (enum: pending, reviewed, approved, rejected)
- timestamps

**Model:** `GovernanceCoiDeclaration` · **Controller:** `ConflictController`

**Endpoints:**
- `GET/POST/PATCH/DELETE /governance/board/conflicts` — COI CRUD
- `PATCH /governance/board/conflicts/{conflict}/review` — Review and approve/reject

### 2.9 Gift Register

**Purpose:** Track gifts received/given by board members; governance compliance.

**Database table:** `governance_gifts`

**Key fields:**
- `uuid`, `user_id`, `direction` (enum: received, given)
- `description`, `counterparty`, `counterparty_relationship`
- `estimated_value`, `currency` (default: ZAR)
- `category` (enum: gift, hospitality, travel, entertainment, donation, sponsorship, other)
- `received_on`, `context`, `declared_at`
- `status` (enum: pending, approved, declined, returned, retained)
- `review_notes`, `reviewed_by`, `reviewed_at`
- timestamps

**Model:** `GovernanceGift` · **Controller:** `GiftRegisterController`

**Endpoints:**
- `GET/POST/PATCH/DELETE /governance/board/gifts` — Gift CRUD
- `PATCH /governance/board/gifts/{gift}/review` — Review and approve/decline

### 2.10 Governance Documents & Library

**Purpose:** Centralized document repository with version control, access control, and audit logging.

**Database tables:** `governance_documents`, `governance_document_access_logs`

**Key fields (`governance_documents`):**
- `uuid`, `title`, `description`
- `document_type` (enum: policy, charter, board_pack, minutes, resolution, report, contract, correspondence, other)
- `classification` (enum: public, internal, confidential, restricted)
- `file_path`, `file_name`, `mime_type`, `file_size_bytes`
- `version`, `is_current_version`, `parent_document_id` (version history)
- `committee_id` (nullable), `meeting_id` (nullable)
- `allow_download`, `allow_print`, `is_watermarked`
- `uploaded_by`, `created_by`, timestamps, soft deletes

**Access log fields:** `document_id`, `user_id`, `access_type` (view/download), `accessed_at`

**Models:** `GovernanceDocument`, `GovernanceDocumentAccessLog` · **Controller:** `GovernanceLibraryController`

**Endpoints:**
- `GET/POST/PATCH/DELETE /governance/board/library` — Document CRUD
- `POST /governance/board/library/{document}/version` — Upload new version
- `GET /governance/board/library/{document}/download` — Download document
- `POST /governance/board/library/{document}/view` — Track view (audit log)

### 2.11 King IV Compliance (South African Governance Code)

**Purpose:** Track compliance with King IV principles and maintain evidence.

**Database tables:** `governance_king_principles`, `governance_king_evidence`

**Key fields (`governance_king_principles`):**
- `number` (unique: 1-16), `title`, `description`, `category`
- `status` (enum: not_started, in_progress, partial, compliant, not_applicable)
- `application_notes`
- `responsible_user_id`, `last_reviewed_at`, `updated_by`
- timestamps

**Evidence fields:** `principle_id`, `user_id`, `file_name`, `file_path`, `file_size_bytes`, `description`, `uploaded_at`, `uploaded_by`

**Models:** `GovernanceKingPrinciple`, `GovernanceKingEvidence` · **Controller:** `KingComplianceController`

**Endpoints:**
- `GET /governance/board/king` — View all principles
- `PATCH /governance/board/king/{principle}` — Update principle status
- `POST /governance/board/king/{principle}/evidence` — Upload evidence
- `GET /governance/board/king/{principle}/evidence/{evidence}` — Download evidence
- `DELETE /governance/board/king/{principle}/evidence/{evidence}` — Delete evidence

### 2.12 Board Roles & Appointments

**Purpose:** Assign and manage board roles (Administrator, Company Secretary, Chairperson, Director, Committee Member, Internal Auditor, Observer).

**Database tables:** `governance_board_appointments`, `governance_role_delegations`

**Key fields (`governance_board_appointments`):**
- `user_id`, `board_role` (string: board_administrator, company_secretary, chairperson, director, committee_member, internal_auditor, board_observer)
- `committee_id` (nullable, for committee_member roles)
- `appointed_at`, `expires_at` (nullable)
- `appointed_by`, `is_active`
- `notes`, timestamps, soft deletes

**Delegation fields:** `user_id`, `from_user_id`, `to_user_id`, `role`, `delegated_from`, `delegated_to`, `reason`, `created_by`, timestamps

**Models:** `GovernanceBoardAppointment`, `GovernanceRoleDelegation` · **Controller:** `BoardRolesController`

**Endpoints (admin only):**
- `GET /governance/board/roles` — View all appointments
- `POST /governance/board/roles/appointments` — Create appointment
- `PATCH /governance/board/roles/appointments/{appointment}` — Update appointment
- `DELETE /governance/board/roles/appointments/{appointment}` — Remove appointment
- `POST /governance/board/roles/delegations` — Create delegation
- `DELETE /governance/board/roles/delegations/{delegation}` — Remove delegation
- `POST /governance/board/roles/matrix` — Bulk update role matrix

### 2.13 Organization Structure

**Purpose:** Define corporate structure (holding company, subsidiaries, business units, departments, regional offices, joint ventures).

**Database table:** `governance_org_units`

**Key fields:**
- `name`, `unit_type` (enum: holding, subsidiary, business_unit, department, regional_office, joint_venture)
- `parent_id` (nullable, hierarchical), `registration_number`, `jurisdiction`
- `is_active`, `description`
- timestamps, soft deletes

**Model:** `GovernanceOrgUnit` · **Controller:** `OrgStructureController`

**Endpoints:**
- `GET/POST/PATCH/DELETE /governance/board/org-structure` — Structure CRUD

### 2.14 Governance Notifications & Alerts

**Purpose:** Real-time notifications for meetings, resolutions, action items, and board term expirations.

**Database table:** `governance_push_subscriptions`

**Functionality:**
- Meeting notices (initial, reminder, final)
- RSVP tracking
- Pack distribution and read confirmation
- Voting reminders
- Action item reminders and escalations
- Board term expiry alerts (6-month window before expiry)
- Minutes approval workflow notifications

**Model:** `GovernancePushSubscription` · **Controller:** `GovernanceNotificationController`

**Endpoints:**
- `GET /governance/board/notifications` — View notifications
- `GET /governance/board/notifications/feed` — Streaming feed
- `POST /governance/board/notifications/{id}/read` — Mark as read
- `POST /governance/board/notifications/read-all` — Mark all as read
- `DELETE /governance/board/notifications/{id}` — Delete
- `POST /governance/push/subscribe` — Subscribe to push
- `POST /governance/push/unsubscribe` — Unsubscribe from push

### 2.15 Scheduled Reports

**Purpose:** Automated report generation and distribution on cron schedule.

**Database table:** `governance_scheduled_reports`

**Key fields:**
- `name`, `report_key` (unique identifier)
- `cadence` (enum: daily, weekly, monthly, etc.), `run_time` (HH:MM), `day_of_week`, `day_of_month`
- `format` (enum: pdf, csv, json), `recipients` (JSON array of emails)
- `active`, `last_run_at`, `next_run_at`, `created_by`
- timestamps

**Available reports:**
- Board meeting schedule
- Upcoming board member term expirations
- Resolution implementation status
- Committee membership changes
- Compliance status (King IV)
- Meeting minutes summary
- Action item status

**Model:** `GovernanceScheduledReport` · **Controller:** `GovernanceScheduledReportController`

**Endpoints:**
- `GET/POST/PATCH/DELETE /governance/board/scheduled-reports` — Report config CRUD
- `POST /governance/board/scheduled-reports/{scheduledReport}/toggle` — Enable/disable

### 2.16 Reporting & Analytics

**Purpose:** Executive dashboards, reports, and analytics on governance activities.

**Key reports:**
- Board member register (with term status)
- Committee composition
- Meeting attendance
- Resolution tracking (implementation status)
- Action item tracking (overdue, completed)
- Voting records
- Meeting minutes archives
- Board term renewal alerts
- King IV compliance status

**Model:** `GovernanceReportData`
**Controllers:** `GovernanceReportController`, `GovernanceAnalyticsController`, `ExecutiveDashboardController`, `BoardDashboardController`, `ComplianceDashboardController`, `GovernanceSearchController`

**Endpoints:**
- `GET /governance/board/reports` — Main reports page
- `POST /governance/board/reports/board-term-alerts` — Send term expiry alerts
- `GET /governance/board/scheduled-reports` — View scheduled reports
- `GET /governance/board/executive-dashboard` — Executive summary
- `GET /governance/board/analytics` — Analytics page
- `GET /governance/board/search` — Global governance search
- `GET /governance/board/dashboard` — Main board dashboard

### 2.17 AI-Powered Features (existing)

**Purpose:** Leverage AI for transcription, minutes generation, action extraction, and governance insights.

**Features:**
- **Meeting Transcription** — Upload meeting recordings; AI transcribes with speaker identification (heuristic)
- **Minutes Auto-Generation** — AI generates draft minutes from transcript
- **Action Item Extraction** — AI extracts action items and owners from transcript
- **Governance Assistant** — Chatbot for governance policy queries and document search
- **Document Summarization** — Summarize lengthy documents for pack distribution

**Services:** `GovernanceAiService`, `DocumentTextExtractor`
**Model:** `GovernanceMeetingTranscript`
**Controllers:** `MeetingTranscriptionController`, `GovernanceAssistantController`

**Endpoints:**
- `POST /governance/board/transcription` — Upload recording
- `POST /governance/board/transcription/{transcript}/transcribe` — Trigger transcription
- `PATCH /governance/board/transcription/{transcript}/save` — Save transcript
- `POST /governance/board/transcription/{transcript}/reviewed` — Mark reviewed
- `POST /governance/board/transcription/{transcript}/minutes` — Generate minutes
- `POST /governance/board/transcription/{transcript}/actions` — Extract actions
- `POST /governance/board/assistant/ask` — Query assistant
- `POST /governance/board/assistant/summarise` — Summarize document

### 2.18 Board Term Lifecycle Service

**Purpose:** Automated tracking and alerts for director term expirations and rotation.

**Service:** `BoardTermService` · **Command:** `ProcessBoardTermsCommand`

**Features:**
- 6-month advance alert window
- Daily expiry alerts until term is renewed
- Automatic access revocation on term lapse (if not renewed)
- Term tracking fields: `current_term_start`, `current_term_end`, `rotation_due_date`, `terms_served`, `max_terms`
- Audit trail via `governance_director_history`

### 2.19 Offline Board Pack Access

**Purpose:** Enable directors to access board packs offline (mobile/web) with sync on reconnect.

**Database tables:** `governance_offline_packs`, `governance_pack_annotations`

**Features:**
- Download pack for offline access (cached locally)
- Create/edit/delete annotations while offline
- Sync annotations when back online
- Track read status even offline

**Models:** `GovernanceOfflinePack`, `GovernancePackAnnotation` · **Controller:** `OfflinePackController`

**Endpoints:**
- `GET /governance/offline-packs` — List offline packs
- `GET /governance/offline-packs/{pack}/payload` — Get pack payload (PDF + metadata)
- `DELETE /governance/offline-packs/{pack}` — Clear offline cache
- `GET /governance/offline-packs/{pack}/annotations` — Get annotations
- `POST /governance/offline-packs/{pack}/annotations/sync` — Sync annotations
- `DELETE /governance/offline-packs/{pack}/annotations/{annotation}` — Delete annotation

### 2.20 Security & Access Control

**Authentication:**
- Azure AD OAuth support via `AzureAuthController`
- TOTP (2FA) setup and verification via `GovernanceTotpController`
- Session management with revocation via `GovernanceSessionController`
- Governance-specific security middleware: `GovernanceSecurity`, `GovernanceSessionTracker`

**Authorization:**
- Role-based access control: `board_administrator`, `board_observer`, various board roles
- Module activation check: `module.active:board_committee`
- Permission checks in controllers (e.g., `governance.pack.manage`)
- Pack download via secure signed tokens

**Document access:**
- Download/print permissions per document
- Watermarking support
- Access logging for audit trail
- Classification levels (public, internal, confidential, restricted)

**Voting security:**
- Secret ballot support
- Encrypted vote payloads
- Vote exclusion (conflict of interest) with override capability
- Chair casting vote capability

### 2.21 Workflow Statuses by Feature

- **Meeting:** `scheduled` → `pack_issued` → `open` | `inquorate` → `adjourned` | `reconvened` → `closed` → `minutes_drafted` → `minutes_approved` → `archived`
- **Minutes:** `draft` → `submitted` → `chair_approved` → `signed` → `distributed` | `amended`
- **Resolution:** `draft` → `proposed` → `voted` → `passed` | `failed` | `withdrawn` | `superseded`
- **Resolution Action:** `pending` → `in_progress` → `completed` | `overdue` | `cancelled`
- **Vote:** `open` → `closed` | `cancelled`
- **Board Pack:** `draft` → `compiled` → `issued` | `revised`
- **COI Declaration:** `pending` → `reviewed` → `approved` | `rejected`
- **Gift:** `pending` → `approved` | `declined` | `returned` | `retained`
- **King IV Principle:** `not_started` → `in_progress` → `partial` | `compliant` | `not_applicable`

### 2.22 Database Summary

**36 models total:** Directors, Meetings, Minutes, Committees, Resolutions, Votes, Agendas, Board Packs, Documents, COI Declarations, Gifts, King IV Principles, Transcripts, Organizational Units, Board Appointments, Role Delegations, and supporting tables for versioning, history, evidence, annotations, and audit logging.

**Key relationships:**
- Directors → User accounts (with external director support)
- Committees → Members, Meetings
- Meetings → Attendees, Agenda Items, Resolutions, Minutes, Board Pack, Notices, Transcripts, Votes
- Resolutions → Actions (with evidence), Votes
- Minutes → Action Items, Item Details
- Board Pack → Items, Distributions, Annotations
- Documents → Versions, Access Logs

All governance entities support soft deletes and activity logging for audit compliance.

---

## 3. Competitive Comparison vs. World-Leading Systems

The global leaders are **Diligent Boards** (used by ~75% of the Fortune 500), **Nasdaq Boardvantage**, **OnBoard**, **BoardEffect**, and **Convene** (Azeus).

### 3.1 Where mkhaya-hub is at parity or ahead

- **Breadth of governance workflow** — COI, gift register, resolution register with evidence, and King IV compliance match or exceed what OnBoard / BoardEffect ship out of the box.
- **Offline + annotations** — comparable to Diligent's native apps; bidirectional sync is genuinely advanced.
- **Voting integrity** — encrypted casts, secret ballot, casting vote, conflict exclusion with override is genuinely strong.

### 3.2 Where the leaders are ahead (the real gaps)

1. **Native iOS/Android apps.** Diligent, Boardvantage and OnBoard all ship polished native apps with biometric login, remote wipe, and "purge on resignation." mkhaya-hub has offline payloads but no native mobile client. Directors are non-technical execs — app quality is often *the* buying decision.

2. **D&O questionnaires & self-assessments as a first-class module.** Diligent and OnBoard have full questionnaire engines (board evaluations, D&O disclosures, conflict attestations) with mobile-first forms and analytics. mkhaya-hub has COI/gifts but not a general questionnaire/board-evaluation builder.

3. **Skills matrix & board composition analytics.** Leaders map director skills/diversity/tenure against a target matrix and flag gaps. mkhaya-hub tracks director type and independence but not a competency/diversity matrix — increasingly a board-evaluation requirement.

4. **Calendar/video/email deep integration.** Boardvantage / OnBoard integrate Outlook, Google Calendar, Teams, Zoom natively (one-click join, auto-RSVP sync). mkhaya-hub has notices and virtual links but not two-way calendar/video sync.

5. **Enterprise entity management.** Diligent ties the board portal to a legal-entity register (subsidiaries, filings, org charts across jurisdictions). mkhaya-hub has `governance_org_units` — a good foundation, but not full entity-management depth.

6. **Document intelligence depth.** Diligent's **Smart Risk Scanner** auto-flags legal/compliance red-flag language inside packs; their **Data Room** cuts due-diligence review from weeks to days. mkhaya-hub AI is summary/Q&A only, not risk-scanning.

7. **Polished agenda → book → minutes AI pipeline.** Diligent's **Smart Builder** turns raw docs/spreadsheets into a formatted board deck in minutes; **Smart Minutes** does voice-matched minutes with auto action-tracking. mkhaya-hub's pipeline exists but is thinner (single-pass, 24k-char truncation).

### 3.3 Reference: Diligent GovernAI suite (2025) — six modules

- **Smart Builder** — creates polished board presentations automatically from uploaded docs/spreadsheets/PDFs; CSV/XLSX uploads, multilingual, rich-text editing, one-click templates, direct sync to Boards.
- **Smart Risk Scanner** — identifies legal, compliance and operational risks (litigation, breaches, incomplete disclosures) with explanations and direct review links.
- **Smart Summary** — transforms dense board packs into concise strategic summaries highlighting key themes and discussion prompts.
- **Smart Prep** — prepares boards for meetings with actionable insights and ready-to-discuss materials.
- **Smart Minutes** — generates minutes from notes/transcripts, exports with customizable Word templates, tracks action items with automated reminders, voice-matching options.
- **Smart News** — curated industry, peer, and market insights delivered to leadership.

Plus: **Diligent Data Room** (AI summaries cut due-diligence review from weeks to days) and **Mobile Questionnaires** (D&O disclosures, conflict statements, board assessments).

### 3.4 Reference: other leaders

- **Nasdaq Boardvantage** — AI-powered summarization, AI minutes, executive summaries, AI-driven "Intelligent Insights"; integrations with Zoom, Teams, Outlook, Google Calendar, Google Drive.
- **OnBoard** — agenda building, secure messaging, voting & approvals, D&O questionnaires, meeting analytics, skills tracking, automated minutes via OnBoard AI (instant transcription → draft minutes), minutes builder, video-conferencing integration.

---

## 4. Current AI Implementation

**File:** `app/Modules/Governance/Board/Services/GovernanceAiService.php`

A thin wrapper around an OpenAI-compatible chat/transcription API. Mirrors the existing `FleetVisionService` pattern:
- Reads key from `config('ai.providers.openai.*')` with env fallback (`OPENAI_API_KEY`).
- Default chat model: `gpt-4.1-mini`; transcription model: `whisper-1`.
- **Degrades gracefully** — returns `['available' => false, ...]` when unconfigured, so every AI feature still works as a manual workflow.

**Methods implemented:**
| Method | Purpose | Notes |
|---|---|---|
| `transcribe()` | Audio → text with segments | Whisper does **not** diarise; speaker labels are heuristic (`(id % 3) + 1`) — line 65 — meant for human correction |
| `generateMinutes()` | Transcript + agenda items → per-item discussion summaries + decisions | Strict-JSON response; input truncated to 24,000 chars |
| `extractActions()` | Text → action items with suggested owner + due date | Strict-JSON; truncated to 24,000 chars |
| `summarise()` | Single body of text → 4–6 bullet summary | Truncated to 24,000 chars |
| `answer()` | Question + context → grounded answer | Uses **only** supplied context; truncated to 24,000 chars |

**Low-level helpers:** `chat()` (temperature 0.2), `chatJson()` (temperature 0.1, `response_format: json_object`). All exceptions logged via `Log::warning` and degrade to a graceful error.

**Architectural observations / limitations:**
- Single-pass, fixed 24k-char truncation — large packs/transcripts are silently cut off.
- No retrieval (RAG) — `answer()` can only see whatever context the caller stuffs in.
- No diarization — speaker attribution is a placeholder.
- Single provider/model hardcoded via config — no per-tenant provider choice or self-hosted option.
- No audit logging of AI invocations (separate from general activity logs).

---

## 5. AI Improvement Opportunities (Full Roadmap)

Ordered by impact-to-effort. Tiers 1–3.

### Tier 1 — Quick wins (extend the existing service)

**a) Smart Risk Scanner (highest differentiation).**
Add a `scanRisks(text)` method that flags litigation / breach / disclosure / financial red-flag language in packs, minutes and resolutions, returning `{snippet, risk_type, severity, explanation, location}`. This is Diligent's flagship 2025 feature and directly serves the King IV "risk governance" principles.

**b) RAG for the assistant.**
`answer()` currently stuffs raw context and truncates at 24k chars — it can't see the whole corpus. Add embeddings over documents/minutes/resolutions so the assistant retrieves the *right* records before answering, with real citations. Turns a toy Q&A into a genuine "ask your board archive" tool.

**c) Pack-level pre-read / summarizer.**
mkhaya-hub summarizes single documents; leaders summarize the *whole board pack* into a one-page "what you need to decide" brief per director. Wrap `summarise()` to roll up all pack items + agenda into a director-facing pre-read.

**d) Speaker diarization.**
Line 65 admits speaker labels are a heuristic placeholder (`% 3`). Swap Whisper for a diarizing model (e.g. AssemblyAI / Deepgram / `gpt-4o-transcribe` with diarization) so minutes attribute statements correctly — currently a manual cleanup burden.

### Tier 2 — New AI capabilities

**e) Agenda drafting assistant.**
Generate a draft agenda from committee type + prior meeting's unfinished actions + standing items + open resolutions. All that data already exists; high-value "Smart Builder" analogue.

**f) Minutes quality/compliance checker.**
Before chair approval, AI checks minutes for completeness (every decision item has an outcome, every resolution recorded, quorum noted, conflicts logged) against King IV — a guardrail competitors don't all have.

**g) Action-item intelligence.**
mkhaya-hub already tracks actions, escalations and reminders. Add AI that drafts reminder/escalation text, predicts at-risk actions (overdue-likelihood from history), and auto-summarizes evidence uploads for approver review.

**h) Meeting effectiveness & sentiment analytics.**
From transcripts: speaking-time balance, decision velocity, unresolved-discussion flags. Feeds board self-evaluation — a board-eval feature leaders are racing to add.

### Tier 3 — Strategic / governance-grade

**i) Skills-matrix gap analysis.**
AI maps current directors against a target competency/diversity matrix and recommends recruitment priorities — pairs with a new skills-matrix module.

**j) Regulatory/news monitoring (Diligent "Smart News" analogue).**
Curated sector/peer/regulatory updates pushed into the board workflow, tied to King IV principles.

**k) AI-governance oversight tooling.**
Boards in 2026 are being asked to *oversee* AI itself. An "AI register" (where the org uses AI, risk ratings, controls) inside the governance module is an emerging, under-served need — and a forward-looking selling point.

### 5.1 Roadmap summary table

| # | Opportunity | Tier | Effort | Differentiation | Builds on existing |
|---|---|---|---|---|---|
| a | Smart Risk Scanner | 1 | Low | Very high | `GovernanceAiService` |
| b | RAG-grounded assistant | 1 | Medium | High | `answer()` |
| c | Pack-level pre-read summarizer | 1 | Low | Medium | `summarise()` |
| d | Speaker diarization | 1 | Low–Med | Medium | `transcribe()` |
| e | Agenda drafting assistant | 2 | Medium | High | Agenda + actions data |
| f | Minutes quality/compliance checker | 2 | Medium | High | Minutes + King IV |
| g | Action-item intelligence | 2 | Medium | Medium | Resolution actions |
| h | Meeting effectiveness & sentiment | 2 | Medium | Medium | Transcripts |
| i | Skills-matrix gap analysis | 3 | High | High | New module |
| j | Regulatory/news monitoring | 3 | High | Medium | New integration |
| k | AI-governance oversight register | 3 | High | High (emerging) | New module |

---

## 6. AI Guardrails

Do these alongside **any** AI work:

- **Confidentiality.** Packs are classified `restricted`/`confidential`. Before sending content to OpenAI, ensure a no-training data-processing agreement, or offer a self-hosted / Azure OpenAI option — boards will demand it. Make the AI provider configurable per tenant.
- **Human-in-the-loop.** Keep every AI output as a *draft* requiring sign-off (already done for minutes — extend the pattern). Never let AI auto-finalize minutes or resolutions.
- **Audit the AI.** Log every AI invocation (who, what record, what model, what output) into the existing access-log/audit pattern — essential for governance defensibility.

---

## 7. Bottom Line

The module is **genuinely competitive on feature breadth** — King IV compliance, offline annotations, and voting integrity are standout strengths. The gaps versus Diligent / Boardvantage / OnBoard are:

1. Native mobile apps
2. Questionnaires + skills-matrix analytics
3. Calendar/video integration
4. AI depth

The AI today is a **thin but well-architected starting point**. The single highest-leverage AI move is a **Smart Risk Scanner + RAG-grounded assistant**, because both extend the service that already exists, both map directly onto King IV, and both are exactly what the market leaders charge premium prices for in 2025–26.

---

## 8. Sources

- [Diligent Boards AI / GovernAI](https://www.diligent.com/features/boards/boards-ai)
- [Diligent 2025 AI innovations](https://www.diligent.com/resources/blog/diligent-ai-innovations-2025-highlights)
- [Diligent Boards updates: smarter AI, automated reminders, faster board prep](https://www.diligent.com/resources/blog/diligent-ai-innovations-faster-board-prep)
- [Nasdaq Boardvantage](https://www.nasdaq.com/solutions/governance/boardvantage)
- [OnBoard vs Boardvantage (G2)](https://www.g2.com/compare/nasdaq-boardvantage-vs-onboard-board-management-software)
- [Corporate governance trends 2026 (Diligent)](https://www.diligent.com/resources/blog/corporate-governance-trends)
- [Board assessment / questionnaires (Diligent)](https://www.diligent.com/features/boards/questionnaires)
- [The ever-expanding scope of the board skills matrix (Directors & Boards)](https://www.directorsandboards.com/articles/board-composition-article/the-ever-expanding-scope-of-the-board-skills-matrix/)
- [AI Governance Maturity Matrix (California Management Review)](https://cmr.berkeley.edu/2025/05/ai-governance-maturity-matrix-a-roadmap-for-smarter-boards/)
