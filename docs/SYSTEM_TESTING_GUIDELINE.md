# Mkhaya Hub — System Testing Guideline (Page‑by‑Page)

**Purpose:** A structured, page‑by‑page test plan for the whole system. The tester works through **one category at a time**, then **one module at a time**, and within every module follows the same rhythm:

> **1) Settings & Setup → 2) Core Process → 3) Reports / Analytics**

Always configure a module's settings first (so the process pages have something to work with), then exercise the day‑to‑day process, then verify the reports at the end.

**Category test order:**
1. System Administration
2. Human Resources
3. Supply Chain Management (Supply Management)
4. Finance
5. Asset Management (Assets)
6. Operations & Support

---

## How to use this document

- Tick each page as you complete it. A page is **PASS** only when every applicable check below passes.
- Page routes are shown in `(brackets)` — append them to the base URL, e.g. `https://<host>/assets/settings`.
- Test as the **roles** that use each module (admin, manager, employee/self‑service). Note any page that should be hidden for a role but is visible, or vice‑versa.
- Log every defect with: module, page, route, role, steps, expected vs actual, and a screenshot.

### Standard per‑page checklist (apply to EVERY page)

| # | Check | Notes |
|---|-------|-------|
| 1 | **Loads** without error (no blank screen, no 500, no console errors) | |
| 2 | **Navigation/ribbon** highlights correctly; breadcrumbs/title correct | |
| 3 | **Data displays** correctly (lists, totals, dates, currency = ZAR/R) | |
| 4 | **Create** — required‑field validation, success message, record appears | |
| 5 | **Edit/Update** — changes persist after reload | |
| 6 | **Delete/Cancel** — confirmation prompt, record removed/soft‑deleted | |
| 7 | **Search / filter / sort / pagination** behave correctly | |
| 8 | **Approvals/workflow** (where present) route to the right approver and update status | |
| 9 | **Permissions** — page respects the user's role/access level | |
| 10 | **File upload/download/export** (PDF/Excel/CSV) opens & contains correct data | |
| 11 | **AI features** (where present) return a sensible result or degrade gracefully if AI is unconfigured | |
| 12 | **Responsive** — usable on a smaller screen; no broken layout | |

> **AI note:** Several modules use AI (CRM Copilot, Payroll AI, Performance AI Insights, Disciplinary AI, LMS AI Chat, Contracts AI Copilot, Whistleblower AI, Claims AI). If the AI key is not configured these pages must show a clear "AI not configured" message rather than crash. Test both states where possible.

> **Scheduled Reports note:** Modules with a *Scheduled Reports* page require the server scheduler/queue worker to be running for emails to actually send. Verify the schedule is created/saved; confirm delivery only if the worker is active.

---
---

# 1. System Administration

> Test this category first — it creates the company structure, users, roles and system settings that every other module depends on.

## 1.1 System Settings `[system_settings]`
**Settings & Setup (do these first)**
- [ ] Company Identity (`settings/setup`) — company name, logo, contact details save & display elsewhere
- [ ] Document Types (`settings/document-types`)
- [ ] Public Holidays (`settings/public-holidays`) — add/edit; verify they reflect in Leave/Attendance
- [ ] Modules Management (`settings/category`) — categories/modules/ribbons visibility toggles
- [ ] Offline Guard Policy (`settings/offline-policy`)
- [ ] Platform Admins (`settings/platform-admins`)

**Maintenance**
- [ ] System Restoration (`settings/restoration`) — backup/restore behaves safely (test cautiously, non‑prod)

## 1.2 Company Settings `[company_settings]`
**Settings & Setup**
- [ ] Organisation Structure (`settings/organization`) — divisions/units/departments tree
- [ ] Positions (`settings/positions`) — create positions; used by HR/Recruitment

## 1.3 Roles & Permissions `[roles_permissions]`
**Setup**
- [ ] Roles (`settings/roles`) — create role, assign permissions
- [ ] Permissions (`settings/permissions`) — list/assign; verify a permission change actually gates a page

## 1.4 User Management `[user_management]`
**Setup / Process**
- [ ] Manage Users (`settings/manage-users`) — create user, link to HR person, set role
- [ ] Users Access (`settings/user-access`) — grant category/module/ribbon access; confirm nav updates for that user
- [ ] Profile Updates Approval (`settings/profile-updates-approval`) — approve/reject a self‑service profile change

## 1.5 Reports (System) `[reports]`
**Reports (at the end)**
- [ ] User Rights (`settings/user-access`) report
- [ ] System Usage (`settings/manage-users/report`)
- [ ] Audits (`settings/audits`) — audit trail records actions

---
---

# 2. Human Resources

> Depends on System Admin (users, positions, org structure, public holidays).

## 2.1 Leave Management `[leave_management]`
**1) Settings & Setup**
- [ ] Leave Types (`leave-management/setup/leave-types`)
- [ ] Leave Profiles (`leave-management/setup/profiles`)
- [ ] Approval Config (`leave-management/setup/approval-config`)
- [ ] Configuration (`leave-management/setup/configuration`)

**2) Core Process**
- [ ] My Dashboard (`leave-management`) / Self Service (`my`)
- [ ] Apply for Leave (`leave-management/apply`) — submit, validation against balance & holidays
- [ ] My Leave (`leave-management/my-applications`)
- [ ] Balances (`leave-management/balances`)
- [ ] Team / Calendar (`leave-management/calendar`)
- [ ] Pending Approvals / Approvals (`leave-management/approvals`) — approve/reject flows
- [ ] Manager Dashboard (`leave-management/manager-dashboard`)
- [ ] HR Dashboard (`leave-management/hr-dashboard`)
- [ ] Workforce Dashboard (`leave-management/workforce-dashboard`)
- [ ] Manual Allocations (`leave-management/manual-allocations`)
- [ ] Leave Import (`leave-management/import`) — template upload

**3) Reports**
- [ ] Reports (`leave-management/reports`)
- [ ] Executive Dashboard (`leave-management/executive-dashboard`)
- [ ] Scheduled Reports (`leave-management/setup/scheduled-reports`)

## 2.2 Time & Attendance `[time_attendance]`
**1) Settings & Setup**
- [ ] Settings (`attendance/settings`)
- [ ] Setup / Shifts (`attendance/shifts`)
- [ ] Roster / Rosters (`attendance/roster`)
- [ ] Policy & Compliance (`attendance/policy`)

**2) Core Process**
- [ ] My Dashboard / Dashboard (`attendance`)
- [ ] Clock In/Out & Clocking Kiosk (`attendance/clocking/kiosk`)
- [ ] Records (`attendance/records`)
- [ ] Missed Clockings (`attendance/clocking/missed`)
- [ ] Discipline (`attendance/discipline`)
- [ ] Workforce / Workforce Health (`attendance/workforce`)
- [ ] Wellness (`attendance/wellness`)
- [ ] Productivity (`attendance/productivity`)
- [ ] Intelligence (`attendance/intelligence`)
- [ ] Audit & Logs (`attendance/audit`)

**3) Reports & Analytics**
- [ ] Analytics (`attendance/analytics`)
- [ ] Late‑Arrival Heatmap (`attendance/analytics/heatmap`)
- [ ] Absenteeism Cost (`attendance/analytics/absenteeism-cost`)
- [ ] BCEA Compliance (`attendance/bcea`)
- [ ] Reports (`attendance/reports`)
- [ ] Scheduled Reports (`attendance/reports/scheduled`)
- [ ] Executive (`attendance/executive`)

## 2.3 Boarding (On/Pre‑boarding) `[boarding]`
**1) Setup**
- [ ] Onboarding Templates (`onboarding/templates`)

**2) Process**
- [ ] Pre‑Boarding (`pre-boarding`)
- [ ] Onboarding (`onboarding`) — task lists, document provisioning

## 2.4 Offboarding `[offboarding]`
**1) Setup**
- [ ] Templates (`offboarding/templates`)

**2) Process**
- [ ] Offboarding Plans (`offboarding`) — exit checklist / interview

**3) Reports**
- [ ] Analytics (`offboarding/analytics`)
- [ ] Reports (`offboarding/reports`)

## 2.5 Recruitment `[recruitment]`
**1) Settings & Setup**
- [ ] Approval Workflows (`recruitment/settings/approval-workflows`)
- [ ] Social Accounts (`recruitment/settings/social-accounts`)

**2) Core Process**
- [ ] Dashboard (`recruitment/dashboard`)
- [ ] Requisitions (`recruitment/requisitions`) → Approvals (`recruitment/approvals/pending`)
- [ ] Jobs (`recruitment/jobs`) / Vacancies (`recruitment/vacancies`)
- [ ] Internal Job Board (`recruitment/internal/job-board`)
- [ ] Candidates (`recruitment/candidates`)
- [ ] Offers (`offers`)
- [ ] Succession Planning (`recruitment/succession`)

**3) Reports & Analytics**
- [ ] Analytics (`recruitment/analytics`)
- [ ] Reports (`recruitment/reports`)

## 2.6 Performance Management `[performance_appraisal]`
**1) Settings & Setup**
- [ ] Settings (`performance/settings`)
- [ ] Templates (`performance/templates`)
- [ ] Rating Scales (`performance/rating-scales`)
- [ ] Cycles (`performance/cycles`)
- [ ] Competencies (`performance/competencies`)
- [ ] Policy (`performance/policy`)

**2) Core Process**
- [ ] Dashboard (`performance`) / My Dashboard (`performance/my-dashboard`) / Team (`performance/manager-dashboard`)
- [ ] Goals (`performance/goals`) + Goal Tree (`performance/goals/tree`)
- [ ] KPIs (`performance/kpis`)
- [ ] Check‑Ins (`performance/checkins`)
- [ ] Feedback (`performance/feedback`)
- [ ] 360 Reviews (`performance/360`)
- [ ] Probation (`performance/probation`)
- [ ] PIP (`performance/pip`)
- [ ] Calibration (`performance/calibration`) + 9‑Box Grid (`performance/nine-box`)
- [ ] Development (`performance/development`) / Succession (`performance/succession`) / Talent Growth (`performance/talent-growth`)
- [ ] Wellness (`performance/wellness`)
- [ ] Recognition (`performance/recognition`)
- [ ] Compensation (`performance/compensation`)
- [ ] AI Insights (`performance/ai`)

**3) Reports & Analytics**
- [ ] Reports (`performance/reports`)
- [ ] Executive Graphs (`performance/reports/executive`)
- [ ] DEI Analytics (`performance/dei`)
- [ ] Scheduled Reports (`performance/reports/scheduled`)

## 2.7 Disciplinary & Misconduct `[disciplinary]`
**1) Setup**
- [ ] Setup (`disciplinary/setup`)
- [ ] Policy Enforcement (`disciplinary/policy-enforcement`)

**2) Core Process**
- [ ] Dashboard (`disciplinary`) / My Dashboard (`disciplinary/my-dashboard`)
- [ ] Incidents (`disciplinary/incidents`)
- [ ] Cases (`disciplinary/cases`)
- [ ] Investigations (`disciplinary/investigations`)
- [ ] Hearings (`disciplinary/hearings`)
- [ ] Progressive Discipline: Warnings (`disciplinary/progressive-discipline/warnings`), Suspensions (`.../suspensions`), Escalations (`.../escalations`)
- [ ] Corrective Actions (`disciplinary/corrective-actions`)
- [ ] Appeals (`disciplinary/appeals`)
- [ ] Notifications & Alerts (`disciplinary/notifications-escalations`)
- [ ] AI Insights (`disciplinary/ai-insights`)
- [ ] Risk / Workforce Intelligence (`disciplinary/risk-intelligence`, `disciplinary/workforce-intelligence`)

**3) Reports & Analytics**
- [ ] Reports (`disciplinary/reports`)
- [ ] Scheduled Reports (`disciplinary/reports/scheduled`)
- [ ] Executive Dashboard (`disciplinary/executive-dashboard`)
- [ ] AI Workforce Intelligence (`disciplinary/executive-ai-insights`)

## 2.8 Employee Surveys `[surveys]`
**1) Setup**
- [ ] Templates (`surveys/templates`)
- [ ] Recurring Schedules (`surveys/schedules`)

**2) Process**
- [ ] All Surveys (`surveys`) — create, publish, respond
- [ ] My Surveys (`surveys/my/dashboard`)

**3) Reports**
- [ ] Reports (`surveys/reports`)
- [ ] Scheduled Reports (`surveys/scheduled-reports`)

## 2.9 LMS (Learning Management) `[learning_management]`
**1) Settings & Setup**
- [ ] Categories (`lms/admin/categories`)
- [ ] Compliance Rules (`lms/admin/compliance/rules`)
- [ ] Cert Templates (`lms/admin/cert-templates`) / Certification Programs (`lms/admin/certifications/programs`)
- [ ] Onboarding Rules (`lms/admin/onboarding/rules`)
- [ ] Question Banks (`lms/admin/question-banks`)
- [ ] SDFs (`lms/admin/sdfs`) / Registered Persons (`lms/admin/registered-persons`)

**2) Core Process — Admin**
- [ ] Admin Dashboard (`lms/admin/dashboard`)
- [ ] Courses / Course Builder (`lms/admin/courses`)
- [ ] Learning Paths (`lms/admin/learning-paths`)
- [ ] Assessments (`lms/admin/questionnaires`) / Marking Queue (`lms/admin/marking`)
- [ ] Enrollments (`lms/admin/enrollments`)
- [ ] Certifications (`lms/admin/certifications`)
- [ ] Skills Library (`lms/admin/skills`) / Skills Matrix (`lms/admin/skills-matrix`) / Succession Readiness (`lms/admin/succession`)
- [ ] Onboarding Training (`lms/admin/onboarding`)
- [ ] PA Training (`lms/admin/pa-training`) + Recommendations / Dev Plans
- [ ] Compliance Dashboard (`lms/admin/compliance`) / Policy Enforcement (`lms/admin/policy-violations`)
- [ ] AI Recommendations (`lms/admin/ai-recommendations`) / AI Chat (`lms/admin/ai-chat`, `/manager`, `/hr`)

**Core Process — Learner/Manager**
- [ ] My Dashboard (`lms/my-dashboard`) / Team Dashboard (`lms/manager-dashboard`) / Executive View (`lms/executive-dashboard`)
- [ ] Course Catalog (`lms/catalog`) → My Learning (`lms/my-learning`) → My Learning Plan (`lms/my-learning-plan`)
- [ ] My Portfolios (`lms/my-portfolios`)

**SETA / Compliance (SA‑specific)**
- [ ] QCTO Hub (`lms/admin/qcto`) / Learnerships (`lms/admin/learnerships`)
- [ ] WSP / ATR (`lms/admin/wsp-atr`) / B‑BBEE Skills Dev (`lms/admin/bbee`)
- [ ] Moderation (`lms/admin/moderation`) / NLRD Submissions (`lms/admin/nlrd`) / PoE Administration (`lms/admin/poe-admin`) / AQP‑EISA (`lms/admin/aqp`)

**3) Reports & Analytics**
- [ ] Reports (`lms/admin/reports`)
- [ ] BI Analytics (`lms/admin/analytics`)
- [ ] Effectiveness (`lms/admin/effectiveness`) / Training Costs (`lms/admin/training-costs`)
- [ ] Scheduled Reports (`lms/admin/scheduled-reports`)

## 2.10 Recognition & Rewards `[recognition]`
**1) Setup**
- [ ] Categories (`recognition/admin/categories`)

**2) Process**
- [ ] Recognition Wall (`recognition`) / My Profile (`recognition/my-profile`)

**3) Reports**
- [ ] Leaderboard (`recognition/admin/leaderboard`)

## 2.11 Whistleblower & Ethics `[whistleblower]`
**1) Settings & Setup**
- [ ] Settings (`whistleblower/settings`)
- [ ] Categories (`whistleblower/categories`)

**2) Core Process**
- [ ] Dashboard (`whistleblower/dashboard`)
- [ ] Report a Case (`whistleblower/cases/create`) — incl. anonymous public report
- [ ] Cases (`whistleblower/cases`)
- [ ] Investigations (`whistleblower/investigations`)
- [ ] Compliance (`whistleblower/compliance`)
- [ ] Activity Log (`whistleblower/activity-log`)

**3) Reports & Analytics**
- [ ] Ethics Analytics (`whistleblower/analytics`)
- [ ] Executive Dashboard (`whistleblower/executive`)

---
---

# 3. Supply Chain Management (Supply Management)

## 3.1 Supplier Management `[supplier_management]`
**1) Settings & Setup**
- [ ] Settings (`suppliers/settings`)

**2) Core Process**
- [ ] Dashboard (`suppliers`)
- [ ] Add Supplier (`suppliers/create`) → Suppliers (`suppliers/list`)
- [ ] Onboarding (`suppliers/onboarding`) → KYC Approvals (`suppliers/kyc`)
- [ ] Performance / Appraisals (`suppliers/appraisals`)
- [ ] Corrective Actions / SCARs (`suppliers/scars`)
- [ ] Sourcing (RFx) (`suppliers/sourcing`)
- [ ] Purchase Orders (`suppliers/purchase-orders`)
- [ ] Invoices (`suppliers/invoices`)
- [ ] Search (`suppliers/search`)

**3) Reports & Analytics**
- [ ] Spend Analysis (`suppliers/spend/report`)
- [ ] Reports (`suppliers/reports`)
- [ ] Analytics (`suppliers/analytics`)
- [ ] Scheduled Reports (`suppliers/scheduled-reports`)

## 3.2 Purchase Requests / Procurement `[procurement_requests]`
**1) Settings & Setup**
- [ ] Policy Rules (`procurement/policies`) — approval matrix & thresholds

**2) Core Process**
- [ ] Dashboard (`procurement/dashboard`)
- [ ] New Request (`purchase-requests/create`) → Requests (`purchase-requests`)
- [ ] Approvals (`procurement/approvals`)
- [ ] Requisitions (`requisitions`)
- [ ] Purchase Orders (`purchase-orders`)
- [ ] Invoices (`invoices`)

**3) Reports & Analytics**
- [ ] Spend Intelligence (`procurement/intelligence`)

## 3.3 Contract Management `[contract_management]`
**1) Settings & Setup**
- [ ] Settings (`contracts/settings`)
- [ ] Templates (`contracts/templates`)
- [ ] Clause Library (`contracts/clauses`)

**2) Core Process**
- [ ] Dashboard (`contracts`)
- [ ] New Contract (`contracts/create`) → Contracts (`contracts/list`)
- [ ] Bulk Upload (`contracts/bulk-upload`)
- [ ] Requests (`contracts/requests`) → Approvals (`contracts/approvals`)
- [ ] Search (`contracts/search`)
- [ ] Appraisals (`contracts/appraisals`)
- [ ] AI Copilot (`contracts/copilot`)

**3) Reports & Analytics**
- [ ] Reports (`contracts/reports`)
- [ ] Analytics (`contracts/analytics`)
- [ ] Scheduled Reports (`contracts/scheduled-reports`)

## 3.4 Budget Management `[budget_management]`
**1) Settings & Setup**
- [ ] Settings (`budgets/settings`)

**2) Core Process**
- [ ] Dashboard (`budgets`)
- [ ] New Budget (`budgets/create`) → Budgets (`budgets/list`)

## 3.5 Supplier Marketplace `[marketplace]`
**1) Settings & Setup**
- [ ] Integrations (`marketplace/integrations`) — connect/mock provider, sync catalog

**2) Core Process**
- [ ] Browse (`marketplace`)
- [ ] Cart (`marketplace/cart`)
- [ ] Orders (`marketplace/orders`)

---
---

# 4. Finance

## 4.1 Petty Cash `[petty_cash]`
**1) Settings & Setup**
- [ ] Settings (`petty-cash/settings`)
- [ ] Policy Violations config (`petty-cash/settings/violations`)

**2) Core Process**
- [ ] Dashboard (`petty-cash`)
- [ ] New Float (`petty-cash/floats/create`) → Floats (`petty-cash/floats`)
- [ ] New Request (`petty-cash/requests/create`) → Requests (`petty-cash/requests`)
- [ ] Reconciliations (`petty-cash/reconciliations`)

**3) Reports**
- [ ] Reports (`petty-cash/reports`)

## 4.2 Staff Loans `[staff_loans]`
**1) Settings & Setup**
- [ ] Settings (`staff-loans/settings`)
- [ ] Policy Violations config (`staff-loans/settings/violations`)

**2) Core Process**
- [ ] Dashboard (`staff-loans`)
- [ ] New Loan (`staff-loans/loans/create`) → Staff Loans (`staff-loans/loans`)
- [ ] New Advance (`staff-loans/advances/create`) → Salary Advances (`staff-loans/advances`)

## 4.3 Claims Management `[claims]`
**1) Settings & Setup**
- [ ] Setup (`claims/setup`)
- [ ] Delegations (`claims/approvals/delegations`)

**2) Core Process**
- [ ] Dashboard (`claims`)
- [ ] New Claim (`claims/my/create`) → My Claims (`claims/my`)
- [ ] Approvals (`claims/approvals`)
- [ ] Payment Processing (`claims/payments`)
- [ ] AI Intelligence (`claims/ai-intelligence`)

**3) Reports & Analytics**
- [ ] Reports (`claims/reports`)
- [ ] Executive Dashboard (`claims/executive`)
- [ ] Scheduled Reports (`claims/reports/scheduled`)

## 4.4 Payroll `[payroll]`
**1) Settings & Setup**
- [ ] Settings (`payroll/settings`)

**2) Core Process**
- [ ] Dashboard (`payroll/dashboard`)
- [ ] New Run (`payroll/runs/create`) → Payroll Runs (`payroll/runs`)
- [ ] Approvals (`payroll/approvals`)
- [ ] Auto‑Corrections (`payroll/corrections`)
- [ ] Anomalies (`payroll/anomalies`) / Cross‑Checks (`payroll/cross-checks`)
- [ ] Leakage Detection (`payroll/leakage`) / Ghost Employees (`payroll/ghost-employees`)
- [ ] Behaviour Analytics (`payroll/behavior`)
- [ ] Simulations (`payroll/simulations`) / Forecasting (`payroll/forecasting`)
- [ ] Compliance (`payroll/compliance`)
- [ ] Change Log (`payroll/change-log`)
- [ ] AI Assistant (`payroll/ai-assistant`)
- [ ] My Payslips (`payroll/my-payslips`) — self‑service

**3) Reports & Analytics**
- [ ] Analytics (`payroll/analytics`)
- [ ] Narrative Reports (`payroll/narrative-reports`)
- [ ] Reports (`payroll/reports`)
- [ ] Scheduled Reports (`payroll/settings/scheduled-reports`)

## 4.5 Finance Executive `[finance_executive]`
**Reports**
- [ ] Executive Dashboard (`finance/executive/dashboard`) — cross‑module finance roll‑up

---
---

# 5. Asset Management (Assets)

## 5.1 Assets `[asset_management]`
**1) Settings & Setup**
- [ ] General Settings (`assets/settings`)
- [ ] Categories (`assets/settings/categories`)
- [ ] Asset Types (`assets/settings/types`)
- [ ] Locations (`assets/settings/locations`)

**2) Core Process**
- [ ] Dashboard (`assets/dashboard`)
- [ ] Asset Register (`assets`) — create/assign asset
- [ ] Maintenance (`assets/maintenance`)
- [ ] Depreciation (`assets/depreciation`)
- [ ] Procurement (`assets/procurement`)
- [ ] Exit Clearances (`assets/exit-clearances`)
- [ ] Misuse Detection (`assets/misuse-flags`)
- [ ] Doc Extraction (`assets/document-extractions`)

**3) Reports & Analytics**
- [ ] Utilization (`assets/utilization-dashboard`)
- [ ] Compliance (`assets/compliance-dashboard`)
- [ ] Reports (`assets/reports`)
- [ ] Scheduled Reports (`assets/settings/scheduled-reports`)

## 5.2 Fleet Management `[fleet_management]`
**1) Settings & Setup**
- [ ] Setup (`fleet/setup`)
- [ ] Projects (`fleet/projects`)
- [ ] Integrations (`fleet/integrations`) — telematics/fuel providers

**2) Core Process**
- [ ] My Dashboard (`fleet/my-dashboard`) / Dashboard (`fleet`)
- [ ] Manage Fleet (`fleet/vehicles`) → Vehicle Approvals (`fleet/vehicles/pending-approvals`)
- [ ] Search Bookings (`fleet/bookings`) → Bookings Approval (`fleet/approvals`)
- [ ] Fleet Cards (`fleet/cards`)
- [ ] Security Check (`fleet/security-check`)
- [ ] Alerts (`fleet/alerts`)

**3) Reports & Analytics**
- [ ] BI Analytics (`fleet/analytics`)
- [ ] Reports (`fleet/reports`)
- [ ] Scheduled Reports (`fleet/reports/scheduled`)

---
---

# 6. Operations & Support

## 6.1 CRM `[crm]`
**1) Settings & Setup**
- [ ] Settings (`crm/settings`)
- [ ] Custom Fields (`crm/custom-fields`) / Custom Objects (`crm/objects`)
- [ ] Email Templates (`crm/email-templates`)
- [ ] Targets (`crm/targets`)
- [ ] Automations (`crm/automations`)
- [ ] Integrations (`crm/integrations`) / API & Webhooks (`crm/webhooks`)
- [ ] Lead Mailboxes (`crm/mailboxes`)
- [ ] Suppressions (`crm/suppressions`)

**2) Core Process — Sales**
- [ ] Dashboard (`crm`) / My Dashboard (`crm/my-dashboard`) / My Day (`crm/my-day`)
- [ ] Leads (`crm/leads`) / Import (`crm/import`)
- [ ] Contacts (`crm/contacts`) / Accounts (`crm/accounts`)
- [ ] Deals (`crm/deals`) / Activities (`crm/activities`) / Calls (`crm/calls`)
- [ ] Copilot (`crm/copilot`)
- [ ] Sequences (`crm/sequences`)
- [ ] Approvals (`crm/approvals`)
- [ ] Duplicates (`crm/duplicates`) / Data Hygiene (`crm/data-hygiene`)
- [ ] Discussions (`crm/discussions`)

**Core Process — Marketing & Service**
- [ ] Campaigns (`crm/campaigns`)
- [ ] Surveys (`crm/surveys`) / Survey Planner (`crm/survey-plans`) / Survey AI (`crm/surveys/recommendations`)
- [ ] Complaints (`crm/complaints`)
- [ ] Meetings (`crm/meetings`)
- [ ] Account Health (`crm/account-health`)

**3) Reports & Analytics**
- [ ] Metrics (`crm/metrics`) / Analytics (`crm/analytics`)
- [ ] Forecast (`crm/forecast`)
- [ ] Team Dashboard (`crm/team-dashboard`) / Executive Dashboard (`crm/executive-dashboard`)
- [ ] Reports (`crm/reports`) / Scheduled Reports (`crm/scheduled-reports`)
- [ ] Audit Trail (`crm/audit`)

## 6.2 HelpDesk `[help_desk]`
**1) Settings & Setup**
- [ ] Settings (`helpdesk/settings`)
- [ ] Inboxes (`helpdesk/settings/inboxes`)
- [ ] Business Hours (`helpdesk/settings?tab=businesshours`)
- [ ] WhatsApp (`helpdesk/settings?tab=whatsapp`)

**2) Core Process**
- [ ] Dashboard (`helpdesk`)
- [ ] New Ticket (`helpdesk/tickets/create`) → Tickets (`helpdesk/tickets`)
- [ ] Live Chat (`helpdesk/livechat`) / Agent Chat (`helpdesk/agent-chat`) / Group Chat (`helpdesk/agent-groups`)
- [ ] Problems (`helpdesk/problems`) / Changes (`helpdesk/changes`)
- [ ] Knowledge Base (`helpdesk/kb`) / FAQ (`helpdesk/faq`)
- [ ] Announcements (`helpdesk/announcements`)

**3) Reports & Analytics**
- [ ] Analytics / CSAT (`helpdesk/analytics`)
- [ ] Performance (`helpdesk/performance`)
- [ ] Reports (`helpdesk/reports`)

## 6.3 E‑Sign `[esign]`
**Process**
- [ ] New Envelope (`esign/create`) — add document, signers, send
- [ ] Envelopes (`esign`) — track status, download signed copy

## 6.4 POPIA Compliance `[popia_compliance]`
**Process / Registers**
- [ ] Dashboard (`compliance`)
- [ ] Breach Register (`compliance/breaches`)
- [ ] Consent Register (`compliance/consents`)
- [ ] PHI Access Log (`compliance/phi-log`)

## 6.5 Content Management (CMS) `[content_management]`
**Process**
- [ ] Dashboard (`cms/dashaboard-cm`)
- [ ] CEO Message (`cms/ceo-message`)
- [ ] News (`cms/news`) / Announcements (`cms/announcements`) / Internal Notice (`cms/internal-notice`)
- [ ] Events (`cms/events`)
- [ ] Employee & Team Spotlight (`cms/employee-team`)
- [ ] Email Campaigns (`cms/campaigns`)

**Reports**
- [ ] Reports (`cms/reports-cm`)

## 6.6 Resources `[resources]`
**Process**
- [ ] Dashboard (`cms/dashaboard-resources`)
- [ ] Forms (`cms/forms`) / Templates (`cms/templates`) / Downloads (`cms/downloads`)
- [ ] Corporate Branding (`cms/corporate-branding`)
- [ ] NDA and Legal (`cms/nda-legal`)
- [ ] Employee Handbook (`cms/employee-handbook`)
- [ ] Health and Safety (`cms/health-safety`)
- [ ] Training Material (`cms/training-material`) / SOPs (`cms/sops`)

**Reports**
- [ ] Reports (`cms/reports-resources`)

---
---

## Appendix A — Cross‑module / end‑to‑end scenarios (run after page testing)

1. **New employee lifecycle:** Create user (System Admin) → Pre/Onboarding (HR) → Leave balance accrues → Clock in (Attendance) → appears in Payroll run → Performance goal set.
2. **Procure‑to‑pay:** Purchase Request → Approval (policy thresholds) → Requisition → Purchase Order → Supplier Invoice → Spend Intelligence reflects it.
3. **Asset assignment to clearance:** Register asset → assign to employee → Offboarding triggers Exit Clearance.
4. **Service ticket:** Inbound email/WhatsApp → HelpDesk ticket → assign/resolve → CSAT survey → Analytics.
5. **Ethics report:** Anonymous public report → Whistleblower case → Investigation → Compliance/Executive analytics.
6. **Approvals & permissions:** For each approval workflow, confirm the request reaches the correct approver and is blocked for unauthorized roles.

## Appendix B — Known cross‑cutting checks

- **Currency** displays as South African Rand (R / ZAR) everywhere.
- **Dates** respect the configured timezone and public holidays.
- **Scheduled Reports / AI hourly jobs** require the scheduler + queue worker running on the server.
- **Self‑service vs admin** views: pages prefixed *My* should only show the logged‑in user's data.
- **Empty states**: every list page should show a friendly empty state (not an error) before data exists.

---
*Generated from the live navigation structure (security_categories → security_modules → security_module_ribbons). If a ribbon is added/renamed, regenerate this list so the test plan stays in sync.*
