# Mkhaya Hub — Complete User Guide

> **Version:** 2026-05  
> **Purpose:** Step-by-step testing and usage guide for all modules.  
> **Audience:** End users, line managers, HR coordinators, HR managers, and system administrators.

---

## Table of Contents

1. [Roles & Access Levels](#1-roles--access-levels)
2. [Getting Started — Login & Navigation](#2-getting-started--login--navigation)
3. [System Administration](#3-system-administration)
4. [Performance Management](#4-performance-management)
5. [Leave Management](#5-leave-management)
6. [Time & Attendance](#6-time--attendance)
7. [Recruitment](#7-recruitment)
8. [Pre-Boarding & Onboarding](#8-pre-boarding--onboarding)
9. [Asset Management](#9-asset-management)
10. [Help Desk & Ticketing](#10-help-desk--ticketing)
11. [Policies & Compliance](#11-policies--compliance)
12. [Reports & Graphs — All Modules](#12-reports--graphs--all-modules)

---

## 1. Roles & Access Levels

The system uses five access levels. Every page (ribbon) is assigned a level — you only see pages your role can reach.

| Level | Who it is | Typical job title |
|-------|-----------|-------------------|
| **1 — Employee** | Any active staff member | All employees |
| **2 — Line Manager** | Manages a team | Team leader, supervisor |
| **3 — HR Coordinator** | Day-to-day HR operations | HR officer, HR coordinator |
| **4 — HR Manager / Executive** | Strategic HR and executive insight | HR manager, senior manager, C-suite |
| **5 — System Administrator** | Full system access, configuration | IT admin, super user |

> Throughout this guide the following shorthand is used:  
> 👤 **Employee** · 👔 **Manager** · 🧑‍💼 **HR Coordinator** · 📊 **HR Manager / Executive** · 🔧 **Admin**

---

## 2. Getting Started — Login & Navigation

### 2.1 Logging In

1. Open your browser and go to `http://localhost:8000` (or your server URL).
2. Enter your email address and password.
3. Click **Sign In**.

### 2.2 The Navigation Bar

After login you will see a **top navigation bar** with module tabs (e.g. *Performance*, *Leave*, *Attendance*, *Assets*).  
Each module tab expands into a **ribbon** — a row of icon buttons — one for each section of that module.

### 2.3 Switching Modules

Click the module name in the top nav to switch modules. The ribbon below it updates to show that module's sections.

### 2.4 What you see depends on your role

If a ribbon button or menu item is not visible, you do not have access to it. Contact your administrator to request access.

---

## 3. System Administration

> 🔧 **Admin only** unless noted.  
> URL: `/settings/...`

System Administration is where you configure the entire platform before rolling out any module.  
**Start here before testing any other module.**

---

### 3.1 Company Identity

**Path:** Settings → Company Identity  
**Who:** 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Settings** in the top nav. |
| 2 | Click **Company Identity** in the ribbon. |
| 3 | Upload your company logo. |
| 4 | Fill in company name, registration number, physical address, and contact details. |
| 5 | Click **Save**. |

---

### 3.2 Organisation Structure

**Path:** Settings → Organisation Structure  
**Who:** 🔧 Admin · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Organisation Structure** in the Settings ribbon. |
| 2 | Click **Add Division / Department**. |
| 3 | Enter the department name, parent division, and type (`department`). |
| 4 | Click **Save**. |
| 5 | Repeat for all departments and cost centres. |

> ⚠️ Departments must exist before you can assign employees, create KPIs, or run department-level reports.

---

### 3.3 Positions

**Path:** Settings → Positions  
**Who:** 🔧 Admin · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Positions** in the ribbon. |
| 2 | Click **Add Position**. |
| 3 | Enter the job title, grade, and link it to a department. |
| 4 | Click **Save**. |

---

### 3.4 Manage Users

**Path:** Settings → Manage Users  
**Who:** 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Manage Users** in the ribbon. |
| 2 | Click **Add User** to create a new account. |
| 3 | Enter name, email, and role. |
| 4 | The user will receive a password reset email. |
| 5 | To edit: click the ✏️ icon next to a user. |
| 6 | To deactivate: toggle the **Active** switch. |

---

### 3.5 Roles & Permissions

**Path:** Settings → Roles  
**Who:** 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Roles** in the ribbon. |
| 2 | Click **Add Role** (e.g. *HR Coordinator*, *Line Manager*). |
| 3 | Click **Permissions** in the ribbon. |
| 4 | Assign permissions to each role. |
| 5 | Go back to **Manage Users** and assign a role to each user. |

---

### 3.6 User Access (Module Access)

**Path:** Settings → User Access  
**Who:** 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **User Access**. |
| 2 | Select a user from the list. |
| 3 | Toggle the modules and ribbons that user should see. |
| 4 | Click **Save Access**. |

---

### 3.7 Public Holidays

**Path:** Settings → Public Holidays  
**Who:** 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Public Holidays** in the ribbon. |
| 2 | Click **Add Holiday**. |
| 3 | Enter the holiday name and date. |
| 4 | Click **Save**. |
| 5 | Repeat for all public holidays in the leave cycle year. |

> ⚠️ Public holidays affect leave day calculations and attendance schedules. Add all holidays before opening a leave cycle.

---

### 3.8 Document Types

**Path:** Settings → Document Types  
**Who:** 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Document Types**. |
| 2 | Add document categories used across the system (e.g. *Payslip*, *Contract*, *ID Copy*). |
| 3 | Click **Save**. |

---

### 3.9 Audit Log

**Path:** Settings → Audits  
**Who:** 🔧 Admin · 📊 HR Manager

Every create, update, and delete in the system is logged here automatically.

| Step | Action |
|------|--------|
| 1 | Click **Audits** in the Settings ribbon. |
| 2 | Filter by module, date range, or user. |
| 3 | Click any row to see the full before/after change detail. |

---

## 4. Performance Management

> URL prefix: `/performance`  
> **Setup order:** Rating Scales → Templates → Cycles → Goals → KPIs → Reviews → Reports

---

### 4.1 Rating Scales Setup

**Path:** Performance → Rating Scales  
**Who:** 🔧 Admin · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Navigate to **Performance → Rating Scales**. |
| 2 | Click **Add Rating Scale** (e.g. *5-Point Scale*). |
| 3 | Fill in scale name and description. |
| 4 | Click **Add Value** for each level: e.g. 1 = Poor, 2 = Below, 3 = Meets, 4 = Good, 5 = Outstanding. |
| 5 | Save each value. |

---

### 4.2 Appraisal Templates

**Path:** Performance → Templates  
**Who:** 🔧 Admin · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Templates** in the ribbon. |
| 2 | Click **Add Template** (e.g. *Annual Appraisal 2026*). |
| 3 | Click the template name to open it. |
| 4 | Click **Add Section** (e.g. *KPI Performance*, *Behavioural Competencies*). |
| 5 | Within each section, click **Add Question**. |
| 6 | Set question text, type (rating / text / number), and link a rating scale. |
| 7 | Save. |

---

### 4.3 Performance Settings

**Path:** Performance → Settings  
**Who:** 🔧 Admin · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Settings** in the Performance ribbon. |
| 2 | Set high-performer threshold (e.g. 80%), low-performer threshold (e.g. 40%), bonus threshold. |
| 3 | Enable/disable modules: Wellness, 360 Reviews, PIP auto-creation, etc. |
| 4 | Click **Save Settings**. |

---

### 4.4 Appraisal Cycles

**Path:** Performance → Cycles  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Cycles** in the ribbon. |
| 2 | Click **Add Cycle** (e.g. *Annual 2026*). |
| 3 | Set start date, end date, and link the appraisal template. |
| 4 | Click **Save**. |
| 5 | When ready to begin scoring, click **Activate** on the cycle. |
| 6 | At year-end, click **Close** to lock the cycle. |

> ⚠️ Only one cycle can be **Active** at a time. Activating a new cycle does not delete old data.

---

### 4.5 Organisational Goals

**Path:** Performance → Goals  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator · 👔 Manager

| Step | Action |
|------|--------|
| 1 | Click **Goals** in the ribbon. |
| 2 | Click **Add Goal**. |
| 3 | Enter goal title, description, start date, end date, and target. |
| 4 | Select the appraisal cycle. |
| 5 | Click **Save**. |
| 6 | To update progress, click the goal → enter a progress % → **Save Progress**. |
| 7 | To delete, click the 🗑️ icon (confirmation required). |

---

### 4.6 Employee KPIs

**Path:** Performance → KPIs  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator · 👤 Employee (view + self-update if enabled)

#### 4.6.1 Creating a KPI (Manager / HR)

| Step | Action |
|------|--------|
| 1 | Click **KPIs** in the ribbon. |
| 2 | Click **Add KPI**. |
| 3 | Select the employee, cycle, and link to an organisational goal (optional). |
| 4 | Enter KPI title, target value, unit (e.g. *%, units, Rand*), and weighting %. |
| 5 | Set whether the employee can self-update progress. |
| 6 | Click **Save**. |

#### 4.6.2 Logging Progress (Employee / Manager)

| Step | Action |
|------|--------|
| 1 | Click **KPIs** and find the employee's KPI. |
| 2 | Click **Log Progress**. |
| 3 | Enter the achieved value and an optional note. |
| 4 | Click **Save Progress**. |

#### 4.6.3 Uploading Evidence

| Step | Action |
|------|--------|
| 1 | Open a KPI. |
| 2 | Click **Upload Evidence**. |
| 3 | Select a file (PDF, image, spreadsheet). |
| 4 | Click **Upload**. |

#### 4.6.4 Scoring a KPI (Manager / HR)

| Step | Action |
|------|--------|
| 1 | Open a KPI. |
| 2 | Click **Score KPI**. |
| 3 | Enter the achieved value and score percentage. |
| 4 | Add scoring notes. |
| 5 | Click **Submit Score**. |

#### 4.6.5 Score All KPIs for an Employee

| Step | Action |
|------|--------|
| 1 | Click **KPIs → Score Employee**. |
| 2 | Select the employee and cycle. |
| 3 | Complete all KPI scores in the form. |
| 4 | Click **Submit All Scores**. |

#### 4.6.6 View KPI Completion Report

| Step | Action |
|------|--------|
| 1 | Click **KPIs → Completion Report**. |
| 2 | Select a cycle. |
| 3 | View the per-employee KPI status breakdown. |

---

### 4.7 AI Performance Insights

**Path:** Performance → AI Insights  
**Who:** 📊 HR Manager · 👔 Manager

| Step | Action |
|------|--------|
| 1 | Click **AI Insights** in the ribbon. |
| 2 | Click **Run Analysis** and select the active cycle. |
| 3 | Wait for the analysis to complete (usually a few seconds). |
| 4 | View the generated insights cards. |
| 5 | Click **Acknowledge** on any insight you have reviewed. |
| 6 | Click **Run Bias Detection** to detect rating inconsistencies by demographic. |
| 7 | Click **Run Inconsistency Detection** to find managers who rate significantly different from peers. |

---

### 4.8 Continuous Feedback

**Path:** Performance → Feedback  
**Who:** 👤 Employee · 👔 Manager · 🧑‍💼 HR Coordinator

#### Submitting Feedback

| Step | Action |
|------|--------|
| 1 | Click **Feedback** in the ribbon. |
| 2 | Click **Give Feedback**. |
| 3 | Select the employee receiving feedback. |
| 4 | Choose type: *Praise*, *Coaching*, *Concern*, *Development*. |
| 5 | Write your feedback. |
| 6 | Toggle **Anonymous** if you do not want your name shown. |
| 7 | Click **Submit Feedback**. |

#### Acknowledging Feedback (Recipient)

| Step | Action |
|------|--------|
| 1 | Click **Feedback** to see feedback given to you. |
| 2 | Click **Acknowledge** on a feedback item. |

#### Feedback Summary (Manager / HR)

| Step | Action |
|------|--------|
| 1 | Click **Feedback → Summary**. |
| 2 | Select a cycle or date range. |
| 3 | View aggregated praise, coaching, and concern counts. |

---

### 4.9 Check-Ins & Coaching Sessions

**Path:** Performance → Check-Ins  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator · 👤 Employee (view own)

#### Scheduling a Check-In

| Step | Action |
|------|--------|
| 1 | Click **Check-Ins**. |
| 2 | Click **Schedule Check-In**. |
| 3 | Select the employee, date, and agenda. |
| 4 | Click **Save**. |

#### Completing a Check-In

| Step | Action |
|------|--------|
| 1 | Open the check-in. |
| 2 | Add discussion notes and action items. |
| 3 | Set status to **Completed**. |
| 4 | Click **Update**. |

#### Adding a Coaching Session

| Step | Action |
|------|--------|
| 1 | Click **Check-Ins → Add Coaching Session**. |
| 2 | Select coach, coachee, session date, topic. |
| 3 | Click **Save Session**. |

---

### 4.10 360° Reviews

**Path:** Performance → 360 Reviews  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator · 👤 Rater (responds to assigned review)

#### Creating a 360 Review

| Step | Action |
|------|--------|
| 1 | Click **360 Reviews**. |
| 2 | Click **Create Review**. |
| 3 | Select the subject employee and cycle. |
| 4 | Add questions or select from the question bank. |
| 5 | Click **Save Review**. |

#### Adding Raters

| Step | Action |
|------|--------|
| 1 | Open the review. |
| 2 | Click **Add Rater**. |
| 3 | Select the user who will rate (peer, subordinate, or manager). |
| 4 | Click **Add**. |

#### Opening the Collection (sending to raters)

| Step | Action |
|------|--------|
| 1 | When all raters are added, click **Open Collection**. |
| 2 | Each rater receives a link to complete their ratings. |

#### Responding as a Rater

| Step | Action |
|------|--------|
| 1 | Click the review link you received. |
| 2 | Answer all rating questions. |
| 3 | Click **Submit Response**. |

#### Closing Collection & Generating Report

| Step | Action |
|------|--------|
| 1 | HR / Manager clicks **Close Collection** when responses are complete. |
| 2 | Click **Generate Report** to create the 360 summary. |

---

### 4.11 Probation Reviews

**Path:** Performance → Probation  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator · 📊 HR Manager

#### Creating a Probation Review

| Step | Action |
|------|--------|
| 1 | Click **Probation**. |
| 2 | Click **Add Review**. |
| 3 | Select the employee on probation, probation end date, and reviewer. |
| 4 | Click **Save**. |

#### Adding Objectives to Probation

| Step | Action |
|------|--------|
| 1 | Open the probation record. |
| 2 | Click **Add Objective**. |
| 3 | Enter objective title and target. |
| 4 | Click **Save**. |

#### Adding Check-Ins & Feedback During Probation

| Step | Action |
|------|--------|
| 1 | Inside the probation record, click **Add Check-In** or **Add Feedback**. |
| 2 | Fill in the details and click **Save**. |

#### Running Risk Calculation

| Step | Action |
|------|--------|
| 1 | Click **Calculate Risk** on the probation record. |
| 2 | The system computes a risk score based on check-in outcomes and objectives. |

#### Finalising a Probation Outcome

| Step | Action |
|------|--------|
| 1 | Click **Finalise** on the probation record. |
| 2 | Select outcome: *Confirmed*, *Extended*, *Terminated*, *Resigned*. |
| 3 | Add outcome notes. |
| 4 | Click **Finalise**. |

---

### 4.12 Wellness & Workforce Signals

**Path:** Performance → Wellness  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator

| Step | Action |
|------|--------|
| 1 | Click **Wellness** in the ribbon. |
| 2 | Click **Generate All Scores** to calculate wellness risk for all active employees. |
| 3 | View the risk breakdown (Critical, High, Medium, Low). |
| 4 | Click **Detect Signals** to identify specific burnout/absence patterns. |
| 5 | Click **Manager Insights** to see team-level wellness summary. |
| 6 | Click **Acknowledge** on any signal you have reviewed. |

---

### 4.13 Development Plans (IDP)

**Path:** Performance → Development  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator · 👤 Employee (view own)

#### Creating a Development Plan

| Step | Action |
|------|--------|
| 1 | Click **Development**. |
| 2 | Click **Add Plan**. |
| 3 | Select the employee, development period, and title. |
| 4 | Click **Save**. |

#### Adding Activities

| Step | Action |
|------|--------|
| 1 | Open the plan. |
| 2 | Click **Add Activity** (e.g. *Training*, *Mentorship*, *Project*). |
| 3 | Set activity title, type, target completion date, and status. |
| 4 | Click **Save**. |

#### Adding Employee Skills

| Step | Action |
|------|--------|
| 1 | Open the plan. |
| 2 | Click **Add Skill** and select from the skill library. |
| 3 | Set current level and target level. |
| 4 | Click **Save**. |

#### AI Recommendations

| Step | Action |
|------|--------|
| 1 | Open the plan. |
| 2 | Click **Generate AI Recommendations**. |
| 3 | Review the suggested activities and skills. |
| 4 | Accept or dismiss each recommendation. |

---

### 4.14 Succession Planning

**Path:** Performance → Succession  
**Who:** 📊 HR Manager · 🔧 Admin

#### Creating a Succession Plan

| Step | Action |
|------|--------|
| 1 | Click **Succession**. |
| 2 | Click **Add Succession Plan**. |
| 3 | Select the target position and key holder (current incumbent). |
| 4 | Set plan priority and timeline. |
| 5 | Click **Save**. |

#### Adding Candidates

| Step | Action |
|------|--------|
| 1 | Open the plan. |
| 2 | Click **Add Candidate**. |
| 3 | Select the employee and set readiness level. |
| 4 | Click **Save**. |
| 5 | Click **Score Candidate** to run the readiness scoring. |

#### Identifying High Potentials

| Step | Action |
|------|--------|
| 1 | Click **Succession → Identify High Potentials**. |
| 2 | The system analyses KPI scores, talent profiles, and feedback to generate a list. |
| 3 | Review the ranked list and add candidates to plans. |

#### AI Succession Analysis

| Step | Action |
|------|--------|
| 1 | Open a succession plan. |
| 2 | Click **Generate Analysis**. |
| 3 | Read the AI-generated narrative on readiness gaps and recommended actions. |

---

### 4.15 Talent Growth Intelligence

**Path:** Performance → Talent Growth  
**Who:** 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Talent Growth**. |
| 2 | Click **Build All Profiles** to generate talent profiles for all employees. |
| 3 | To build for one employee, click **Build Profile** next to their name. |
| 4 | Click **View Skill Gaps** for an employee to see missing competencies vs position requirements. |
| 5 | Click **Generate Recommendations** to create development suggestions. |
| 6 | Click **Succession Recommendation** to see which positions an employee is ready for. |

---

### 4.16 Policy Enforcement Engine

**Path:** Performance → Policy  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator

#### Creating a Performance Policy

| Step | Action |
|------|--------|
| 1 | Click **Policy** in the Performance ribbon. |
| 2 | Click **Add Policy**. |
| 3 | Enter policy name, description, and threshold rules (e.g. *score below 40% = violation*). |
| 4 | Click **Save**. |

#### Running Violation Detection

| Step | Action |
|------|--------|
| 1 | Click **Detect Violations**. |
| 2 | The system scans all scored KPIs against active policies. |
| 3 | View the list of detected violations. |

#### Managing Violations

| Step | Action |
|------|--------|
| 1 | Open a violation. |
| 2 | Click **Escalate**, **Exempt**, or **Resolve**. |
| 3 | Add notes and click **Submit**. |

#### Computing Compliance Scores

| Step | Action |
|------|--------|
| 1 | Click **Compute All Compliance**. |
| 2 | The system generates a compliance score (0–100) per employee. |
| 3 | View in the policy dashboard. |

---

### 4.17 Performance Improvement Plans (PIP)

**Path:** Performance → PIP  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator · 📊 HR Manager

#### Creating a PIP

| Step | Action |
|------|--------|
| 1 | Click **PIP**. |
| 2 | Click **Create PIP**. |
| 3 | Select the employee, manager, and reason for the PIP. |
| 4 | Set start date and target end date. |
| 5 | Click **Save**. |

#### Adding PIP Objectives

| Step | Action |
|------|--------|
| 1 | Open the PIP. |
| 2 | Click **Add Objective**. |
| 3 | Enter the objective, success criteria, and target date. |
| 4 | Click **Save**. |

#### Logging Progress

| Step | Action |
|------|--------|
| 1 | Open the PIP. |
| 2 | Click **Log Progress**. |
| 3 | Enter the progress note, date, and completion %. |
| 4 | Click **Save**. |

#### PIP Review Sessions

| Step | Action |
|------|--------|
| 1 | Open the PIP. |
| 2 | Click **Add Review**. |
| 3 | Enter review date, rating, and notes. |
| 4 | Click **Save Review**. |

#### AI Coaching & Outcome Recommendation

| Step | Action |
|------|--------|
| 1 | Open the PIP. |
| 2 | Click **Generate AI Coaching** for tailored improvement suggestions. |
| 3 | Click **Recommend Outcome** for an AI-driven recommendation (*Improved / Extended / Dismissed*). |

#### Closing Out a PIP

| Step | Action |
|------|--------|
| 1 | Open the PIP. |
| 2 | Click **Close Out**. |
| 3 | Select the final status: *Closed — Improved*, *Closed — Not Improved*, *Escalated*. |
| 4 | Add outcome notes. |
| 5 | Click **Submit**. |

---

### 4.18 Calibration & Approval Workflows

**Path:** Performance → Calibration  
**Who:** 📊 HR Manager · 🔧 Admin

#### Creating a Calibration Session

| Step | Action |
|------|--------|
| 1 | Click **Calibration**. |
| 2 | Click **New Session**. |
| 3 | Select the cycle, facilitator, and schedule date. |
| 4 | Click **Save**. |

#### Adding Participants to a Session

| Step | Action |
|------|--------|
| 1 | Open the session. |
| 2 | Click **Add Participant**. |
| 3 | Select the employee and enter their original score. |
| 4 | Click **Save**. |

#### Updating Calibrated Scores

| Step | Action |
|------|--------|
| 1 | Click ✏️ next to a participant in the session. |
| 2 | Enter the calibrated score and adjustment reason. |
| 3 | Click **Update**. |

#### Finalising a Session

| Step | Action |
|------|--------|
| 1 | When all scores are agreed, click **Finalise Session**. |
| 2 | The system locks the calibrated scores. |
| 3 | Click **View Summary** to see the session statistics. |

#### Creating an Approval Workflow

| Step | Action |
|------|--------|
| 1 | Click **New Workflow** in the Calibration page. |
| 2 | Enter workflow name and resource type (e.g. *PIP Closure*). |
| 3 | Add steps: click **Add Step**, set step label, assign an approver or role, and mark as required. |
| 4 | Click **Save Workflow**. |

#### Submitting an Approval Request

| Step | Action |
|------|--------|
| 1 | Click **New Request**. |
| 2 | Select the workflow, resource type, and resource. |
| 3 | Enter request title. |
| 4 | Click **Submit**. |

#### Acting on an Approval Request (Approver)

| Step | Action |
|------|--------|
| 1 | Open the approval request. |
| 2 | Click **Approve**, **Reject**, or **Recall**. |
| 3 | Add notes. |
| 4 | Click **Submit Action**. |

---

### 4.19 Performance Reports

**Path:** Performance → Reports  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator

The reports page contains **14 report types**. For each:

| Step | Action |
|------|--------|
| 1 | Click **Reports** in the ribbon. |
| 2 | Apply filters (Cycle, Employee, Department, Date range). |
| 3 | Click **Generate** on any report card to load data into the table. |
| 4 | Click **View Table** to switch the data table to that report. |
| 5 | Click **Export → Excel** or **Export → PDF** to download. |
| 6 | Click **Queue & Email** to run in the background and receive by email. |

**Available reports:**

| Report | What it shows |
|--------|---------------|
| Performance Distribution | Headcount per score band (Outstanding/Good/Meets/Below/Poor) |
| High Performers | Employees scoring ≥ 80% |
| Low Performers | Employees scoring < 40% |
| PIP Employees | Active performance improvement plans |
| Promotion Ready | Talent profiles with readiness score ≥ 60% |
| Burnout Risk | Wellness risk flags (high/critical) |
| Department Scores | Average score per department per cycle |
| Bonus Eligibility | Employees meeting the bonus threshold |
| Calibration Risk | Participants with large score adjustments |
| Policy Violations | Open performance policy breaches |
| KPI Completion | Individual KPI target vs actual per employee |
| Feedback Summary | Praise, coaching, and concern counts per employee |
| PIP Outcomes | Closed PIPs with outcome and duration |
| Probation Reviews | Probation decisions and risk scores |

---

### 4.20 Executive Graphs Dashboard

**Path:** Performance → Exec. Graphs  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Exec. Graphs** in the ribbon. |
| 2 | Review the **8 KPI cards**: headcount, avg score, high/low performers, active PIPs, high-scorer rate, burnout risks, open violations. |
| 3 | Read the **Score Distribution Donut** to see how many employees are in each band. |
| 4 | Review **Cycle Trend** to see whether average scores are improving or declining over time. |
| 5 | Read the **Department Scores** horizontal bar chart to see top and bottom performing teams. |
| 6 | Check the **PIP Status** donut for active vs closed improvement plan breakdown. |
| 7 | Review **Feedback by Type** to understand culture health. |
| 8 | View **Top 10 Performers** bar chart. |
| 9 | Read the **Score Band Summary** table at the bottom for percentages with progress bars. |

---

### 4.21 Scheduled Performance Reports

**Path:** Performance → Sched. Reports  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Sched. Reports** in the ribbon. |
| 2 | Click **New Schedule**. |
| 3 | Enter a schedule name (e.g. *Weekly High Performers — HR Team*). |
| 4 | Select the report type. |
| 5 | Set cadence: *Daily*, *Weekly*, or *Monthly*. |
| 6 | Set run time (e.g. 07:00). |
| 7 | For weekly: select the day of week. For monthly: enter day of month (1–28). |
| 8 | Set output format: *PDF*, *Excel*, or *Both*. |
| 9 | Enter recipient email addresses (comma-separated). |
| 10 | Toggle **Active** on. |
| 11 | Click **Create Schedule**. |

To pause a schedule: open it, toggle **Active** off, and save.

---

## 5. Leave Management

> URL prefix: `/leave-management`  
> **Setup order:** Leave Types → Profiles → Approval Config → Configuration → Open cycle → Employee applies

---

### 5.1 Leave Types Setup

**Path:** Leave → Leave Types  
**Who:** 🔧 Admin · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Navigate to **Leave → Leave Types**. |
| 2 | Click **Add Leave Type** (e.g. *Annual Leave*, *Sick Leave*, *Family Responsibility*). |
| 3 | Set the leave type name, entitlement days, carry-over rules, and cycle. |
| 4 | Set gender restrictions (if applicable, e.g. maternity). |
| 5 | Enable negative balance if employees can borrow days. |
| 6 | Click **Save**. |

---

### 5.2 Leave Profiles

**Path:** Leave → Leave Profiles  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Leave Profiles**. |
| 2 | Click **Add Profile** (e.g. *Full-Time Staff*, *Part-Time*). |
| 3 | Link applicable leave types to the profile. |
| 4 | Assign the profile to employee groups. |
| 5 | Click **Save**. |

---

### 5.3 Approval Configuration

**Path:** Leave → Approval Config  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Approval Config**. |
| 2 | Set approval chain: 1-level (manager only) or 2-level (manager then HR). |
| 3 | Set auto-approval thresholds (e.g. applications under 1 day auto-approved). |
| 4 | Click **Save**. |

---

### 5.4 Leave Configuration (Global)

**Path:** Leave → Configuration  
**Who:** 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Configuration**. |
| 2 | Set the leave year start month. |
| 3 | Enable/disable features: AI leave advisor, overtime leave, parental leave pooling, etc. |
| 4 | Set minimum notice days required for leave applications. |
| 5 | Click **Save**. |

---

### 5.5 Applying for Leave (Employee)

**Path:** Leave → My Leave  
**Who:** 👤 Employee

| Step | Action |
|------|--------|
| 1 | Click **Leave Management** in the top nav. |
| 2 | Click **Apply for Leave**. |
| 3 | Select leave type. |
| 4 | Pick start date and end date. |
| 5 | View the calculated working days (public holidays and weekends excluded). |
| 6 | Add a reason / note. |
| 7 | Attach supporting documents (e.g. medical certificate for sick leave). |
| 8 | Click **Submit Application**. |
| 9 | You will receive a notification when approved or rejected. |

#### Checking Your Leave Balance

| Step | Action |
|------|--------|
| 1 | Click **My Leave** (the dashboard). |
| 2 | View balance cards for each leave type: entitlement, used, remaining. |

---

### 5.6 Approving Leave (Manager)

**Path:** Leave → Pending Approvals  
**Who:** 👔 Manager

| Step | Action |
|------|--------|
| 1 | Click **Pending Approvals** in the ribbon. |
| 2 | Review all pending applications. |
| 3 | Click **Approve** or **Reject**. |
| 4 | Add a rejection reason if rejecting. |
| 5 | Click **Submit**. |

#### Team Calendar View

| Step | Action |
|------|--------|
| 1 | Click **Team Calendar**. |
| 2 | View which team members are on leave on each date. |
| 3 | Use this before approving new leave requests to check coverage. |

---

### 5.7 Leave Balances (HR)

**Path:** Leave → Workforce Dashboard  
**Who:** 🧑‍💼 HR Coordinator · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Workforce Dashboard**. |
| 2 | See department-level absence rates and leave summary. |
| 3 | Identify employees with negative balances or approaching limits. |
| 4 | Click **HR Dashboard** for a full headcount and coverage view. |

---

### 5.8 Manual Leave Allocations (HR)

**Path:** Leave → Manual Allocations  
**Who:** 🧑‍💼 HR Coordinator · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Manual Allocations**. |
| 2 | Select the employee and leave type. |
| 3 | Enter allocation days (positive = credit, negative = debit). |
| 4 | Add a reason. |
| 5 | Click **Save Allocation**. |

---

### 5.9 Leave Reports

**Path:** Leave → Reports  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator

Available leave reports (same generate/export/queue pattern as performance):

| Report | Description |
|--------|-------------|
| Leave Balance | Current balance per employee and leave type |
| Leave Taken | Approved leave with dates and approver |
| Transaction Ledger | All credit/debit entries per employee |
| Allowance Report | Allocation vs utilisation |
| Paid-Out Leave | Days paid out in lieu of taking leave |
| Pending Leave | All unactioned applications |
| Cancelled Leave | Cancelled and withdrawn requests |
| Policy Violations | Breaches of BCEA and policy rules |
| AI Anomalies | Machine-learning-flagged suspicious patterns |
| Burnout Risk | Employees with high leave frequency and unused balance |
| Department Trends | Aggregate leave by department, type, and month |
| Negative Balance | Employees with negative leave balances |
| Leave Liability | Outstanding leave balance financial value |

---

### 5.10 Executive Leave Dashboard

**Path:** Leave → Executive Dashboard  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Executive Dashboard** in the ribbon. |
| 2 | Review KPI cards: headcount, coverage today, pending approvals, liability, burnout risks, open violations. |
| 3 | Read the leave trends bar chart (monthly breakdown by leave type). |
| 4 | Review the leave liability donut chart by leave type. |
| 5 | See department-level pressure scores in the horizontal bar chart. |
| 6 | Read the top burnout risk list with employee-level risk scores. |

---

## 6. Time & Attendance

> URL prefix: `/attendance`  
> **Setup order:** Work Calendars → Shifts → Statuses → Assign shifts → Employees clock in

---

### 6.1 Work Calendars Setup

**Path:** Attendance → Setup → Work Calendars  
**Who:** 🔧 Admin · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Navigate to **Attendance → Setup**. |
| 2 | Click **Work Calendars**. |
| 3 | Click **Add Calendar** (e.g. *Standard 5-Day Week*). |
| 4 | Set working days and hours. |
| 5 | Link public holidays. |
| 6 | Click **Save**. |

---

### 6.2 Shift Setup

**Path:** Attendance → Setup → Shifts  
**Who:** 🔧 Admin · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Shifts**. |
| 2 | Click **Add Shift** (e.g. *Day Shift 08:00–17:00*). |
| 3 | Set shift name, start time, end time, break duration. |
| 4 | Click **Save**. |

---

### 6.3 Clocking In / Out (Employee)

**Path:** Attendance → Clock In/Out  
**Who:** 👤 Employee

| Step | Action |
|------|--------|
| 1 | Navigate to **Attendance → Clock In/Out**. |
| 2 | Click **Clock In** to record your arrival. |
| 3 | At the end of the day, click **Clock Out**. |
| 4 | The system records your hours and compares to your shift schedule. |

#### Work From Home

| Step | Action |
|------|--------|
| 1 | On the kiosk page, click **WFH** instead of Clock In. |
| 2 | The record is marked as a remote working day. |

---

### 6.4 Attendance Records (Manager / HR)

**Path:** Attendance → Records  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator

| Step | Action |
|------|--------|
| 1 | Click **Records**. |
| 2 | Filter by employee, date, or status. |
| 3 | View late arrivals, early departures, and absences. |
| 4 | To correct an error: click the record → click **Request Correction** → submit. |

---

### 6.5 Rosters

**Path:** Attendance → Rosters  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator

| Step | Action |
|------|--------|
| 1 | Click **Rosters**. |
| 2 | Click **Generate Roster**. |
| 3 | Select the team, week, and shift pattern. |
| 4 | Click **Generate**. |
| 5 | Review and adjust the generated roster. |
| 6 | Click **Publish Roster**. |
| 7 | Click **AI Optimise** to let the system suggest the most efficient roster. |

---

### 6.6 Attendance Intelligence (Anomalies)

**Path:** Attendance → Intelligence  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator

| Step | Action |
|------|--------|
| 1 | Click **Intelligence**. |
| 2 | Click **Run Anomaly Scan** to detect late patterns, unexplained absences, and clocking anomalies. |
| 3 | View anomaly cards with risk scores. |
| 4 | Click **Acknowledge** on reviewed anomalies. |
| 5 | Click **Risk Scores** to see per-employee risk rankings. |
| 6 | Click **Patterns** to view recurring behaviour patterns. |

---

### 6.7 Workforce Health

**Path:** Attendance → Workforce Health  
**Who:** 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Workforce Health** (or **Workforce** in the ribbon). |
| 2 | Click **Detect Burnout** to scan for overworked employees. |
| 3 | View the burnout profile for each flagged employee. |
| 4 | Click **Generate Predictions** for AI-driven absence forecasting. |

---

### 6.8 Discipline Management

**Path:** Attendance → Discipline  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Discipline**. |
| 2 | Click **Add Case**. |
| 3 | Select the employee, incident type, and date. |
| 4 | Add incident description and evidence. |
| 5 | Click **Save**. |
| 6 | Add actions: warning letters, hearings, outcomes. |
| 7 | Click **Generate Pack** to create a PDF documentation pack. |
| 8 | Click **Offenders** to see repeat offenders report. |

---

### 6.9 Attendance Policy & Compliance

**Path:** Attendance → Policy & Compliance  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Policy & Compliance**. |
| 2 | Click **Run Policy Scan** to detect violations (late arrivals, absences without leave, etc.). |
| 3 | View violations list. |
| 4 | For each violation: **Escalate** (to manager) or **Grant Exception**. |
| 5 | Click **Compliance Dashboard** for overall compliance rates. |

---

### 6.10 Attendance Reports

**Path:** Attendance → Reports  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator

Standard reports follow the same generate/export/queue pattern. Key attendance reports:
- Daily Attendance Register
- Late Arrivals & Early Departures
- Absence Analysis
- Overtime Report
- Shift Adherence
- Discipline Summary

---

## 7. Recruitment

> URL prefix: `/recruitment`  
> **Setup order:** Create a Requisition → Approve it → Create a Job Posting → Manage Applications → Shortlist → Offer

---

### 7.1 Job Requisitions

**Path:** Recruitment → Requisitions  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator

#### Submitting a Requisition

| Step | Action |
|------|--------|
| 1 | Click **Recruitment** in the top nav. |
| 2 | Click **Requisitions**. |
| 3 | Click **New Requisition**. |
| 4 | Enter position title, department, headcount, reason for hire, and target start date. |
| 5 | Click **Submit Requisition**. |

#### Approving a Requisition

| Step | Action |
|------|--------|
| 1 | HR / Senior Manager opens the pending requisition. |
| 2 | Click **Approve** or **Reject**. |
| 3 | Add notes and submit. |

---

### 7.2 Job Postings

**Path:** Recruitment → Jobs  
**Who:** 🧑‍💼 HR Coordinator · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Jobs**. |
| 2 | Click **Create Job**. |
| 3 | Enter job title, description, requirements, department, salary range, and closing date. |
| 4 | Link to an approved requisition (optional). |
| 5 | Set the job as **Active** to make it visible on the careers page. |
| 6 | Click **Save**. |

---

### 7.3 Managing Applications

**Path:** Recruitment → Jobs → Pipeline  
**Who:** 🧑‍💼 HR Coordinator · 👔 Hiring Manager

| Step | Action |
|------|--------|
| 1 | Open a job. |
| 2 | Click **Pipeline** or **Applications**. |
| 3 | View the kanban board showing all candidate stages. |
| 4 | Drag a candidate card to the next stage (e.g. from *Applied* to *Shortlisted*). |
| 5 | Click a candidate card to view their CV and details. |

#### Moving an Application

| Step | Action |
|------|--------|
| 1 | Open an application. |
| 2 | Click **Move to Stage**. |
| 3 | Select the new stage and add a note. |
| 4 | Click **Save**. |

#### Rejecting an Application

| Step | Action |
|------|--------|
| 1 | Open the application. |
| 2 | Click **Reject**. |
| 3 | Select rejection reason. |
| 4 | Click **Confirm Rejection**. |

---

### 7.4 Shortlisting

**Path:** Recruitment → Jobs → Shortlist  
**Who:** 🧑‍💼 HR Coordinator · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Open a job. |
| 2 | Click **Generate Shortlist** (AI-powered). |
| 3 | Review the ranked candidates. |
| 4 | Click **Approve Shortlist** to proceed. |
| 5 | If you disagree with the AI ranking, click **Override Shortlist** and drag candidates into your preferred order. |

---

### 7.5 Interviews

**Path:** Recruitment → Candidates → Application  
**Who:** 👔 Hiring Manager · 🧑‍💼 HR Coordinator

| Step | Action |
|------|--------|
| 1 | Open a shortlisted candidate. |
| 2 | Click **Schedule Interview**. |
| 3 | Set date, time, interviewers, and format (in-person / video). |
| 4 | Click **Save**. |
| 5 | After the interview, click **Score Interview**. |
| 6 | Rate each competency area and add notes. |
| 7 | Click **Complete Interview**. |

---

### 7.6 Offers

**Path:** Recruitment → Offers  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator

| Step | Action |
|------|--------|
| 1 | Click **Offers**. |
| 2 | Click **Create Offer**. |
| 3 | Select the candidate and job. |
| 4 | Set salary, start date, and benefits. |
| 5 | Click **Save Draft**. |
| 6 | Click **Send Offer** to dispatch the offer letter. |
| 7 | When the candidate responds, update the status to **Accepted** or **Declined**. |
| 8 | If declined, click **Withdraw Offer** and note the reason. |

---

### 7.7 Recruitment Reports

**Path:** Recruitment → Reports (via Candidates / Jobs)  
**Who:** 📊 HR Manager

Key metrics to review:
- Time-to-hire by position
- Offer acceptance rate
- Application source breakdown
- Pipeline stage conversion rates

---

## 8. Pre-Boarding & Onboarding

> URL prefix: `/pre-boarding`, `/onboarding`

---

### 8.1 Pre-Boarding (New Hire Preparation)

**Path:** Pre-Boarding  
**Who:** 🧑‍💼 HR Coordinator · 📊 HR Manager · 👤 New Hire (completes tasks)

| Step | Action |
|------|--------|
| 1 | Click **Pre-Boarding** in the top nav. |
| 2 | Click **Add Pre-Boarding Plan** for the new hire. |
| 3 | Link the plan to the offer/hire. |
| 4 | Add tasks: *Submit ID copy*, *Bank details form*, *Sign contract*, *IT setup request*. |
| 5 | Assign each task a due date and responsible party (HR / employee). |
| 6 | The employee sees their task list and completes each step. |
| 7 | HR marks tasks as complete as they are received. |

---

### 8.2 Onboarding (First Weeks)

**Path:** Onboarding  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator · 👤 New Employee

| Step | Action |
|------|--------|
| 1 | Click **Onboarding**. |
| 2 | Open the new employee's onboarding plan. |
| 3 | Complete each onboarding task: orientation, system access, department introduction, etc. |
| 4 | Click **Complete Task** as each item is done. |
| 5 | HR monitors completion progress from the admin view. |

---

## 9. Asset Management

> URL prefix: `/assets`  
> **Setup order:** Settings (Categories → Types → Locations) → Create Assets → Assign Assets

---

### 9.1 Asset Settings

**Path:** Assets → Settings  
**Who:** 🔧 Admin · 📊 HR Manager

#### Categories

| Step | Action |
|------|--------|
| 1 | Click **Assets** in top nav → **Settings → Categories**. |
| 2 | Click **Add Category** (e.g. *IT Equipment*, *Furniture*, *Vehicles*). |
| 3 | Set depreciation method and useful life years. |
| 4 | Click **Save**. |

#### Asset Types

| Step | Action |
|------|--------|
| 1 | Click **Settings → Types**. |
| 2 | Click **Add Type** (e.g. *Laptop*, *Office Chair*). |
| 3 | Link to a category. |
| 4 | Click **Save**. |

#### Locations

| Step | Action |
|------|--------|
| 1 | Click **Settings → Locations**. |
| 2 | Click **Add Location** (e.g. *Head Office*, *Cape Town Branch*). |
| 3 | Click **Save**. |

---

### 9.2 Creating Assets

**Path:** Assets → Asset Register → Create  
**Who:** 🧑‍💼 HR Coordinator · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Asset Register**. |
| 2 | Click **Add Asset**. |
| 3 | Enter asset tag number, serial number, type, location, purchase date, and cost. |
| 4 | Click **Save**. |

---

### 9.3 Assigning Assets to Employees

**Path:** Assets → Asset Register → Assignments  
**Who:** 🧑‍💼 HR Coordinator

| Step | Action |
|------|--------|
| 1 | Open an asset from the register. |
| 2 | Click **Assign**. |
| 3 | Select the employee. |
| 4 | Set assignment date and expected return date (if temporary). |
| 5 | Click **Assign Asset**. |

#### Returning an Asset

| Step | Action |
|------|--------|
| 1 | Open the asset assignment record. |
| 2 | Click **Return Asset**. |
| 3 | Enter the return date and condition. |
| 4 | Click **Confirm Return**. |

---

### 9.4 Maintenance Scheduling

**Path:** Assets → Maintenance  
**Who:** 🧑‍💼 HR Coordinator · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Maintenance**. |
| 2 | Click **Schedule Maintenance**. |
| 3 | Select the asset, maintenance type, scheduled date, and responsible technician. |
| 4 | Click **Save**. |
| 5 | When maintenance is done, open the record and click **Complete**. |
| 6 | Add cost and notes. |
| 7 | To log an incident, click **Log Incident** on the asset. |

---

### 9.5 Depreciation

**Path:** Assets → Depreciation  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Depreciation**. |
| 2 | Click **Create Schedule** for an asset or batch. |
| 3 | Select the depreciation method (Straight-line / Reducing balance). |
| 4 | Click **Post Depreciation** at month/year end. |
| 5 | View the asset's carrying value and accumulated depreciation. |

---

### 9.6 Procurement (Purchase Requests)

**Path:** Assets → Procurement  
**Who:** 👔 Manager · 🧑‍💼 HR Coordinator

| Step | Action |
|------|--------|
| 1 | Click **Procurement**. |
| 2 | Click **New Purchase Request**. |
| 3 | Enter item description, quantity, estimated cost, and justification. |
| 4 | Submit for approval. |
| 5 | HR/Finance approves and marks as **Delivered**. |
| 6 | Click **Create Asset from Purchase** to automatically register the new asset. |

---

### 9.7 Exit Clearance

**Path:** Assets → Exit Clearances  
**Who:** 🧑‍💼 HR Coordinator · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Exit Clearances**. |
| 2 | Click **New Exit Clearance** for a departing employee. |
| 3 | Select the employee and exit date. |
| 4 | The system lists all assets assigned to that employee. |
| 5 | Mark each asset as returned or confirm outstanding items. |
| 6 | Click **Complete Clearance**. |

---

### 9.8 Misuse Detection

**Path:** Assets → Misuse Detection  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Misuse Detection**. |
| 2 | Click **Manage Rules** to define misuse criteria (e.g. *asset used after hours*, *multiple location changes*). |
| 3 | Click **Add Rule** → enter rule name, condition, and severity. |
| 4 | Click **Run Detection** to scan all assets. |
| 5 | Review flagged misuse incidents. |
| 6 | Click **View** to see the incident detail. |

---

### 9.9 Compliance Dashboard

**Path:** Assets → Compliance  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Compliance**. |
| 2 | Click **Manage Rules** to define compliance requirements (e.g. *annual service required*). |
| 3 | Click **Run Compliance Check** to scan all assets against rules. |
| 4 | View the compliance run results. |
| 5 | Address non-compliant items from the results page. |

---

### 9.10 Asset Reports

**Path:** Assets → Reports  
**Who:** 📊 HR Manager

Available reports:
- Asset Register (full inventory)
- Depreciation Schedule
- Policy Compliance Summary
- SARS Tax Asset Report
- Utilisation Report

---

## 10. Help Desk & Ticketing

> URL prefix: `/helpdesk`

---

### 10.1 Creating a Ticket (Any User)

**Path:** Help Desk → Tickets → Create  
**Who:** 👤 Employee · 👔 Manager · 🧑‍💼 HR Coordinator

| Step | Action |
|------|--------|
| 1 | Click **Help Desk** in the top nav. |
| 2 | Click **New Ticket**. |
| 3 | Enter ticket title and description. |
| 4 | Set priority: *Low*, *Medium*, *High*, *Critical*. |
| 5 | Select category (e.g. *IT*, *HR*, *Facilities*). |
| 6 | Attach files if needed. |
| 7 | Click **Submit Ticket**. |
| 8 | Note your ticket number for follow-up. |

---

### 10.2 Responding to a Ticket (Support Agent / HR)

**Path:** Help Desk → Dashboard  
**Who:** 🧑‍💼 HR Coordinator · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Help Desk → Dashboard**. |
| 2 | See all open, in-progress, and resolved tickets. |
| 3 | Click a ticket to open it. |
| 4 | Add a comment/response in the **Reply** section. |
| 5 | Update the status: *In Progress*, *Waiting on User*, *Resolved*. |
| 6 | Click **Submit**. |

---

### 10.3 Escalating a Ticket

| Step | Action |
|------|--------|
| 1 | Open the ticket. |
| 2 | Click **Escalate**. |
| 3 | Select the escalation target (senior agent or manager). |
| 4 | Add an escalation note. |
| 5 | Click **Escalate**. |

---

### 10.4 Configuring Email Inboxes (Admin)

**Path:** Help Desk → Settings → Inboxes  
**Who:** 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Settings → Inboxes**. |
| 2 | Click **Add Inbox**. |
| 3 | Enter the email address (e.g. `helpdesk@company.com`). |
| 4 | Configure IMAP/SMTP credentials. |
| 5 | Click **Save**. |
| 6 | Emails sent to that address will automatically create tickets. |

---

### 10.5 Help Desk Reports

**Path:** Help Desk → Reports  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator

Key metrics:
- Tickets by status (open, in progress, resolved)
- Average resolution time
- SLA breach rate
- Tickets by category

---

## 11. Policies & Compliance

> URL prefix: `/policies`  
> **Setup order:** Categories → Create Policy → Upload Document → Review Clauses → Build Rules → Assign Attestations

---

### 11.1 Policy Categories

**Path:** Policies → Policy Categories  
**Who:** 🔧 Admin · 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Policies** in the top nav. |
| 2 | Click **Policy Categories**. |
| 3 | Click **Add Category** (e.g. *HR Policies*, *IT Security*, *Finance*). |
| 4 | Click **Save**. |

---

### 11.2 Creating a Policy

**Path:** Policies → Policies  
**Who:** 📊 HR Manager · 🔧 Admin

| Step | Action |
|------|--------|
| 1 | Click **Policies**. |
| 2 | Click **Create Policy**. |
| 3 | Enter policy title, category, effective date, and review date. |
| 4 | Write the policy content or upload a PDF. |
| 5 | Click **Save as Draft**. |
| 6 | When ready, click **Publish Policy**. |

---

### 11.3 AI Document Extraction

**Path:** Policies → Policies → Show  
**Who:** 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Open a policy. |
| 2 | Click **Extract Clauses** (AI reads the uploaded PDF). |
| 3 | Review the extracted clauses. |
| 4 | Confirm or reject each clause. |
| 5 | Click **Build Rules** to convert clauses into enforceable rules. |

---

### 11.4 Policy Attestations (Acknowledgement)

**Path:** Policies → Policy Attestations  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator

#### Assigning an Attestation

| Step | Action |
|------|--------|
| 1 | Click **Policy Attestations**. |
| 2 | Click **Assign Attestation**. |
| 3 | Select the policy and employee group. |
| 4 | Set the due date. |
| 5 | Click **Assign**. |

#### Completing an Attestation (Employee)

| Step | Action |
|------|--------|
| 1 | The employee receives a notification. |
| 2 | Click the link or navigate to **Policies → My Attestations**. |
| 3 | Read the policy. |
| 4 | Click **Acknowledge & Sign**. |

#### Attestation Dashboard (HR)

| Step | Action |
|------|--------|
| 1 | Click **Attestation Dashboard**. |
| 2 | View completion rates per policy and department. |
| 3 | Follow up with employees who have not acknowledged. |

---

### 11.5 Policy Violations

**Path:** Policies → Policy Violations  
**Who:** 📊 HR Manager · 🧑‍💼 HR Coordinator

| Step | Action |
|------|--------|
| 1 | Click **Policy Violations**. |
| 2 | View all detected violations. |
| 3 | Open a violation. |
| 4 | Add notes. |
| 5 | Click **Resolve**, **Escalate**, or **Create Exception**. |

---

### 11.6 Policy Exceptions

**Path:** Policies → Policy Exceptions  
**Who:** 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Policy Exceptions**. |
| 2 | View submitted exception requests. |
| 3 | Open an exception. |
| 4 | Click **Approve** or **Decline**. |
| 5 | Add decision notes. |

---

### 11.7 Policy Quality Check

**Path:** Policies → Policy Quality  
**Who:** 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Policy Quality**. |
| 2 | The system scans for conflicting or overlapping rules. |
| 3 | View detected conflicts. |
| 4 | Acknowledge and resolve each conflict. |

---

### 11.8 Policy Simulation

**Path:** Policies → Simulation  
**Who:** 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Simulation**. |
| 2 | Select a policy and hypothetical scenario (e.g. *if we lower the violation threshold from 40% to 35%*). |
| 3 | Click **Run Simulation**. |
| 4 | View the projected impact: how many employees would be affected. |

---

### 11.9 Policy Dashboard & Reports

**Path:** Policies → Dashboard · Reports  
**Who:** 📊 HR Manager

| Step | Action |
|------|--------|
| 1 | Click **Dashboard** for a visual overview of policy compliance rates. |
| 2 | Click **Reports** for detailed exported data. |
| 3 | Use the scheduled reports feature to automate delivery. |

---

## 12. Reports & Graphs — All Modules

This section summarises the reporting capability across every module. All reports follow the same three-step pattern:

> 1. **Generate** — loads data into the on-screen table.  
> 2. **Export** — downloads as Excel (XLSX) or PDF.  
> 3. **Queue & Email** — runs in the background and sends to recipients.

---

### 12.1 Report Filter Controls (All Modules)

| Filter | Where available |
|--------|----------------|
| Cycle / Period | Performance, Leave |
| Department | Performance, Leave, Attendance |
| Employee | Performance, Leave, Attendance |
| Date From / To | Performance, Leave, Attendance, Assets |
| Leave Type | Leave |
| Status | Leave, HelpDesk, Policies |

---

### 12.2 Scheduled Reports (Set-and-Forget)

Available in: **Performance**, **Leave**, **Attendance**, **HelpDesk**, **Policies**.

**To create a scheduled report in any module:**

| Step | Action |
|------|--------|
| 1 | Navigate to the module's **Scheduled Reports** ribbon. |
| 2 | Click **New Schedule**. |
| 3 | Name the schedule. |
| 4 | Select the report type. |
| 5 | Set cadence (Daily / Weekly / Monthly). |
| 6 | Set run time, and day (if weekly/monthly). |
| 7 | Set format (PDF / Excel / Both). |
| 8 | Enter recipient email addresses. |
| 9 | Toggle **Active** on. |
| 10 | Click **Create Schedule**. |

To pause: toggle **Active** off. To delete: click the 🗑️ icon.

---

### 12.3 Executive Dashboards

| Module | Path | Key Charts |
|--------|------|------------|
| Performance | `/performance/reports/executive` | Score distribution donut, cycle trend line, dept scores bar, PIP status, feedback types, KPI status, top performers |
| Leave | `/leave-management/executive-dashboard` | Coverage KPIs, leave trend bar, liability donut, dept pressure bar, burnout list |
| Attendance | `/attendance/executive` | Attendance KPIs, anomaly trends, burnout heatmap |

---

### 12.4 Audit Reports

**Path:** Settings → Audits  
**Who:** 🔧 Admin · 📊 HR Manager

Every write operation in the system is logged. To query the audit log:

| Step | Action |
|------|--------|
| 1 | Go to **Settings → Audits**. |
| 2 | Filter by: *Module* (e.g. `performance_appraisal`), *User*, *Date range*, *Action* (created / updated / deleted). |
| 3 | Click any row to see the full JSON diff (what changed, before and after values). |
| 4 | Export to Excel for compliance reporting. |

---

## Quick Reference: Role Task Matrix

| Task | 👤 Employee | 👔 Manager | 🧑‍💼 HR Coord | 📊 HR Mgr | 🔧 Admin |
|------|:-----------:|:---------:|:-----------:|:--------:|:------:|
| Apply for leave | ✅ | ✅ | ✅ | ✅ | ✅ |
| Approve leave | ❌ | ✅ | ✅ | ✅ | ✅ |
| View team balances | ❌ | ✅ | ✅ | ✅ | ✅ |
| Clock in/out | ✅ | ✅ | ✅ | ✅ | ✅ |
| Run anomaly scans | ❌ | ❌ | ✅ | ✅ | ✅ |
| Submit feedback | ✅ | ✅ | ✅ | ✅ | ✅ |
| Score KPIs | ❌ | ✅ | ✅ | ✅ | ✅ |
| Create KPIs | ❌ | ✅ | ✅ | ✅ | ✅ |
| Create PIP | ❌ | ✅ | ✅ | ✅ | ✅ |
| Close PIP | ❌ | ✅ | ✅ | ✅ | ✅ |
| Create 360 review | ❌ | ❌ | ✅ | ✅ | ✅ |
| Respond to 360 | ✅ | ✅ | ✅ | ✅ | ✅ |
| Run AI insights | ❌ | ❌ | ❌ | ✅ | ✅ |
| View exec graphs | ❌ | ❌ | ❌ | ✅ | ✅ |
| Create appraisal cycle | ❌ | ❌ | ❌ | ✅ | ✅ |
| Manage leave types | ❌ | ❌ | ❌ | ✅ | ✅ |
| Create job posting | ❌ | ❌ | ✅ | ✅ | ✅ |
| Approve requisition | ❌ | ✅ | ❌ | ✅ | ✅ |
| Create offer | ❌ | ❌ | ✅ | ✅ | ✅ |
| Assign assets | ❌ | ❌ | ✅ | ✅ | ✅ |
| Create help desk ticket | ✅ | ✅ | ✅ | ✅ | ✅ |
| Resolve help desk ticket | ❌ | ❌ | ✅ | ✅ | ✅ |
| Publish policies | ❌ | ❌ | ❌ | ✅ | ✅ |
| Acknowledge policy | ✅ | ✅ | ✅ | ✅ | ✅ |
| Manage users & roles | ❌ | ❌ | ❌ | ❌ | ✅ |
| Configure modules | ❌ | ❌ | ❌ | ❌ | ✅ |
| View audit log | ❌ | ❌ | ❌ | ✅ | ✅ |
| Run scheduled reports | ❌ | ❌ | ❌ | ✅ | ✅ |
| Export all reports | ❌ | ❌ | ✅ | ✅ | ✅ |

---

## Step-by-Step System Test Checklist

Use this checklist to verify the full system from scratch.

### Phase 1 — System Setup (Admin)
- [ ] Company identity saved
- [ ] Organisation structure created (at least 2 departments)
- [ ] Positions created
- [ ] At least 3 test users created (1 employee, 1 manager, 1 HR coordinator)
- [ ] Roles created and assigned
- [ ] Public holidays entered for the year
- [ ] Document types created

### Phase 2 — Leave Setup (Admin / HR Manager)
- [ ] At least 3 leave types created (Annual, Sick, Family Responsibility)
- [ ] Leave profile created and employees assigned
- [ ] Approval configuration set
- [ ] Configuration saved

### Phase 3 — Leave Workflow (Employee → Manager → HR)
- [ ] Employee applies for annual leave
- [ ] Manager approves
- [ ] Employee checks balance update
- [ ] HR views workforce dashboard

### Phase 4 — Attendance Setup (Admin)
- [ ] Work calendar created
- [ ] Shifts created
- [ ] Shifts assigned to employees

### Phase 5 — Attendance Workflow (Employee → Manager → HR)
- [ ] Employee clocks in
- [ ] Employee clocks out
- [ ] Manager views attendance records
- [ ] HR runs anomaly scan

### Phase 6 — Performance Setup (Admin / HR Manager)
- [ ] Rating scale created
- [ ] Appraisal template created with at least 2 sections and 3 questions
- [ ] Performance settings configured
- [ ] Cycle created and activated

### Phase 7 — Performance Workflow (HR → Manager → Employee)
- [ ] Organisational goal created
- [ ] KPI created for an employee
- [ ] Employee logs KPI progress
- [ ] Manager scores the KPI
- [ ] Manager schedules a check-in
- [ ] Employee receives and acknowledges feedback
- [ ] HR runs AI insights

### Phase 8 — Advanced Performance (HR Manager)
- [ ] 360 review created and raters added
- [ ] Raters submit responses
- [ ] 360 report generated
- [ ] Probation review created and finalised
- [ ] PIP created, objectives added, and closed out
- [ ] Calibration session created and finalised

### Phase 9 — Recruitment (HR → Manager)
- [ ] Manager submits requisition
- [ ] HR approves requisition
- [ ] HR creates job posting
- [ ] Application added/received
- [ ] Shortlist generated and approved
- [ ] Interview scheduled and scored
- [ ] Offer created and sent

### Phase 10 — Assets (Admin / HR)
- [ ] Asset categories, types, and locations created
- [ ] Asset created in the register
- [ ] Asset assigned to employee
- [ ] Asset returned
- [ ] Maintenance scheduled and completed

### Phase 11 — Policies (HR Manager)
- [ ] Policy category created
- [ ] Policy created and published
- [ ] Attestation assigned to all employees
- [ ] Employee acknowledges policy
- [ ] Attestation dashboard shows 100% completion

### Phase 12 — Reports & Graphs (HR Manager / Admin)
- [ ] Performance reports page loads (all 14 tabs)
- [ ] At least one report exported to Excel
- [ ] At least one report exported to PDF
- [ ] Executive Graphs dashboard shows charts
- [ ] Scheduled report created for weekly delivery
- [ ] Leave reports page loads
- [ ] Audit log shows all test actions
- [ ] Settings audit log reviewed

---

*End of Mkhaya Hub User Guide — v2026-05*
