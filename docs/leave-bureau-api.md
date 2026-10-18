# Leave Bureau API

For a bureau's own systems, or a client's HR system, to read and record leave without anyone
re-keying it.

## Authentication

A Sanctum personal access token belonging to a **bureau agent**. Send it as a bearer token:

```
Authorization: Bearer <token>
Accept: application/json
```

Tokens are issued from the Hub. Treat one as you would a password: it carries everything the agent
can do across every client they administer.

## Naming the client

**Every request must say which client company it concerns**, with `?client=<uuid>` (or `client` in
the body on `POST`). A bureau token belongs to an agent who administers many employers, so "the
current client" is not something a stateless request can assume.

The uuid is checked against the agent behind the token. Naming a company they do not administer
returns **404**, not 403 — the API does not confirm which companies exist on the installation.

A client whose Leave Bureau activation is inactive, suspended, or on a lapsed trial also returns
404.

## Rate limit

120 requests a minute per token. These endpoints are meant for periodic synchronisation, not for
walking an employee register in a loop.

---

## Endpoints

### Balances

```
GET /api/leave/balances?client={uuid}
```

Optional: `cycle={uuid}` (defaults to the client's current cycle), `employee={uuid}`,
`per_page` (default 100, max 500).

```json
{
  "data": [
    { "employee_id": 42, "leave_type": "Annual Leave", "code": "ANN",
      "opening": 20, "accrued": 1.25, "taken": 5, "available": 16.25, "pending": 2 }
  ],
  "meta": { "page": 1, "per_page": 100, "total": 1, "pages": 1 }
}
```

### One employee's balance

```
GET /api/leave/employees/{employee_uuid}/balance?client={uuid}
```

`spendable` is `available` less leave already applied for. It is the figure to check before
offering somebody dates — `available` alone will let them book the same days twice while the first
request is still with a manager.

### Applications

```
GET  /api/leave/applications?client={uuid}
GET  /api/leave/applications/{uuid}?client={uuid}
POST /api/leave/applications
```

Filters: `status` (`draft|pending|approved|rejected|cancelled`), `from`, `to`, `per_page`.

Fetching a single application also returns `days_breakdown` — one row per calendar day with the
reason any day cost nothing. That is what answers "why is this four days and not five".

**Creating one:**

```json
POST /api/leave/applications
{
  "client": "9b1c…",
  "employee": "4f2a…",
  "leave_type": "ANN",
  "from": "2026-06-22",
  "to": "2026-06-26",
  "reason": "Family visit",
  "capture_source": "client_instruction"
}
```

`leave_type` accepts the short code (`ANN`, `SICK`) or the type's uuid.

**Part of a day.** `day_portion` takes `full` (the default), `first_half`, `second_half` or
`hourly`. A half day applies to the boundary it names — asking for `first_half` across a fortnight
takes the first morning off, not ten half-days.

`hourly` is for one day and needs the clock times:

```json
POST /api/leave/applications
{
  "client": "9b1c…",
  "employee": "4f2a…",
  "leave_type": "ANN",
  "from": "2026-06-22",
  "to": "2026-06-22",
  "day_portion": "hourly",
  "starts_at": "09:00",
  "ends_at": "11:00"
}
```

Two hours of an eight-hour day costs 0.25 days, and the ledger moves by exactly that. Times are
required when `day_portion` is `hourly` and the request is refused without them — treating a missing
pair as a whole day would deduct eight times what was asked for. The leave type must allow it
(`hourly_allowed`), and the request must not span two days.

Leave created through the API is recorded as coming from the API, never as self-submitted. A
client's HR system booking on somebody's behalf is exactly what that distinction is for.

Every rule the screens enforce applies here: notice periods, backdating limits, document
requirements, balance and negative-balance rules. A request that would be refused in a browser
returns **422** with the reason.

**Documents.** Leave captured through the API is treated like a bureau capture rather than a
self-submission, so a missing certificate does not block the request — but the application is
flagged as owing one, cannot be approved until it arrives, and will not reach payroll. Attach it
with the document upload endpoint, or the bureau screens.

### Decisions

```
POST /api/leave/applications/{uuid}/approve?client={uuid}   { "comment": "Fine" }
POST /api/leave/applications/{uuid}/reject?client={uuid}    { "reason": "Too busy that week" }
POST /api/leave/applications/{uuid}/cancel?client={uuid}    { "reason": "Trip called off" }
```

Approval moves the ledger only once the whole chain is satisfied. Cancelling approved leave posts a
reversal rather than deleting anything, so both movements stay visible.

Deciding an application with nothing outstanding returns **409**. Leave locked into a closed payroll
period returns **422** — reopen the period first.

### Calendar

```
GET /api/leave/calendar?client={uuid}&from=2026-06-01&to=2026-06-30
```

Approved and pending leave overlapping the period. Defaults to the current month.

### Reports

```
GET /api/leave/reports?client={uuid}            # what can be asked for
GET /api/leave/reports/{key}?client={uuid}      # run one
```

Keys: `balances`, `taken`, `liability`, `expiry`, `exceptions`, `pending`, `transactions`,
`statement`. Optional `from`, `to`, `employee_id` (required for `statement`).

Every report returns the same shape — `columns`, `rows`, `totals` — so one consumer can render any
of them.

The **liability** report states its basis in the payload: basic salary over 21.67 working days a
month, on unused paid leave. It is a calculation from the ledger, offered as an input to whatever
provision a client's accountants decide on — not as that provision.

---

## Errors

| Status | Meaning |
|---|---|
| 401 | No token, or a token that is not valid |
| 404 | The client, employee, application or leave type is not one this token can reach |
| 409 | Nothing outstanding to decide |
| 422 | The request breaks a leave rule, or `client` was not named |
| 429 | Rate limit |

Validation errors carry the usual Laravel shape:

```json
{ "message": "…", "errors": { "leave_type": ["Not enough Annual Leave: 2 day(s) available, 5 requested."] } }
```

## Notes for implementers

- **Poll, don't walk.** `balances` and `calendar` are cheap; pulling every application every hour is
  not. Use `from`/`to`.
- **Uuids, not ids.** Employees, applications and leave types are addressed by uuid. The numeric
  `employee_id` in a balance row is internal and may not be relied on across installations.
- **Dates are `YYYY-MM-DD`** throughout, in the client's own working calendar. Weekends, public
  holidays and shutdowns are already excluded from `days`.
