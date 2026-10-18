# Contract Management & Supplier Management — Functional Requirements

> **Source:** Reverse-engineered from the existing ERP implementation
> (`/Users/sbahlelujiva/Documents/work/erp`). This document captures **all
> complete, implemented functionality** of the two modules so they can be
> rebuilt/ported into mkhaya-hub. Partial or unwired features are listed
> separately at the end under *Out of Scope (Incomplete in source)* and are
> **not** part of the requirements.

---

# PART A — CONTRACT MANAGEMENT

## A1. Overview
A full contract lifecycle system: capture/upload contracts, run AI analysis on
them, route them through a configurable multi-level approval workflow, track
their status, milestones, expiry and renewals, appraise contract/supplier
performance, and report on the whole portfolio with dashboards and exports.

## A2. Contract Lifecycle & Statuses
The system supports the following contract states:

| Status | Meaning |
|--------|---------|
| `draft` | Being created / saved but not submitted |
| `published` | Created and submitted into the approval workflow |
| `pending` | Awaiting an approval decision |
| `active` | Fully approved and in effect |
| `expired` | Past its `end_date` |
| `renewed` | Has been renewed |
| `terminated` | Ended early |
| `cancelled` | Cancelled before execution |

Requirements:
- **R-A2.1** Save a contract as a **draft** (single or multiple at once) without submitting it.
- **R-A2.2** **Publish** a contract to start the approval workflow.
- **R-A2.3** Auto-generate a unique **contract number** (format `C{firstChar}{year}{5-digit-random}`).
- **R-A2.4** **Soft-delete** a contract, recording the deleting user (`deleted_by`) and IP (`deleted_ip`).
- **R-A2.5** **Restore** soft-deleted contracts (single, bulk, and a dedicated "destroyed contracts" listing).
- **R-A2.6** **Bulk delete** and **bulk restore** multiple contracts in one action.
- **R-A2.7** Maintain a full **status-change history** (`from_status`, `to_status`, `changed_by`, `changed_at`, `days_in_status`, notes) as an audit trail, with average-time-in-status analytics.
- **R-A2.8** Track **periodicity** (Monthly / Quarterly / Semiannually / Yearly) and renewal terms.

## A3. Contract Capture & Document Handling
- **R-A3.1** Create a contract from scratch using a **rich-text editor**.
- **R-A3.2** Create from a reusable **contract template** (templates have name, HTML content, active flag, owner).
- **R-A3.3** **Upload** contract documents — PDF, DOC, DOCX.
- **R-A3.4** **Bulk upload** multiple contract files at once.
- **R-A3.5** **Chunked upload** for large files (100MB+), with: upload-progress polling, status check, finalize, and cancel-in-progress.
- **R-A3.6** Extract text automatically from PDF (PdfParser) and DOCX (PhpWord) for AI analysis.

## A4. AI-Powered Contract Analysis
On upload, the contract text is analysed (OpenAI) and structured data is stored
against the contract. Extracted/analysed elements:

- **R-A4.1** Parties (names + roles), key contacts (name, role, contact info, protocols).
- **R-A4.2** Obligations & responsibilities (split: ours vs theirs), service standards, scope of services, service pricing & metrics.
- **R-A4.3** KPIs / key metrics, milestones (name, due date, length).
- **R-A4.4** Termination clauses, notice period, termination penalties.
- **R-A4.5** Payment terms, payment discounts, late-payment penalties, contract amount.
- **R-A4.6** Renewal terms, change-management procedures, confidentiality/NDA terms.
- **R-A4.7** Compliance requirements, regulatory requirements, compliance challenges, dispute-resolution process.
- **R-A4.8** Liabilities, indemnities, subcontracting policies, insurance requirements & coverage amount, reporting requirements, audit rights.
- **R-A4.9** **Scoring:** risk score, compliance score, complexity score (each 0–100) plus risk/complexity factor breakdowns.
- **R-A4.10** AI-generated **key points**, **recommendations**, and executive summary.
- **R-A4.11** **Analysis error tracking** — capture failures with type, severity (low/medium/high/critical), message, stack trace, excerpts; mark resolved with resolver, timestamp and notes.
- **R-A4.12** Generate AI **reports** across the portfolio: sentiment, language, review, comprehensive — with averaged risk/compliance/complexity scores and an executive summary; viewable and exportable.

## A5. Contract Requests (Requisitions)
A pre-contract request/approval flow that feeds contract creation.
- **R-A5.1** Create a request with title, summary, motivation/justification, contract type, length + unit (days/months/years), criticality, and value.
- **R-A5.2** Auto-generate a unique **request number**.
- **R-A5.3** **Classify** each request automatically by term length, value level, criticality level, discretionary-approval flag and special-attention flag (via a classification service).
- **R-A5.4** **Approve** or **reject** a request (with rejection reason / not-recommended reason / recommendation).
- **R-A5.5** Dedicated "requests awaiting approval" queue.
- **R-A5.6** Only **approved requests not yet linked to a contract** are available when creating a new contract.

## A6. Multi-Level Approval Workflow
- **R-A6.1** Configurable **approval steps** with sequence ordering, step name, and an **amount threshold** (`max_amount`).
- **R-A6.2** **Parent steps** and **child (sub) steps** within a step.
- **R-A6.3** **Amount-based routing** — a contract is routed to the appropriate step based on its value vs each step's threshold.
- **R-A6.4** Two **approval types**: (1) **Line Manager** — escalates to the approver's manager via `manager_id`; (2) **Multi-User** — routes to a configured set of approvers in sequence.
- **R-A6.5** **Role-based** approver assignment (via `role_id`).
- **R-A6.6** Record each decision (approved/declined) per step with **comment**, **approver** and **timestamp** (`contract_steps_statuses`, unique per contract+step+type).
- **R-A6.7** **Reset / rollback** the approval workflow to an earlier step.
- **R-A6.8** **Cancel** a contract mid-approval.
- **R-A6.9** Email notifications to approvers and the contract owner at each transition (action-required, stage-update, owner first-step, approved, rejected).

## A7. Expiry Management & Reminders
- **R-A7.1** Track `start_date` / `end_date`.
- **R-A7.2** **Expiry reminders** generated at intervals: 3 months, 2 months, 1 month, 2 weeks, 1 week before end date.
- **R-A7.3** **Expiry forecast** report bucketed by window: Critical (0–30 days), Warning (31–90), Caution (91–180), Long-term (180+), including total value at risk per bucket.
- **R-A7.4** **In-app notification centre**: list notifications, unread count, mark-one-as-read, mark-all-as-read.

## A8. Milestones & Performance Appraisal
- **R-A8.1** Create **milestones** (name, due date, status: pending/completed/overdue) per contract.
- **R-A8.2** Assign **performance evaluators** to a contract.
- **R-A8.3** **Appraisal templates** with customizable, weighted questions.
- **R-A8.4** Conduct an **appraisal** against a template, scoring each question, computing a `total_score`, recording appraiser and date, with status tracking.
- **R-A8.5** **Export an appraisal as PDF**.
- **R-A8.6** Appraisal reporting/analytics.

## A9. Reviews & Comments
- **R-A9.1** Add review comments to a contract with reviewer, comment, feedback + feedback category, complexity rating.
- **R-A9.2** Track **review time** (minutes) and start/completion timestamps; compute average review time.

## A10. Categorization
- **R-A10.1** Manage **contract types** (name, description, status) — CRUD plus activate/deactivate and restore.
- **R-A10.2** Manage **contract categories** (name, description) — CRUD.
- **R-A10.3** Filter contracts by type, category, supplier and status.

## A11. Financial Tracking
- **R-A11.1** Store negotiated **contract value** and **final contract value**.
- **R-A11.2** Track **payment status**; store parsed payment terms, discounts and late-payment penalties.
- **R-A11.3** Total value calculations and monthly value-trend analysis.

## A12. Dashboard & Reporting
- **R-A12.1** Main dashboard KPIs: total contracts, total value, value last month, published count, critical-expiry summary.
- **R-A12.2** Reports: status distribution, by type, by supplier, contract-request metrics, AI-analysis (risk/compliance/complexity), appraisal performance, expiry forecast, user-activity (approval activity).
- **R-A12.3** **Custom report builder** + generate.
- **R-A12.4** **Export** dashboard/reports to **PDF** and **Excel**.
- **R-A12.5** Chart-data and contract-list AJAX endpoints for interactive reports.

## A13. Notifications, Jobs & Audit
- **R-A13.1** Queued email sending (`ShouldQueue`) via a dispatched job supporting all approval email types.
- **R-A13.2** Email templates for: request, manager approval, approved applicant, rejection (status + contract), new contract, notify owner (incl. first-step), action-required, validation-failed, analysis-error.
- **R-A13.3** Console command to backfill status history for existing contracts.
- **R-A13.4** Soft-delete audit trail + status history + approval decision log + analysis-error resolution log.

## A14. Contract Data Model (entities)
`contracts`, `contractsAi` (AI extraction), `contract_types`, `contract_categories`,
`contract_requests`, `contract_approval_steps`, `contract_approval_steps_child`,
`contract_approval_steps_users`, `contract_milestones`,
`contract_performance_evaluators`, `contract_reviews`, `contract_reminders`,
`contract_steps_statuses`, `contract_appraisals`, `contract_notifications`,
`contract_status_histories`, `contracts_templates`, `ai_contract_reports`,
`contract_analysis_errors`. Most carry a `uuid`; contracts and milestones use soft deletes.

---

# PART B — SUPPLIER / VENDOR MANAGEMENT

## B1. Overview
End-to-end supplier lifecycle: invite & onboard suppliers, capture a full
KYC/compliance profile with documents, approve via an optional KYC workflow,
classify by industry & products, evaluate performance against weighted
appraisal templates, search/discover suppliers, and view a dashboard. Suppliers
link to contracts (Part A).

## B2. Supplier Onboarding (2-step)
- **R-B2.1 (Step 1 — invite):** Create an onboarding request with company name, registration number, supplier/contact name, contact email & phone, and the initiating employee. A **token** with **24-hour expiry** is generated and an onboarding email is sent.
- **R-B2.2** Validate against duplicates (company registration number, contact email — both unique).
- **R-B2.3** **Resend** the onboarding email (listing provided vs still-missing documents).
- **R-B2.4 (Step 2 — complete profile):** Via the tokenized link the supplier completes a multi-tab profile that creates the full `Supplier` record.
- **R-B2.5** Track onboarding status (pending → active / inactive) and which employee initiated it; track emails sent (`SupplierEmailNotification`).
- **R-B2.6** Manage onboarding records (list, create, edit, update, delete).

## B3. Supplier Profile (KYC) — Captured Data
- **R-B3.1 Company info:** company name, trading name, company registration number, tax reference number, VAT registration number, B-BBEE status level, business entity type, years in operation.
- **R-B3.2 Contact:** physical & postal address, phone, email (unique), website; **multiple contact persons** (first name, surname, email, tel; links to a user when activated).
- **R-B3.3 Banking:** bank name, account holder, account type, account number, branch code, proof of banking, payment terms (default "30 days").
- **R-B3.4 Directors/owners:** multiple directors (name, email [unique], tel), plus director ID copies.
- **R-B3.5 Industries & products:** multi-industry classification and multi-product/service offerings with item name and price.
- **R-B3.6 Governance/compliance flags:** conflict-of-interest declaration, fraud & corruption policy agreement, public liability insurance, health & safety compliance, references/client list.
- **R-B3.7 Company structure:** parent company, subsidiaries, joint ventures, government ownership (5%+ disclosure), PEP identification.
- **R-B3.8 Economic / sanctions screening:** company, leadership, partner sanctions status, US-persons involvement.
- **R-B3.9 Ethics & compliance:** compliance officer, compliance program, code of ethics (file), whistleblowing procedure (file), training records, third-party due diligence, subcontractor compliance clauses.
- **R-B3.10 Litigation:** active litigation tracking.
- **R-B3.11 Certifications:** ISO, SABS, environmental compliance.

## B4. Compliance Documents
- **R-B4.1** Configure **document types** (name, active flag, **compulsory** flag, optional template/sample attachment) — full CRUD, activate/deactivate, download template.
- **R-B4.2** Attach documents to a supplier with **attachment path** and optional **expiry date**.
- **R-B4.3** Supported document types include: company registration certificate, tax clearance certificate/PIN, letter of good standing, VAT registration proof, B-BBEE certificate/affidavit, CIDB registration, proof of banking, company profile, bank confirmation letter, proof of address, director ID copies, ISO/SABS/environmental compliance.

## B5. Supplier Lifecycle & States
| State | Code | Trigger |
|-------|------|---------|
| Pending | onboarding `current_status = 0` | onboarding created |
| Active | `is_active = 1` | activation (KYC off, or after KYC approval) |
| Refused/Inactive | `is_active = 2` | rejection |
| Pending KYC | `is_active = 3` | activation while KYC required |

- **R-B5.1 Activate:** if global KYC is **off** → set active, **create a supplier user account** (type 3, `parent_id = supplier.id`) for the primary contact, send approval email. If KYC is **on** → set "pending KYC" and notify assigned KYC approvers.
- **R-B5.2 Refuse:** set refused, send rejection email.
- **R-B5.3 Request more info:** email the supplier requesting missing information; keep pending.
- **R-B5.4 Soft delete / restore:** delete a supplier, list destroyed suppliers, restore.
- **R-B5.5 Edit** every section independently (basic info, ethics/structure, contacts, directors, products [sync], industries [sync]).

## B6. KYC Approval Workflow
- **R-B6.1** Global **KYC toggle** setting (on/off).
- **R-B6.2** Assign specific **users as KYC approvers** (only they can approve in KYC mode); they receive email notifications of pending suppliers.
- **R-B6.3** KYC approval queue listing suppliers in "pending KYC"; approve/reject with status (Pending/Approved/Rejected) — full CRUD on KYC approval records.
- **R-B6.4** On KYC approval the supplier is activated (and the user account created as in R-B5.1).

## B7. Classification (Industries & Products)
- **R-B7.1** Manage **industry types** (name, active) — CRUD + activate/deactivate.
- **R-B7.2** Manage **products/services** (item name, price, active) — CRUD + activate/deactivate.
- **R-B7.3** Associate suppliers with many industries and many products (sync).

## B8. Performance Management & Appraisals
- **R-B8.1 Performance templates** per **industry** (name, status).
- **R-B8.2 Question library** per template: question, description, **weight % (1–100, sum ≤ 100 per template)**, **minimum threshold (1–5)**; question text unique per template.
- **R-B8.3 Performance records** per supplier: admin comments, admin rating, self-appraisal percentage.
- **R-B8.4 Appraisals:** select an active supplier + industry + template, rate each question 1–5 stars.
  - Convert rating to percentage: `(rating / 5) × 100`.
  - Weighted score per question: `(percentage × weight%) / 100`.
  - Sum to a `total_score` (0–100); record pass/fail vs each question's minimum threshold; optional per-question comment; store all responses; record appraiser and date.
- **R-B8.5** AJAX helpers: get a supplier's industries + templates; get an industry's templates.

## B9. Search & Discovery
- **R-B9.1** Browse all suppliers.
- **R-B9.2** **Advanced search** filters: status, company name (LIKE), company registration number (LIKE), multiple products, multiple industries — returns full supplier rows (name, reg no, email, phone, address, status, products, industries); JSON or view.

## B10. Dashboard
- **R-B10.1** Stats: total suppliers, pending KYC approvals, performance reviews, and the 5 most recent supplier activities.

## B11. Supplier Categories
- **R-B11.1** Manage supplier categories (many-to-many with suppliers, status per category).

## B12. Emails & Notifications
Triggered emails (queued): onboarding invite (with required docs), add-new-onboarding
(provided vs missing docs), KYC-approver notification, status updates
(activation / refusal / additional-info request), supplier-account creation,
and generic supplier communications. Email sends are tracked.

## B13. Supplier Data Model (entities)
`suppliers` (soft deletes), `supplier_onboardings`, `supplier_contacts`,
`supplier_directors`, `supplier_documents`, `supplier_document_types`,
`supplier_industry_types`, `supplier_products`,
`supplier_performance_management`, `supplier_performances_templates`,
`supplier_question_libraries`, `supplier_appraisals`,
`supplier_appraisal_responses`, `supplier_kyc_approvals`, `supplier_settings`
(+ `supplier_settings_users` pivot), supplier categories pivot, plus related
one-to-one records: company structure, economic station, ethics/compliance,
litigation. Most carry a `uuid`.

## B14. Cross-Cutting (both modules)
- **R-X.1** Activity logging (Spatie) on key entities (e.g. supplier industry types, document types, products — logging name/price/status changes).
- **R-X.2** Soft deletes + restore on the primary entities (contracts, suppliers).
- **R-X.3** Form-request validation classes for every create/update path.
- **R-X.4** Service layer separating business logic from controllers.
- **R-X.5** Suppliers ↔ Contracts: a contract belongs to a supplier; supplier reporting includes contract counts/values.

---

# Out of Scope (Incomplete in source — exclude)
These exist partially in the ERP but are **not** wired up / complete, and are
therefore excluded from the requirements above:

- **Supplier Reminders** — model/service/views exist but all routes are commented out.
- **Supplier Reports** — controller/views exist but all routes are commented out.
- **Supplier portal (self-service views)** — supplier user type exists but no portal screens.
- **Document expiry alerts (supplier)** — `expiry_date` is stored but no reminder/alert job exists.
- **Contract edit** — `contract.edit` route exists but the controller method is not implemented.
- **Several stub notification classes** — superseded by Mailable classes.
