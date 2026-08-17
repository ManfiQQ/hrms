# ADR 0003 — Multi-Role Authority Model

- **Status:** Accepted
- **Date:** 2026-08-10
- **Supersedes:** `adr/0001` decision 2 (`core_role` as a single enum column on
  `employees`). `adr/0001` decisions 1, 3, 4, 5, 6, 7 stand unchanged except where
  re-expressed in terms of the pivot introduced here.
- **Withdraws:** `hr_scope` (`PAYROLL | OPERATIONS`) — the distinction it was invented to
  model does not exist. Referenced in **seven** documents, all corrected: `CLAUDE.md` §10
  and §11 item 2; `adr/0001` decision 6, follow-up item 10 and Consequences; `adr/0002`
  decision 5, Consequences and References; `business-rules.md` § Cross-company approval;
  `schema.md` `approval_requests`; `payroll-notes.md` open item 5; and
  `employee-master.spec.md` BR-14 and §10.
- **Affects:** `employees`, `employee_roles` (new), `employee_job_functions` (new),
  `job_functions` (new), `sequences` (new), `employee_status_history`,
  `approval_requests`, `companies`, `CLAUDE.md` §5, §10, §11, `conventions.md` §2–3,
  `schema.md`, `business-rules.md`, Employee Master spec,
  Auth & RBAC spec (not yet written)

---

## Context

`adr/0001` resolved the legacy system's overlapping `core_role` / `level` taxonomy into
two fields with one job each. That resolution was correct in principle and is retained.
Its **implementation assumption** was not: it modeled `core_role` as a single enum column
on `employees`, one value per person.

Client review of the Employee Master spec established that assumption is false, and
falsifies it in three separate ways.

### A person holds several roles, and the roles differ per company

The canonical example given by the client — one employee, five roles across two
companies:

| Company | Roles held |
|---|---|
| AHS | BDO, Account |
| AIM | Manager, Account, Admin |

A single enum column cannot store five values. More importantly, it cannot store **which
role applies to which company**. This person is a Manager at AIM and *not* a Manager at
AHS, so the question "what authority does this employee have?" has **no answer** until a
company is named. Authority is per-company, and the schema had nowhere to put that.

### The role list mixes three different concepts

The full list supplied by the client was:

```
Assistant Director · HR · Account · HOD · Manager
BDO · Admin · Media · Logistic · Live Host · Intern
```

Separating these by what they actually answer:

- **Authority** — who approves what: Assistant Director, HR, Account, HOD, Manager,
  and Supervisor (absent from the list above but confirmed separately as the role held
  alongside Operation Crew in the Logistics branch).
- **Job function** — what work the person does: BDO (Business Development Officer),
  Admin (office administration), Media, Live Host, Operation Crew.
- **Neither** — `Intern` is an `employment_type`, which already exists as its own field.
  `Logistic` is a **branch**, a place of work, which `adr/0002` already models.

Putting all eleven into one authority enum would force the approval engine to answer
questions that should not exist — "can a Live Host approve a leave request?" — and would
recreate precisely the legacy defect `adr/0001` was written to remove. Further, the client
has confirmed job functions will grow as the remaining workplaces (factory, studio,
galleria, restaurant) are mapped, so the list is not stable enough to be an enum at all.

### Salary visibility is a role, not an HR sub-scope

`adr/0001` and `adr/0002` both recorded a required Auth & RBAC input: an `hr_scope` field
distinguishing **Payroll HR** (salary, documents, payslip configuration) from
**Operations HR** (leave, attendance, OT entry), on the understanding that some HR staff
see salary and others do not.

The client has since stated the rule directly: **only the `Account` role sees salary. No
HR sees salary, regardless of how many HR staff there are.** There is no Payroll HR. The
distinction `hr_scope` was designed to express does not exist, so the field is withdrawn
rather than deferred.

### Company list correction

The entity list in `CLAUDE.md` §5 and `business-rules.md` was incomplete and is corrected
here — see decision 9.

---

## Decision

### 1. Authority roles move to a pivot table; `employees.core_role` is removed

`core_role` ceases to exist as a column. Authority is expressed as a triple:

```
employee_roles
  id
  employee_id      FK → employees
  company_id       FK → companies      -- which company this role applies in
  role             enum                -- see decision 2
  effective_date   date                -- when it takes effect
  assigned_by      FK → users
  revoked_date     date, nullable      -- NULL = currently held
  revoked_by       FK → users, nullable
  revoke_reason    text, nullable
  created_by, updated_by, timestamps
```

**`company_id` here is a real reference to a company, not a tenant marker.** It answers
"in which company does this role apply," not "which tenant owns this row." That
distinction governs decision 7 and must not be blurred.

**`effective_date` is distinct from `created_at`.** A promotion is typically effective
before HR gets to enter it; the ledger records both the date it applies from and the date
it was typed.

**`STAFF` is deliberately not a role value.** With a pivot, an ordinary staff member is
someone with **no** authority row. Defining a value for the absence of authority would
create a second way to express the same state, and the two would eventually disagree.
This is the same reasoning `adr/0001` decision 2 used to exclude `MASTER_ADMIN`.

**Rows are never deleted.** Revoking a role sets `revoked_date`; re-granting it later
inserts a **new** row. This preserves the full cycle — held Jan–Aug, revoked Aug,
re-granted November — which a boolean toggle cannot express. All authority queries filter
`WHERE revoked_date IS NULL`.

**No `is_enabled` flag exists on this table, and none may be added.** A flag would mean
every authority check must test two conditions instead of one, and the check that forgets
the second is a silent security hole. Revocation is the single mechanism.

**Rejected alternatives.** A JSON column on `employees` was rejected: it violates
`conventions.md` §4 (structured data over parsed text) at the single most
correctness-critical point in the system, carries no foreign keys, cannot be indexed for
"who is Account at AIM," and has no place to record who granted what and when. A pivot
plus a `primary_role` column on `employees` was rejected for reintroducing two sources of
truth for one fact — the exact failure `adr/0001` exists to correct. Where a single
headline value is needed for display, `level` already provides it.

### 2. Three separate concepts, three separate structures

| Concept | Answers | Where it lives | Form |
|---|---|---|---|
| **Authority role** | What may this person approve, and where | `employee_roles` | Fixed enum |
| **Job function** | What work does this person do | `employee_job_functions` | Reference table |
| **Level** | Where do they sit in the org chart | `employees.level` | Fixed enum, display only |

**Authority roles — six values, fixed enum:**

```
ASSISTANT_DIRECTOR, HR, ACCOUNT, HOD, MANAGER, SUPERVISOR
```

This list stays an enum precisely because it is not meant to change casually. Adding an
authority role changes the approval chain and requires an ADR, not a UI form.

Note this is **not** the same six as `adr/0001` decision 2: `STAFF` is removed (see
decision 1), `ACCOUNT` is added. `MASTER_ADMIN` and `DIRECTOR` remain absent for the
reasons `adr/0001` decisions 2 and 7 give, both of which still hold.

**Job functions — a reference table, not an enum:**

```
job_functions
  id, name, description, is_active, timestamps, soft deletes

employee_job_functions
  id, employee_id, company_id, job_function_id,
  created_by, updated_by, timestamps, soft deletes
```

Starting set: `BDO`, `Admin`, `Media`, `Live Host`, `Operation Crew`. This will grow as
the remaining workplaces are mapped.

**Master Admin manages `job_functions`, and removal is soft delete only.** Hard-deleting a
function that employees currently hold would orphan their rows and break history. A
"deleted" function is deactivated: it disappears from the assignment picker, existing
assignments stay intact, and it can be reactivated if the workplace reopens. HR assigns
job functions; only Master Admin creates or deactivates the types themselves, which keeps
the vocabulary from drifting into three spellings of the same thing (`CLAUDE.md` §5).

**Removed from the role list entirely:**

- `Intern` → already a value in `employment_type`. An intern doing media work has job
  function `Media` and `employment_type = INTERN`. Two facts, two fields.
- `Logistic` → a **branch**, modeled by `adr/0002`. The roles *within* it are
  `Operation Crew` (job function) and `Supervisor` (authority). Keeping `Logistic` as a
  role as well would give one name two meanings, which `CLAUDE.md` §5 exists to prevent.

### 3. Sensitive roles are restricted — only Master Admin may grant them

Without this, decision 5 is unenforceable: an HR who can grant roles freely can grant
themselves `ACCOUNT` and read every salary in the group by lunchtime. The rule would not
be *violated* — it would be *walked around through the front door*, and it would look like
ordinary HR activity in the audit log.

| Role | Restricted | Changeable | Reason |
|---|---|---|---|
| `ACCOUNT` | **Yes — hardcoded** | No | The only door to salary data |
| `HR` | **Yes — hardcoded** | No | Can create further HR; self-propagating |
| `ASSISTANT_DIRECTOR` | **Yes — hardcoded** | No | Top of the approval chain |
| `HOD` | **Yes** | By Master Admin | Skips approval stages — see below |
| `MANAGER` | No | By Master Admin | Routine operational change |
| `SUPERVISOR` | No | By Master Admin | Base operational tier |

**HOD is restricted because it is structurally different, not merely more senior.** An HOD
may approve **skipping the Manager/Supervisor stage** for their own company's staff in
their department, and their own requests route **directly to HR** (`adr/0001` decision 3,
`adr/0002` decision 4). Granting HOD does not add a tier — it *bypasses two*. HOD
assignment also changes rarely, so restricting it costs almost nothing operationally.

**Manager is deliberately not restricted.** It is a routine operational appointment that
changes often, its authority spans one stage and no sensitive data, and every grant is
visible in the audit log. Routing every Manager appointment through Master Admin would
pull that account into daily HR work and erode the separation `adr/0001` decision 4
establishes.

**Only Master Admin may change any `is_restricted` value, and every change is written to
`audit_logs`.** If HR could edit the flags, the entire mechanism would be one click deep.

**The top three flags are hardcoded and cannot be changed at all.** There is no legitimate
situation in which HR should be able to grant `ACCOUNT`, `HR`, or `ASSISTANT_DIRECTOR`.
Where no legitimate case exists, no control should exist — the same structural reasoning
`adr/0001` decision 2 applies to excluding `MASTER_ADMIN` from the enum.

### 4. Approval routing under multiple roles

**As an approver — no ambiguity.** If the employee holds *any* role qualifying for the
stage in question, they may act on it, subject to the existing checks: same company
(`adr/0002` decision 4, except HR and Assistant Director), and never their own request.
Multiple roles mean *more* things approvable, not a conflict.

**As a requester — the requester's rank is their highest role held anywhere in the group.**
Using the authority hierarchy below, across **all** companies, not only their payroll
employer.

```
ASSISTANT_DIRECTOR  →  HR  →  HOD  →  MANAGER  →  SUPERVISOR
```

An employee with no authority row anywhere is routed as ordinary staff.

The example employee is a Manager at AIM while employed by AHS, where he holds only BDO
and Account. He is routed as a **Manager**. Requiring him to pass a Manager stage would
put a peer in authority over him, which is organizationally wrong and would be felt as
such. Accepted consequence: **rank can be conferred by a position at another group
company.** In a group this size, where staff genuinely work across entities, that is the
correct reading. It would not be in a larger, more separated group, and this is the reason
to revisit if the group structure changes.

**`ACCOUNT` is deliberately absent from the hierarchy.** It is not a management tier — it
is functional authority over money. The other five answer "who reports to whom"; `ACCOUNT`
answers "who may touch funds." Ranking them on one scale would give a newly hired Account
a shorter approval path than a ten-year Supervisor leading eight people. `ACCOUNT` is
ignored for routing entirely and consulted in full for salary permission (decision 5).
This also disposes of the otherwise unanswerable question of whether `ACCOUNT` outranks
`HOD` — they are not on the same axis.

**Entitlement and approvers come from `employees.company_id`.** Leave is drawn from the
payroll employer's quota and approved by that company's people. Only the requester's
*rank* is read group-wide.

**`approval_requests` must record which role was used to route each request**, so that
"why did this skip the Manager stage?" has a written answer months later.

### 5. Salary visibility is the `ACCOUNT` role, and `hr_scope` is withdrawn

**Only an employee holding the `ACCOUNT` role may view salary data.** No `HR` account may,
regardless of how many HR staff exist. Access is per-company: the role is held at a
company, and it grants salary visibility at that company.

`hr_scope` is **withdrawn, not deferred**. It was designed to distinguish two kinds of HR
that do not exist. Every reference to it in `CLAUDE.md`, `adr/0001`, `adr/0002`, and
`payroll-notes.md` is removed in the same commit as this ADR.

The replacement is strictly better in one respect worth stating: `hr_scope` had **no
enforcement mechanism** — anyone who could edit an employee could change its value. The
`ACCOUNT` role is protected by decision 3's hardcoded restriction, so the rule is
structural rather than declarative.

This closes the `CLAUDE.md` §10 open item "Data visibility vs approval authority" as it
applies to salary. The wider visibility question — what a cross-company approver may read
generally — remains open and still belongs to the Auth & RBAC spec (`adr/0002`
decision 5). Approval authority still confers no read access.

### 6. One employee record per person; `company_id` means payroll employer only

**An employee has exactly one `employees` row and one `employee_no`, no matter how many
companies they hold roles in.** `employees.company_id` remains **NOT NULL** and is
narrowed to a single meaning:

> **`employees.company_id` answers "who is the legal and payroll employer."
> `employee_roles` answers "where does this person have authority, and what."
> Two questions, two places, never mixed.**

This extends the pattern `adr/0002` established as *shared structure, scoped data*.

**No `secondary_company_id` column exists, and none may be added.** The requirement it
would serve — seeing at a glance that someone also works at another company — is met by
querying the pivot and rendering it:

> **Employer (payroll):** AHS
>
> **Also serving at:** AHS — BDO, Account · AIM — Manager, Account, Admin

A stored column would duplicate a fact the pivot already holds, would drift the moment a
role is revoked and the column is not updated, cannot represent a third company, and
carries *less* information than the pivot it copies. A fact that can be derived from
existing data must not be stored a second time.

**Not modeled: an employee genuinely paid by two entities.** If a person is ever split
across two payrolls with two EPF contributions, this model cannot express it and would
need revisiting. Confirmed with the client as not occurring.

### 7. Company transfer — three cascade categories

Transfers between group entities **do occur, rarely**. When they do, `employees.company_id`
changes in place; the record and `employee_no` stay with the person.

Child tables fall into three categories, distinguished by what `company_id` *means* on
each:

| Category | `company_id` means | On transfer | Tables |
|---|---|---|---|
| **Descriptive** | Tenant marker; the row describes the *person* | **Cascade** | `employee_family_members`, `employee_education_history`, `employee_employment_history`, `employee_documents` |
| **Event** | The employer *at the time it happened* | **Frozen forever** | `employee_status_history`, and all Phase 2 leave / payroll / attendance tables |
| **Company-reference** | A real reference to a company, unrelated to employment | **Untouched** | `employee_roles`, `employee_job_functions` |

**The test to apply when adding any new table** — if this person's payroll employer
changed tomorrow, would this row still be true?

- Yes, and it is about the person → **descriptive**, cascade
- Yes, because it happened under the previous employer → **event**, freeze
- Yes, because `company_id` here is not about the employer at all → **company-reference**,
  leave alone

Freezing event records is what keeps payroll and statutory history attributable to the
entity that actually paid. A payslip issued by AIM must not be rewritten as TURSENIA's
because the employee later transferred; that is not an update, it is falsification.

Company-reference rows are untouched for a different reason: a Manager role at AIM is
still a Manager role at AIM after the person's payroll moves. Cascading it would corrupt
the data outright.

**Consequence — frozen rows fall outside the new tenant scope.** After a transfer, the
employee's pre-transfer history rows carry the old `company_id` and the new employer's
tenant scope excludes them. Their Status History tab would appear to begin on the transfer
date, with no error — the same silent-missing-rows failure mode `adr/0002` flags for shared
branches.

**Therefore: event tables accessed through an employee relationship release the tenant
scope.** Permission has already been decided at the employee level — if the user may read
this employee, they may read this employee's history. Filtering again per row adds no
security and breaks the record. Queried **directly** for reporting, tenant scope applies in
full, so "how many promotions did TURSENIA make this year" stays correctly scoped. This
carve-out is documented in `conventions.md` alongside the `adr/0002` one, because Phase 2
will create many event tables and the rule must be findable from outside this ADR.

### 8. Role history lives in the pivot, not in the status ledger

`employee_status_history` records changes to `staff_status`, `position_id`,
`department_id`, and `level` — **four** `change_type` values:

```
STAFF_STATUS, POSITION, DEPARTMENT, LEVEL
```

`CORE_ROLE` is **not** among them. The pivot already records every grant and revocation
with dates, actors, and reasons; writing the same event to a second table would create two
records of one fact that can disagree.

The ledger's shape, confirmed in the same review:

```
employee_status_history
  id, company_id, employee_id,
  change_type,
  old_value, old_label,
  new_value, new_label,
  effective_date, reason, changed_by, created_at
```

Append-only — no `updated_by`, no soft deletes, no `updated_at`. A correction is a new row.

**`old_label` / `new_label` hold a snapshot of the display text at the time.** Storing only
`department_id = 7` would require a join to render, and that join shows the department's
name *today*, not its name *then* — so renaming a department would retroactively rewrite
history. A ledger that changes retroactively is not a ledger. The labels are redundant for
enum types (`CONFIRMED` / `CONFIRMED`), which is accepted: one uniform row shape costs a
few bytes and avoids per-type branching in every reader. **To be reviewed once the system
is running on real data.**

**The UI merges both sources into one timeline**, so HR sees a single chronological history
without the data being stored twice:

```
15 Jan 2026 · Role → Manager (AIM)        [employee_roles]
01 Mar 2026 · Status → CONFIRMED          [employee_status_history]
08 Aug 2026 · Account (AIM) revoked       [employee_roles]
```

### 9. `employee_no` — generation, lifecycle, and the company list

**Generation is a locked sequence table**, not `MAX() + 1`:

```
sequences
  id, key, next_value, timestamps
```

The row for `key = 'employee_no'` is taken with `lockForUpdate()` **inside the same
transaction as the employee insert**. `MAX() + 1` collides when two requests read the
current maximum before either writes — which happens on a double-clicked Save button, two
open tabs, a legacy import running alongside manual entry, or a seeder, and therefore
happens even with the client's operating rule that **one HR does all registration**. That
rule prevents duplicate *people*; it does not prevent duplicate *numbers*, and the two
protections are complementary rather than alternatives.

Deriving the number from `employees.id` was rejected: it leaves visible gaps whenever a
transaction rolls back, couples the number to a primary key, and makes the Master Admin
correction below impossible, since a derived value cannot be edited.

**Format** is unchanged from `employee-master.spec.md` §10 decision 1: `AHS-0001`, always
the `AHS` prefix regardless of employing subsidiary, one group-wide sequence, unique index
group-wide and not composite with `company_id`.

**Lifecycle:**

- **Transfer between entities** — the number **stays with the person**. This is the same
  employment, continuing under a different payroll employer.
- **Resignation or termination** — the number is **retired with them, permanently**. The
  sequence never rewinds and a number is never reissued.
- **Rejoining** — a **new record with a new number**, consistent with
  `business-rules.md` BR-2 (`RESIGNED` and `TERMINATED` are terminal).

The dividing line is **continuity of employment**, not which entity pays: a transfer is one
unbroken employment, a rejoin is two separate ones.

> **⚠ The rejoining bullet is currently unimplementable — found 2026-08-17, and it is a
> contradiction with `adr/0013`, not a gap in this one.**
>
> `adr/0013` decision 1 made `employees.ic_no` **unique**. A rejoiner gets a new record and
> brings **the same IC**, because a person has one. The unique index knows nothing about
> `deleted_at` and nothing about a terminal `staff_status`, so **the second record cannot be
> created**. The only way through is to empty `ic_no` on the old record — which destroys the
> identity on the historical row, and that row is precisely the one `previous_employee_id`
> below is required to point at.
>
> Both ADRs are Accepted and neither is wrong read alone: one says a rejoin is a new record,
> the other says an IC identifies one person. **Nothing in either notices that the two meet.**
>
> The `unique` validation rule added to the two employee FormRequests on 2026-08-17 does not
> cause this and does not worsen it — it converts a raw constraint violation into a message
> naming the field. **The block is the index.**
>
> **⚠ And `ic_no` is not the only one — `users.phone_no` blocks the same flow, and worse.** A
> rejoiner brings the same phone number; it is NOT NULL and unique; `User` has no soft deletes,
> so the old account row survives its freeze and expiry still holding that number. There is no
> validation rule for it anywhere, so `CreateEmployee` fails **inside its transaction as a raw
> 1062 — a 500, not a field message**. A second number is refused by `adr/0006` decision 7, a
> placeholder by BR-A1, reactivation by BR-A18, and skipping the account by BR-A20. Recorded at
> BR-A18 in `auth-rbac.spec.md`.
>
> **The two are one question.** `passport_no` and `fingerprint_id` sit on the same index shape
> and inherit it. Deciding `ic_no` alone leaves the flow exactly as blocked, so the ADR this
> needs covers **every unique identity column, `users.phone_no` included**. It has not been
> written. Recorded in `schema.md` under `ic_no` as well, since that is where somebody hits it.
>
> > **✅ Written 2026-08-17 — `adr/0015`, covering all four columns as this note demanded.**
> >
> > **The rejoining bullet above is not amended, and that is the outcome rather than a
> > detail of it.** `adr/0015` decision 1 keeps a rejoiner on a new record with a new
> > `employee_no` and `previous_employee_id` pointing at the old one; it changes the
> > indexes, not this design. Reactivating the old record was considered again and rejected
> > again — the old record carries `join_date` from the first employment, so leave would
> > accrue from a date years before the person returned, which is exactly what BR-2 makes
> > the break terminal to prevent.
> >
> > A nullable `superseded_at` on `employees` and `users` releases the identity claim
> > without emptying the value, so the historical row keeps the IC that
> > `previous_employee_id` needs it to be identifiable by. **The bullet becomes
> > implementable with its wording intact.**
> >
> > **✅ BUILT 2026-08-17** — `2026_08_17_100000`, `2026_08_17_100100`, and the release logic in
> > `CreateEmployee::supersedePrior()`. **The rejoining bullet above is now executable with its
> > wording untouched**, which was the whole aim: a new record, a new number, a new account,
> > `previous_employee_id` set, and the historical row still carrying the IC that makes it
> > identifiable.
> >
> > ⚠ **The ⚠ block above describes the state BEFORE that migration** and is kept rather than
> > rewritten, because the contradiction it records is why the index has the shape it now has.

**`employees.previous_employee_id`** — self-FK, nullable — links a rejoiner's new record to
their old one. BR-2 already requires reinstatement to reference the prior record but no
column existed for it, leaving the rule unimplementable. Employee Master only **stores**
the link; whether prior service counts toward leave entitlement is a Leave spec decision
that cannot be made at all without this data being captured now.

**Master Admin may edit `employee_no`**, audited, for genuine corrections such as a bad
legacy import value. **A number vacated by an edit is burned, not returned to the pool** —
reissuing it would point previously printed letters and payslips at the wrong person.

**Company list — corrected.** The parent is AL HADDAD SUCCESS SDN BHD (AHS), with
**five** subsidiaries. Three errors are corrected in `CLAUDE.md` §5 and
`business-rules.md`: `SLEGHO ALYA KITCHEN` was missing entirely, `THALHAH` was listed as
an entity when it is a **brand under AIM** and not a registered company, and
`ES SOFEEYA` was spelled as the joined `ESSOFEEYA`.

| Entity | Role |
|---|---|
| AL HADDAD SUCCESS SDN BHD | Parent — also an operating tenant |
| AL HADDAD INTEGRATED MARKETING | Subsidiary |
| ES SOFEEYA ENTERPRISE | Subsidiary |
| ZISH GLOBAL PLT | Subsidiary |
| TURSENIA TRADING | Subsidiary |
| SLEGHO ALYA KITCHEN | Subsidiary |

AHS is an operating tenant with its own staff, not an empty holding row — the example
employee holds BDO and Account there. Master Admin may add further companies without a
migration.

---

## Consequences

**Positive**

- The model now expresses what the group actually is: people holding several roles across
  several entities, which a single enum column could not represent at all.
- Authority, job function, and org level are three structures for three concepts, which is
  `adr/0001`'s principle applied properly rather than abandoned.
- Role history, grant attribution, and revocation come free from the pivot's shape — no
  separate audit mechanism, no second table to keep in step.
- Salary access is enforced structurally (`ACCOUNT` + hardcoded `is_restricted`) rather
  than declaratively, and one `CLAUDE.md` §10 open item closes.
- `hr_scope` is removed from seven documents. A speculative field modeling a
  non-existent distinction is gone before it reached a migration.
- Job functions grow through a UI rather than a migration, avoiding the repair-migration
  pattern `CLAUDE.md` §9 records from the legacy system.

**Costs and constraints accepted**

- **Every authority check is now a query, not a field read.** Eager loading must be
  disciplined or the employee list will N+1. Index `(employee_id, company_id)` and
  `(company_id, role)`.
- **`WHERE revoked_date IS NULL` is load-bearing.** Omitting it returns revoked authority
  as current — a silent security failure, not an error. It belongs in a model scope applied
  by default, not repeated at each call site.
- **Three cascade categories are more to remember than two.** Mitigated by the written test
  in decision 7, but a new table placed in the wrong category corrupts data on transfer.
- **Rank crossing company boundaries** (decision 4) means a position at one company can
  shorten an approval path at another. Correct for this group at this size; revisit if the
  group grows or separates.
- **Event tables release tenant scope through the employee relationship** (decision 7).
  Like `adr/0002`'s shared-rows carve-out, it must be tested in both directions: history
  stays visible after transfer, and direct reporting queries stay scoped.
- **This is a large documentation change, not a patch.** Nine files. `adr/0001`'s central
  decision is superseded barely three days after it was accepted — which is the system
  working as intended, since no code had been written and `CLAUDE.md` Principle #1 is what
  made the discovery cheap.

**Explicitly not changed**

- `employees.company_id` remains NOT NULL. Principle #4 stands.
- `level` remains display-only and never drives authorization (`adr/0001` decision 1).
- Master Admin still has no employee record (`adr/0001` decision 4) — with `core_role`
  gone, this is now enforced by the absence of any `employee_roles` row rather than by the
  absence of an enum value, which is equally structural.
- Director authority remains off-system (`adr/0001` decision 7).
- HOD authority remains strictly same-company (`adr/0002` decision 4).
- `HR` and `ASSISTANT_DIRECTOR` remain the only cross-company approvers, and approval still
  confers no data visibility (`adr/0002` decision 5).

---

## Confirmed but not yet specced

Rules confirmed during this review that belong to modules without specs. Recorded so they
are not rediscovered — or worse, not rediscovered.

**Approval Engine (Phase 0)**

- **Manager endorses; HR decides.** A Manager's stage is an *endorsement*, not an approval
  gate. Options are endorse or decline; **declining requires a written reason**.
- **HR may approve at any time without waiting** for the Manager's endorsement. A request
  does not stall because a Manager is on leave.
- **A stage bypassed this way is marked `APPROVED_BY_HR`, not `SKIPPED`.** The stage
  happened; someone else decided it. The Manager receives an **informational
  notification** — they still need to know their staff will be absent in order to schedule
  work.
- **`approval_requests` must distinguish endorsement stages (non-blocking) from approval
  stages (blocking).** The current schema treats all stages alike.
- **Record which role routed each request** (decision 4).

**Leave (Phase 2)**

- Leave notice: **one week preferred, three days minimum**. A request inside three days is
  **flagged for consideration, not rejected**.
- Hours-bank usage: request by **07:00 on the day of use** (already in
  `business-rules.md`).
- **Does prior service count across `previous_employee_id`?** Undecided. Affects the
  one-year and two-year annual leave thresholds.

**Notification Engine (Phase 0)**

- Requires **informational** notifications, not only actionable ones.

**Auth & RBAC (Phase 0)**

- The general data-visibility check remains undefined (`adr/0002` decision 5). Salary is
  now answered by decision 5 here; everything else is not.
- `§6` of the Employee Master spec scopes HR reads to their own company. With HR and
  Assistant Director employed at group level, that needs revisiting — but as an Auth & RBAC
  decision, not an Employee Master one.

---

## References

- `adr/0001` decision 2 — superseded by decision 1 here
- `adr/0001` decisions 3, 4, 6, 7 — unchanged, re-expressed in pivot terms
- `adr/0001` follow-up item 10 — `hr_scope`, withdrawn by decision 5
- `adr/0002` decision 4 — HOD same-company authority, unchanged
- `adr/0002` decision 5 — cross-company approval; `hr_scope` portion withdrawn
- `docs/schema.md` — `employees`, `employee_roles`, `employee_job_functions`,
  `job_functions`, `sequences`, `employee_status_history`
- `docs/conventions.md` §2–3 — cascade categories and the event-table scope carve-out
- `docs/business-rules.md` § Approval Hierarchy, § Company Group Reference
- `docs/modules/employee-master.spec.md` — BR-9, BR-10, BR-11, BR-13, BR-14, §3, §5.3,
  §6, §8
- `CLAUDE.md` §5, §10, §11
