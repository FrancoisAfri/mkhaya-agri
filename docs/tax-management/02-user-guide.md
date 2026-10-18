# Tax Management — user guide

For finance and tax staff who prepare, review and approve tax in Mkhaya Hub. Menu: **Finance → Tax Management**.

The module keeps the records; it does not decide tax treatments. Rates, codes and field mappings are
configuration that your tax professionals confirm before you rely on them.

## Who does what

Three people are always involved in anything that is filed or relied on: the **preparer**, the
**reviewer** and the **approver**. The system refuses to let one person take two of those steps on the
same return, computation, rule or reconciliation. Your administrator gives each person a role:

| Role | Typically does |
|---|---|
| Tax Preparer | Builds registers, imports payroll, reconciles, prepares returns and computations |
| Tax Reviewer | Reviews, signs off reconciliations |
| Tax Approver | Approves returns, computations and rules; records submissions and SARS outcomes |
| Tax Administrator | Registrations, rules, periods, imports |
| Tax Compliance Officer | The compliance calendar |
| Tax Auditor | Read-only, including the audit trail |

Employee-level payroll tax figures need the separate *view payroll tax* permission.

## 1. Set up (once)

1. **Tax Setup → Tax registrations → Add registration** — one per tax a business is registered for, with the
   reference, filing frequency, preparer and reviewer. If Accounting is not used, the business is the company.
2. **Confirm it applies.** Nothing is generated until someone confirms, with the basis (e.g. the SARS
   registration letter). Then the periods and deadlines appear.
3. **Tax Setup → Tax rules.** Use *Draft from Finance codes* (VAT) and *Draft income tax rates* (CIT). Check each
   draft — treatment, rate, VAT201 field, dates — *Simulate* it with known answers, *Submit* it, and have an
   approver *Approve & publish* it. A published rule is never edited; a rate change is a new version from a date.
4. **Import history if you have it** (Tax Setup → Import historical data): registrations, opening balances, past
   returns, assessed losses. Download the template, upload, check the preview, and have someone else sign it off.

## 2. A VAT period

1. **Tax Work → Tax periods → open the period → Build register.** Every posted invoice, credit note and expense
   with VAT becomes a register line, traced to its document.
2. **Resolve what needs attention** (VAT register → *Needs attention*). *Classify* a line that has no code, or
   *Accept* an exception with a reason. The invoice itself is never changed.
3. **Run reconciliation.** The register is compared with the VAT control accounts journal by journal. Explain
   every difference above the tolerance; someone else signs it off.
4. **Prepare return.** The VAT201 is drawn from the register. Adjust a field only with a reason; the calculated
   value stays beside it. Attach the signed supporting schedule. *Send for review*.
5. The **reviewer** passes it; the **approver** approves it. Approval creates the liability and the SARS
   submission record.
6. **eFiling capture sheet** (PDF/CSV) — capture the return on eFiling from it.
7. **Record submission** with the SARS reference. If eFiling timed out, record *Outcome unknown* — and check
   eFiling before sending again; the system will ask you to confirm that you did.
8. When SARS acknowledges, attach the receipt and **Record SARS outcome → Accepted**.
9. Pay SARS in **Finance** as usual, then **Liabilities and payments → Allocate payment**.

## 3. Payroll taxes (EMP201)

On a PAYE period: **Import payroll** (released pay runs only; PAYE exactly as payroll calculated it),
**Run reconciliation** against the payroll ledger accounts, then prepare, review, approve and submit the
EMP201 as above. SDL is included only for a business with a confirmed SDL registration.

## 4. Income tax and provisional tax

- **Tax Work → Income tax → Classify accounts** once per business: what is exempt, not deductible or capital.
- **Prepare** on the CIT period: the year's profit from the ledger, your classifications, and adjustments you add
  (with the section of the Act). Assessed losses brought forward are set off within the legal limit.
- **Provisional (IRP6)**: prepare on each PROV period with the estimated taxable income for the year and the basic
  amount. The first payment is half the year's tax; the second is the balance.
- Review and approve as with returns. *The year so far* shows estimates, payments, the computation and the
  SARS assessment side by side. Record an assessment under *Liabilities and payments → Record assessment*.

## 5. Staying on top

- **Dashboard** — what is overdue, waiting for review or sign-off, unconfirmed submissions, what is owed.
- **Compliance calendar** — every filing and payment deadline. Owners are reminded at 14, 7, 3 and 1 day(s);
  a missed deadline is escalated once. Submitting and paying close deadlines automatically.
- **Tax reports** — 18 standard reports; every export states the business, period and when it was produced.
- **Tax audit trail** — who did what and why; *Verify chain* proves nothing was altered.

## If something is refused

The message says why and what to do. The usual reasons: the period is already submitted or locked (an
authorised person can reopen it, with a reason); someone else must take the next step; evidence or a
reconciliation sign-off is missing; or the register changed since the return was prepared (prepare it again).
