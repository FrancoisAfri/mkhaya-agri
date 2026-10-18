# Workflow & Automation — user guide

For everyone who uses Mkhaya Hub's workflows: people making requests, approvers, process designers,
process managers, auditors and executives. Find it under **Operations → Workflow & Automation**.

## 1. My Work

**My Work** is the first screen. It lists everything waiting for you across the company's workflows, the
most urgent first:

- **Assigned to me**: tasks and approvals that are yours. A chip shows when each is due; red means overdue.
- **My queues**: work shared by a team. Open one and **Claim** it to take it; the rest of the team then
  sees it as taken. **Return to queue** gives it back.
- **Delegated to me**: work of colleagues who have asked you to act for them while they are away.
- **Recently completed by me**.

When an approver asks you for more information, a banner appears at the top with a link to the request.

## 2. Making a request

**Start a request** lists the request forms your company has published. Open one, fill it in and press
**Submit**. Fields marked * are required; some appear only when an earlier answer needs them (for example a
reason when there is no purchase order). Totals marked *Calculated* fill themselves in.

- **Save draft** keeps what you have typed; the form opens with it next time.
- Attachments are filed in Document Management as you choose them (they are checked for type and
  malware) and linked to your request.
- Pressing Submit twice does not create two requests.

Requests that belong to a module (leave, expense claims, purchase requisitions) are still made in that
module. When your company runs them through the workflow engine, their approvals appear in My Work like any
other task.

**My requests** shows each request you made, its status and who it is waiting on. Open one to see:

- **Details**: what you entered and, for module requests, the business record (with a link to it when you
  may open it).
- **Path**: the workflow drawn with the route your request took. Done steps are filled, the step it waits
  at pulses, and the connections it followed are solid.
- **History**: every step, with when it started and how it ended, including which condition chose a route.
- **Comments & documents**: add a comment or a supporting document.

You can **Cancel request** while it is running; give the reason. Everyone waiting on it is told.

## 3. Deciding a task

Open a task from My Work. The right-hand panel shows the instructions and your choices:

| Choice | What happens |
|---|---|
| **Approve** / **Complete** | The request moves to the next step. A comment is optional. |
| **Reject** | Needs a reason. The request follows its rejection route (usually it ends as rejected). |
| **Return for correction** | Needs a reason. The request goes back (often to the requester) and returns to you afterwards. |
| **Ask for information** | Your question goes to the requester. The task stays with you and its deadline is paused until they answer. |
| **Abstain / declare a conflict** | Offered on committee approvals; your vote is not counted. |

Some tasks have their own form to fill in before you decide.

The engine, not the screen, enforces the rules: you cannot approve your own request, and you cannot approve
a second level of a request you already approved, even through a delegation.

**Delegate** hands one task to a colleague (when the step allows it). **Summarise** (when AI is enabled)
writes a short summary of the request so far.

## 4. Delegations

**Requests → Delegations → Delegate my tasks**: choose who acts for you, from and until when, and optionally
only for some categories. While it is active they see your tasks under *Delegated to me* and decide them
on your behalf; the record shows both names. It ends on its own at the end date, or press **End**. A
delegate never gets wider access to the records themselves, and never approves their own request through
you.

## 5. Designing workflows (designers and publishers)

**Design → Workflows** lists the company's workflows and their versions. Start one in three ways:

- **From a template**: 31 ready processes across Finance (supplier invoices, payment authorisation, petty
  cash, staff loans, budget transfers, travel, expense claims), HR (leave, new positions, onboarding,
  offboarding, training, overtime, confidential disciplinary cases), Procurement (requisitions, supplier
  onboarding), Legal (contracts), Sales (quotation discounts), IT (access, change with a 2-of-3 board),
  Governance (document review, policies, board resolutions, a monthly compliance review that starts itself),
  SHEQ (incidents, corrective actions), Operations (maintenance, vehicle bookings, asset disposal, batch
  release) and Warehouse (stock adjustments). Filter them by category or search. Installing makes an
  editable draft your company owns; check who each step goes to, then publish. A platform administrator
  must switch into a company first.
- **Describe it**: write the process in plain language. A draft is built (by AI when enabled, otherwise
  from the words you used). It never chooses people; you check every step before publishing.
- **New workflow**: an empty canvas. Choose the business record it runs on, or *None — a request form*.

### The designer

Drag steps from the palette (or click them) onto the canvas. Drag from a step's right edge to another
step to connect them. Click a step or connection to edit it on the right; **Delete** removes the
selection. **Tidy** lays the workflow out; the minimap and zoom controls help with large ones; **Find a
step** jumps to one by name.

| Step | Use it for |
|---|---|
| Task, Review | Work a person (or a queue) does; may have its own form. |
| Approval | Any one, everyone, or a quorum of approvers. Options: allow return for correction, abstaining, delegation; whether an earlier approver may approve again. |
| Decision | Routes by conditions on the form or the business record (*amount is greater than 50 000 and currency equals ZAR*). One route is the default. |
| Parallel split / Join | Run branches at the same time; continue when all, the first, or a quorum finish, optionally cancelling the rest. |
| Wait | A number of (business) hours, or until a date on the form. |
| Notify | A message to people, with `{{field}}` placeholders. |
| Automated action | Set a value, add a comment, schedule a reminder, or (if your administrator permits it) set the business record's outcome. |
| Generate document | A PDF from a template, filed in Document Management. |
| Sub-workflow | Start another published workflow, optionally waiting for it. |
| Manual intervention | Hand the request to a person to sort out. |

Each step can have a **service level**: a target in hours (business hours by default), when to warn, and
what to do when it is overdue (remind, tell the manager, reassign to the manager, tell the process owner,
raise the priority, raise an exception).

Who a step goes to: a named person, everyone with a role, a department head or members, the requester's
line manager, the requester, the record's owner, the budget holder, a person chosen on the form, or the
process owner. If nobody can be found when the step is reached, the task goes to the process owner (or the
exception queue) instead of disappearing.

**Check** lists everything that would stop publication, each with the step it is on and how to fix it.
Click a problem to jump to it. **Explain** describes the workflow in plain language.

**Publish** (or **Submit for review** when your company requires review): another person with publishing
rights approves it. A published version never changes. Editing makes a new draft version; publishing it
affects new requests only. Requests already running stay on the version they started with.

### Forms

**Design → Forms**: build reusable request and task forms. Field types include text, numbers, amounts, dates
and ranges, dropdowns and multi-selects, yes/no, employee, department and supplier pickers, attachments,
calculated values, acknowledgements, and repeating line items. Any field can be shown, or made required,
only when a condition holds. Mark a field **Sensitive** to keep its value out of notifications and AI.
Saving makes a new form version; published workflows keep the version they were published with.

**Design → Form library** has 46 ready-made forms: general requests, room bookings and equipment loans; HR
forms (personal and bank details changes, remote work, resignation, exit interview, grievance, improvement
plans, sick notes, study assistance, shift swaps, clocking corrections, referrals); Finance (supplier bank
changes, customer credit, credit notes, write-offs, refunds, capital expenditure, invoice disputes);
Procurement (quotations, sole-source justification, supplier scorecards, delivery discrepancies); IT
(software, faults, lost devices); Legal (conflicts of interest, gifts, POPIA requests, whistleblowing,
NDAs); Sales (FICA onboarding, complaints); SHEQ (near misses, hazards, permits to work, toolbox talks,
PPE) and Operations (vehicle inspections, access cards, visitors). Switch on *Include the forms inside
workflow templates* to see the 27 forms the templates use as well. Preview a form's fields, then **Add**:
your company gets its own copy at version 1 to change as it likes.

### Versions

On a workflow's page:

- **Compare** two versions to see which steps were added, removed or changed.
- **Roll back** to an earlier version; it becomes the newest version, for new requests.
- **Export** a workflow to a file and **Import** it, here or in another company. Named people are cleared
  on import.
- **Suspend** stops new requests while running ones continue. **Retire** ends the workflow.
- **Migrate** (process managers) moves chosen running requests to the published version. This works only
  when every step they are waiting at exists in the new version; completed approvals and history are kept.

## 6. Operations (process managers)

- **Requests in the system**: every request you may see, filtered by status, workflow, SLA and priority.
- **Exceptions**: automated steps that keep failing, steps nobody could be assigned to, overdue tasks and
  undelivered events. **Retry now** repeats the step safely; **Resolve by hand** marks it handled and lets
  the request continue. Exception tasks on an approval are **Reassigned** to someone who may approve.
- **Reassign** any open task from its task screen, with a reason.

## 7. Performance (managers and executives)

**Operations → Performance** has two views:

- **Operations**: running requests, approvals awaiting decision, overdue tasks, SLA breaches, requests
  needing intervention, failures, bottleneck steps (average and median wait), approvals waiting by
  department, and workload per person.
- **Executive**: requests started, median and average end-to-end time, SLA compliance, rejection and rework
  rates, weekly throughput, processes side by side, and where the time goes (with people, or waiting on
  timers and systems).

Filter by period, workflow, department and priority. **Export** any report as CSV or PDF. **Ask about
performance** answers questions such as *Which workflows had the most SLA breaches last month?* from the
figures you are allowed to see.

## 8. Confidential workflows

A workflow marked confidential (HR cases, disciplinary matters, whistleblowing) is visible only to the people
named on it and those taking part in each request, whatever else they may do in the system.
Notifications about it carry a link only, and AI is never used on it.
