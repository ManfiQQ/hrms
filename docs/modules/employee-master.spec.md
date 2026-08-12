# Module Spec — Employee Master

- **Phase:** 1 — Employee & Org
- **Status:** Draft — awaiting approval. **No code until this is approved** (`CLAUDE.md`
  Principle #1).
- **Branch:** `feat/employee-master`
- **Depends on:** `companies`, `branches`, `departments`, `positions`, `users`,
  `sequences` (Phase 0); `adr/0001` (taxonomy — partly superseded), `adr/0002` (shared org
  structure — resolved), `adr/0003` (multi-role authority — resolved), `adr/0004` (account
  access and read scope — **resolves §6**)
- **Date:** 2026-08-07 — approval-scope rules corrected 2026-08-08 (BR-10, BR-14);
  realigned to `adr/0003` on 2026-08-10 (multi-role authority pivot, job functions,
  `employee_no` lifecycle); §6 read scope resolved by `adr/0004` on 2026-08-11 — the ⚠
  known-wrong `own company only` rows are corrected and §6 is implementable

---

## 1. Purpose

The single authoritative record of a person's employment with the group. Every later
module reads from it: Attendance matches punches to it via `fingerprint_id`, Leave
computes entitlement from `join_date` and `staff_status`, Payroll reads salary and
statutory config against it, Approval routes on `employee_roles` and department, Org
Structure renders from `level` and the reporting FKs.

It is built first in Phase 1 because nothing downstream can be built without it.

## 2. Scope

**In scope**

- Employee CRUD (create, view, edit, soft-delete/archive), tenant-scoped
- Employment detail: company/branch/department/position, type, status, dates
- Reporting lines: direct supervisor and manager
- Work schedule: structured times, working days, off days, attendance type
- Child records: family members, education history, previous employment history,
  documents
- **Authority roles** — granting and revoking `employee_roles` rows, per company, subject
  to the grant restrictions in BR-16
- **Job functions** — assigning `employee_job_functions`; the `job_functions` vocabulary
  itself is Master-Admin-managed (BR-15)
- Status history as an append-only ledger, written automatically on every relevant change
- List/search/filter, and employee detail view
- Import of existing employee records from the legacy system (the one data set
  `CLAUDE.md` §1 says carries over)

**Out of scope — explicitly**

- Leave balances and accrual (Phase 2 — Leave)
- Attendance punch matching (Phase 2 — Attendance; this module only *provides*
  `fingerprint_id`)
- Salary figures, EPF/SOCSO computation (Phase 2 — Payroll)
- Org chart rendering (separate Phase 1 module — Org Structure; this module provides the
  reporting FKs it draws)
- Self-service profile editing by the employee themselves (later; Phase 1 is HR-managed
  entry only)
- Letter/document *generation* (Phase 0 Document Generator; this module only stores
  uploaded files)

## 3. Data Model

Tables are specified in `docs/schema.md` and are **not duplicated here** — that file is
the single source of truth for columns. This section records only the decisions a
migration author needs beyond the column list.

- `employees` — the master record. **`core_role` is not a column on it** and never was
  built: `adr/0003` decision 1 supersedes `adr/0001` decision 2 before any migration was
  written.
- `employee_family_members`, `employee_education_history`,
  `employee_employment_history`, `employee_documents` — child records, each with
  `company_id` at creation per `conventions.md` §3.
- `employee_status_history` — append-only ledger; see §5.3.
- `employee_roles` — **the authority pivot**. Authority is a triple: *who*, *at which
  company*, *what role*. Revocation is `revoked_date`; rows are never deleted and the
  table has **no soft deletes** (`conventions.md` §3 exceptions).
- `employee_job_functions` — what work a person does, per company; distinct from what
  they may approve.
- `job_functions` — reference table for the above (`BDO`, `Admin`, `Media`, `Live Host`,
  `Operation Crew`, growing). Master-Admin-managed vocabulary, soft-deletable.

**Dependency — `sequences` (Phase 0 Core Engine, not this module).** Employee Master does
not create that table, but it is the **only** permitted source of `employee_no`: the row
for `key = 'employee_no'` is taken with `lockForUpdate()` inside the same transaction as
the employee insert. `MAX() + 1` is not acceptable (BR-13). If `sequences` does not exist
yet when this module is built, it must be created by the Core Engine migration, not
improvised here.

**Two different things are called `company_id` in this module — do not conflate them.**
On `employees` and the four descriptive child tables it is a **tenant marker**. On
`employee_roles` and `employee_job_functions` it is a **real company reference** — "in
which company does this apply" — and the ordinary tenant global scope must not be applied
to it unthinkingly. On `employee_status_history` it is a **frozen event fact**. The three
behave differently on a company transfer; see BR-17 and `conventions.md` §2.

**Migration rules for this module**

- `company_id` on every table in the migration that **creates** it. Never retrofitted
  (`CLAUDE.md` Principle #4). `employees.company_id` is **NOT NULL**.
- Tenant global scope applied on every model, bypassable only from an explicit Master
  Admin context.
- **Exception — `branches` and `departments`**: `company_id` is **nullable** there
  (`NULL` = shared across companies), and their global scope must resolve to
  `company_id IS NULL OR company_id = :current_company` so shared rows are **included**,
  not filtered out. See `adr/0002` and `conventions.md` §2 carve-out. A plain
  `where company_id = :current` on these tables is a silent data-loss bug, not an error.
- **Nine migrations** will be generated in this module — one per table created here.
  Previously six; `adr/0003` adds three. In FK-safe creation order:

  1. `employees`
  2. `employee_family_members`
  3. `employee_education_history`
  4. `employee_employment_history`
  5. `employee_documents`
  6. `employee_status_history`
  7. `job_functions` — before `employee_job_functions`, which FKs to it
  8. `employee_job_functions`
  9. `employee_roles`

  `sequences` is **not** in this count — it is a Phase 0 Core Engine table (see the
  dependency note above). Verify with `ls database/migrations | sort` that no two share a
  timestamp before committing (`conventions.md` §6; the legacy system shipped three
  colliding timestamps, and nine generated in one session is exactly the condition that
  produced them).
- **`employee_roles` carries no soft deletes and `employee_status_history` carries no
  `updated_at` / `updated_by` / soft deletes.** These are deliberate exceptions to
  `conventions.md` §3, documented there — the migration author must not add them back for
  consistency's sake.
- `schema.md` updated in the **same commit** as each migration (`CLAUDE.md` Principle #5).

**Indexes**

- `employees`: unique on `employee_no` — **group-wide, not composite with `company_id`**
  (§10 decision 1); unique on `fingerprint_id` (nullable — unique index must permit
  multiple nulls); composite index on `(company_id, staff_status)` for the default list
  query; index on `department_id`, `direct_supervisor_id`, `manager_id`.
- `branches`, `departments`: index on `company_id` — the column is nullable, so the index
  must serve `IS NULL` lookups as well as equality.
- Child tables: index on `(company_id, employee_id)`.
- `employee_roles`: **`(employee_id, company_id)`** — "what authority does this person
  hold at this company" — and **`(company_id, role)`** — "who is `ACCOUNT` at AIM", the
  counterpart lookup the approval engine and the salary permission check both run.
  Both are required (`adr/0003` decision 1). Every authority check is now a **query, not a
  field read**, so the employee list will N+1 unless `employee_roles` is eager-loaded
  deliberately.
- `employee_job_functions`: `(employee_id, company_id)`, and `job_function_id` for the
  reverse lookup "who performs this function" — needed before a `job_functions` row can be
  safely deactivated (BR-15).
- `employees`: also index `previous_employee_id` — the rejoiner link is followed from the
  new record back to the old (BR-13).

**Enums**

- **`employee_roles.role`** — **six** values, authority only:

  ```
  ASSISTANT_DIRECTOR, HR, ACCOUNT, HOD, MANAGER, SUPERVISOR
  ```

  This is **not** the same six as `adr/0001` decision 2. `ACCOUNT` is added; **`STAFF` is
  removed and must not be re-added.** With a pivot, an ordinary staff member is someone
  with **no row in this table at all**. Defining a value for the *absence* of authority
  would create a second way to express one state, and the two would eventually disagree —
  the same reasoning that keeps `MASTER_ADMIN` out. `MASTER_ADMIN` and `DIRECTOR` remain
  absent (`adr/0001` decisions 2 and 7, both still standing).

  The list stays a **fixed enum**, not a reference table, precisely because it must not
  change casually: adding an authority role changes the approval chain and requires an
  ADR, not a UI form.

- **`level`** — `STAFF, SUPERVISOR, MANAGER, HOD`, **four** values, unchanged and
  **display only**. It never drives an authorization or routing decision (BR-9).

- **`employee_status_history.change_type`** — `STAFF_STATUS, POSITION, DEPARTMENT, LEVEL`,
  four values. `CORE_ROLE` is deliberately **not** among them; see §5.3.

- **Job functions are not an enum.** `job_functions` is a reference table because the list
  is not stable — it grows as the factory, studio, galleria and restaurant are mapped
  (BR-15).

## 4. Business Rules

Sourced from `docs/business-rules.md`. Anything numeric here is a
`policy_configurations` lookup, never a literal in code (`conventions.md` §5).

**BR-1 — Employment type.** `FULL-TIME, PART-TIME, CONTRACT, INTERN, FREELANCE`.
`FREELANCE` is the handbook's probation-equivalent: 3 months, extendable or shortenable
at company discretion, no offer letter until confirmed permanent.

**BR-2 — Status lifecycle.** `PROBATION → ACTIVE/CONFIRMED → SUSPENDED → RESIGNED/
TERMINATED`. Permitted transitions are enforced in the service layer, not the UI.
`RESIGNED` and `TERMINATED` are terminal — reinstatement is a new employee record
referencing the old one, not a status flip back.

**BR-3 — Confirmation dates.** `probation_end_date` defaults to `join_date` + the
configured probation period (3 months for `FREELANCE` per BR-1). `confirmation_date`
must be ≥ `probation_end_date`. Handbook expects confirmation 6–12 months after
probation ends; this is **advisory**, surfaced as a warning, not a hard validation
block — the company retains discretion.

**BR-4 — Retirement age is 75** (male and female). Surface a warning on an employee
approaching it. Do not auto-terminate.

**BR-5 — Structured schedule, never free text.** `work_start_time`, `work_end_time`,
`ot_after_time` are `TIME`. `working_days` and `offday` are JSON arrays of
`MON|TUE|WED|THU|FRI|SAT|SUN`. This is the direct fix for the legacy
`"ISNIN - SABTU"` / `"9.00 AM - 5.00 PM"` strings (`conventions.md` §4). Handbook
default: Mon–Sat 9:00–17:00, Sun off — seeded from `policy_configurations`, overridable
per employee.

**BR-6 — `attendance_type`.** `FIXED` = late after the configured start time;
`FLEXIBLE` = OT applied manually. Drives Phase 2 Attendance; stored here.

**BR-7 — `hours_enabled`.** Whether Saturday accumulated-hours banking applies to this
employee. Stored here, consumed by Phase 2.

**BR-8 — Reporting lines.** `direct_supervisor_id` and `manager_id` are both self-FKs to
`employees`, both nullable (two-tier reporting confirmed from the legacy Staff Master
template). Neither may equal the employee's own id, and the supervisor chain must not
form a cycle — validated on save.

**BR-9 — Authority vs display, and authority is per company.** Authority comes from
`employee_roles`, never from `employees`. `level` drives org display only. They may
legitimately differ for the same person, and **no code may read `level` for an
authorization decision** (`adr/0001` decision 1, unchanged).

What changed is where authority lives. A person holds **several roles, and the roles
differ per company** — the canonical case is one employee who is `ACCOUNT` at AHS and
`MANAGER` + `ACCOUNT` at AIM. So:

- **"What authority does this employee have?" has no answer until a company is named.**
  Any function answering it must take a `company_id` argument. A signature that does not
  is a bug, not a convenience.
- **Ordinary staff hold no row.** Absence of an `employee_roles` row *is* the staff state;
  there is no `STAFF` value to look for.
- **`WHERE revoked_date IS NULL` is load-bearing on every authority read.** Omitting it
  returns revoked authority as current — a **silent security failure, not an error**. It
  belongs in a default model scope on the `EmployeeRole` model, not repeated at each call
  site.
- **No `is_enabled` flag exists on the pivot and none may be added.** Two conditions per
  check means the check that forgets the second is a hole. Revocation is the single
  mechanism.

**`ACCOUNT` is financial authority, not a management tier.** It is **excluded from
approval routing entirely** — it does not rank against `HOD`, `MANAGER` or anything else,
because the other five answer "who reports to whom" while `ACCOUNT` answers "who may touch
funds". Ranking them on one scale would give a newly hired Account a shorter approval path
than a ten-year Supervisor leading eight people. What `ACCOUNT` *does* grant is **salary
visibility at the company where it is held, and it is the only role that does — no `HR`
sees salary, however many HR staff exist** (`adr/0003` decisions 4 and 5). Employee Master
holds no salary data (§10 decision 3), so this module **stores** the role and implements
no salary check; Payroll consumes it.

**BR-10 — HOD assignment is per department, optional, and same-company in authority.** A
department may or may not have an assigned HOD, and this varies between departments
within one company. Employee Master stores the `HOD` role as an **`employee_roles` row
carrying its own `company_id`**; the *department → HOD* resolution consumed by approval
routing must be queryable dynamically (`adr/0001` decision 3).

The pivot makes this resolution **more natural, not less**. Under the old single-enum
design the HOD's company had to be inferred from `employees.company_id` — a second hop,
and one easy to forget. The role row now states the company directly, so
*(department, company) → HOD* is a join on `employee_roles.company_id` with
`role = 'HOD' AND revoked_date IS NULL`, and the same-company rule below is expressed by
the query rather than bolted on after it.

An HOD's approval authority is **strictly same-company** — it covers only employees
sharing the HOD's own `employees.company_id`, **even inside a shared department or
branch** (`adr/0002` decision 4). Consequences for this module:

- The resolution Employee Master must support is **(department, company) → HOD**, not
  department → HOD. A shared department may legitimately hold **more than one** `HOD`
  employee, one per company represented in it — the data model must not assume at most
  one HOD per department, and no validation may reject the second.
- The comparison remains `hodEmployee.company_id === subject.company_id`, read from
  **`employees.company_id`** on both sides — the payroll employer. Do **not** substitute
  `employee_roles.company_id` for the subject: the role row says where authority applies,
  the employee row says who employs the person, and BR-10 is a rule about employment
  (`adr/0002` decision 4, `adr/0003` decision 6).
- Where a department's only HOD belongs to a different company than an employee in it,
  that employee simply has no HOD stage and falls back to the standard chain. This is a
  correct configuration, not an error state to flag.

**BR-14 — Cross-company approval is `HR` / `ASSISTANT_DIRECTOR` only, and grants no
visibility.** These two `employee_roles.role` values are the only ones whose approval
authority crosses `company_id`; `SUPERVISOR`, `MANAGER` and `HOD` are all confined to their
own company, an employee with **no `employee_roles` row holds no approval authority at
all**, and `ACCOUNT` is not a routing tier in either direction (BR-9). Approving a
cross-company request **does not** confer read access to that
employee's sensitive data — a separate visibility check governs that, and **holding an
approval stage is never an input to it** (`adr/0002` decision 5).

**The visibility check is now defined, and it is not this rule.** `adr/0004` decision 1
derives read scope from the **employer's position in `companies.parent_company_id`** —
independently of every role and every approval stage. §6.1 carries it. The two axes produce
visibly different answers for the same person: an `HR` employed by a subsidiary approves
across the whole group (this rule) while reading **one company only** (§6.1). If an
implementation ever makes those two agree by construction, it has merged the axes and is
wrong.

**Salary is separate again and was answered first:** only the `ACCOUNT` role may read salary
data, and no `HR` may, at any scope (`adr/0003` decision 5). Group-level employment does not
change it — Employee Master holds no salary data regardless (§10 decision 3). The remaining
categories are answered by `adr/0004`: documents, family, education, employment history and
status history in decisions 8–9 (§6.2, §6.3); disciplinary records in decision 10 and leave
history in decision 11, both belonging to modules not yet built.

**BR-12 — Org assignment is independent of employing company.** `employees.company_id`
(NOT NULL) is the payroll/legal employer. `branch_id` and `department_id` may point at
shared org units (`company_id IS NULL`) or at units belonging to a different company.
**Validation must not require them to match** — an employee of TURSENIA in the shared
Logistics branch is a correct record. Sensitive data stays scoped to
`employees.company_id` regardless of org placement. See `adr/0002` decisions 2–3.

**One person, one employee record, one `employee_no` — however many companies they hold
roles in** (`adr/0003` decision 6). `employees.company_id` is narrowed to a single
meaning: *who is the legal and payroll employer*. Where the person has authority, and
what kind, is `employee_roles`. Two questions, two places, never mixed.

**No `secondary_company_id` column exists and none may be added.** The requirement it
would serve — seeing at a glance that someone also works at another company — is met by
querying the pivot and rendering it:

> **Employer (payroll):** AHS
>
> **Also serving at:** AHS — BDO, Account · AIM — Manager, Account, Admin

A stored column would duplicate a fact the pivot already holds, drift the moment a role is
revoked and the column is not updated, be unable to represent a third company, and carry
*less* information than the pivot it copies.

**Not modeled: a person genuinely paid by two entities** — two payrolls, two EPF
contributions. Confirmed with the client as not occurring. If it ever does, this model
cannot express it and needs revisiting rather than patching.

**BR-13 — `employee_no` is group-wide, generated from a locked sequence, and never
reissued.** Format `AHS-0001`, sequential and zero-padded, **always the `AHS` prefix
regardless of employing subsidiary**. A single group-wide sequence — a per-company counter
would collide against the group-wide unique index. See §10 decision 1.

**Generation (`adr/0003` decision 9).** The `sequences` row for `key = 'employee_no'` is
taken with **`lockForUpdate()` inside the same transaction as the employee insert**.
`MAX() + 1` is **not acceptable**: it collides whenever two requests read the current
maximum before either writes — a double-clicked Save button, two open tabs, a legacy
import running alongside manual entry, a seeder. The client's operating rule that **one HR
does all registration does not remove this**: that rule prevents duplicate *people*, not
duplicate *numbers*, and the two protections are complementary rather than alternatives.
Deriving the number from `employees.id` is **rejected** — it leaves visible gaps on
rollback, couples the number to a primary key, and makes the Master Admin correction below
impossible, since a derived value cannot be edited.

**Lifecycle — the dividing line is continuity of employment, not which entity pays:**

| Event | `employee_no` | Record |
|---|---|---|
| **Transfer** between group entities | **Stays with the person** | Same record; `employees.company_id` changes in place |
| **Resignation / termination** | **Retired permanently**, never reissued | Terminal (BR-2) |
| **Rejoining** | **New number** | **New record**, linked via `previous_employee_id` |

**`employees.previous_employee_id`** — self-FK, nullable — is the thread from a rejoiner's
new record back to the old one. BR-2 already required reinstatement to reference the prior
record, but no column existed for it, leaving the rule unimplementable. **Employee Master
stores the link only.** Whether prior service counts toward leave entitlement is a Leave
spec decision — and one that cannot be made at all unless the link is captured now.

**The sequence never rewinds.** Master Admin may edit an `employee_no` for a genuine
correction (a bad legacy import value), audited — but **a number vacated by an edit is
burned, not returned to the pool**, because reissuing it would point previously printed
letters and payslips at the wrong person.

**BR-11 — Master Admin has no employee record.** No Employee row may be created for a
Master Admin account. This is **enforced structurally, not by assertion** — but the
structure that enforces it has changed with `adr/0003`, and it is now enforced **twice
over**:

1. **A Master Admin has no employee record**, so it can hold **no `employee_roles` row at
   all.** Authority now requires a pivot row keyed to an `employee_id`; with no employee,
   there is nothing to key to. This is the primary guarantee, and it is stronger than the
   old one because it does not depend on an enum's contents.
2. **The pivot enum has no `MASTER_ADMIN` value either**, so even a mistakenly created
   employee row could not express Master Admin authority.

The old formulation — "`employees.core_role` has no `MASTER_ADMIN` value" — is **obsolete
because the column itself is gone** (`adr/0003` decision 1), not because the rule
weakened. Master Admin remains identified solely at the `users` level, by
**`system_access = FULL` with a null `employee_id`** (`adr/0004` decision 2 — this replaced
the `is_master_admin` column named here previously, which was withdrawn on 2026-08-11 as a
second way to state a fact `system_access` already states).

The migration must therefore define `employee_roles.role` with exactly the six values in
§3 — adding `MASTER_ADMIN` "for completeness" would reintroduce the hole this closes.

**BR-15 — Job function is what you do; authority is what you may approve.** They are
separate structures because they answer separate questions, and merging them would force
the approval engine to answer questions that should not exist — *"can a Live Host approve
a leave request?"* (`adr/0003` decision 2).

- `job_functions` is a **reference table, not an enum**, because the list is not stable:
  it grows as the factory, studio, galleria and restaurant are mapped. Starting set:
  `BDO`, `Admin`, `Media`, `Live Host`, `Operation Crew`.
- **Master Admin creates and deactivates the types; HR only assigns them.** Keeping the
  vocabulary under one account is what stops it drifting into three spellings of one thing
  (`CLAUDE.md` §5).
- **Removal is soft delete only.** Hard-deleting a function employees currently hold would
  orphan their rows and break history. A "deleted" function is deactivated: it disappears
  from the assignment picker, existing assignments stay intact, and it can be reactivated
  if the workplace reopens.
- **Two things are deliberately not job functions.** `Intern` is an `employment_type`
  (BR-1) and `Logistic` is a **branch** (`adr/0002`). An intern doing media work has job
  function `Media` and `employment_type = INTERN` — two facts, two fields. Neither may be
  seeded into `job_functions`.

**BR-16 — Sensitive roles are restricted; only Master Admin may grant them.** Without
this, "only `ACCOUNT` sees salary" (BR-9) is unenforceable: an HR who can grant roles
freely grants themselves `ACCOUNT` and reads every salary in the group by lunchtime. The
rule would not be *violated* — it would be **walked around through the front door**, and
it would look like ordinary HR activity in the audit log.

| Role | Restricted | Changeable | Reason |
|---|---|---|---|
| `ACCOUNT` | **Yes — hardcoded** | No | The only door to salary data |
| `HR` | **Yes — hardcoded** | No | Can create further HR; self-propagating |
| `ASSISTANT_DIRECTOR` | **Yes — hardcoded** | No | Top of the approval chain |
| `HOD` | **Yes** | By Master Admin | Skips approval stages, see below |
| `MANAGER` | No | By Master Admin | Routine operational change |
| `SUPERVISOR` | No | By Master Admin | Base operational tier |

**The top three are hardcoded and cannot be configured away at all** — where no legitimate
case exists, no control should exist. **`HOD` is restricted because it is structurally
different, not merely senior**: granting it does not add a tier, it **bypasses two** (an
HOD may skip the Manager/Supervisor stage, and their own requests route directly to HR).
**`MANAGER` is deliberately not restricted** — a routine appointment that changes often,
spanning one stage and no sensitive data; routing every Manager appointment through Master
Admin would pull that account into daily HR work. Only Master Admin may change any
`is_restricted` value, and **every such change is written to `audit_logs`**
(`adr/0003` decision 3).

**BR-17 — Company transfer: three cascade categories, and the wrong one corrupts data.**
Transfers between group entities **do occur, rarely**. `employees.company_id` changes **in
place**; the record and `employee_no` stay with the person (BR-13). Child tables then split
three ways by what `company_id` *means* on each (`adr/0003` decision 7,
`conventions.md` §2):

| Category | `company_id` means | On transfer | Tables in this module |
|---|---|---|---|
| **Descriptive** | Tenant marker; the row describes the *person* | **Cascade** | `employee_family_members`, `employee_education_history`, `employee_employment_history`, `employee_documents` |
| **Event** | The employer *at the time it happened* | **Frozen forever** | `employee_status_history` |
| **Company-reference** | A real reference to a company, unrelated to employment | **Untouched** | `employee_roles`, `employee_job_functions` |

Freezing event rows is what keeps history attributable to the entity that actually
employed the person; rewriting them would be falsification, not an update. Company-
reference rows are left alone for a different reason: a Manager role at AIM is still a
Manager role at AIM after the person's payroll moves, so cascading it would corrupt the
data outright.

**⚠ Consequence this module must implement.** Frozen rows fall **outside the new
employer's tenant scope**, so a transferred employee's Status History tab would appear to
**begin on the transfer date, with no error raised**. Therefore: **`employee_status_history`
accessed through the employee relationship releases the tenant scope** — permission was
already decided at the employee level, and if the user may read this employee they may
read this employee's history. Queried **directly** for reporting, tenant scope applies in
full. Test both directions (§8).

## 5. Behaviour

### 5.1 Create / Edit

- All validation in FormRequests (`EmployeeStoreRequest`, `EmployeeUpdateRequest`) —
  never inline in a controller (`conventions.md` §1).
- All business logic in `App\Services\EmployeeService` /
  `App\Actions\Employee\*` — controllers stay thin.
- `company_id` is taken from the authenticated tenant context, **never** accepted from
  request input.
- `created_by` / `updated_by` populated automatically.

### 5.2 Delete

Soft delete only. An employee with dependent records is archived, never hard-deleted.
Hard deletion is not exposed in the UI at all.

### 5.3 Status history is automatic

Any change to `staff_status`, `position_id`, `department_id`, or `level` writes a new
`employee_status_history` row inside the same transaction as the update — the caller
cannot forget to write it, because the service does it, not the controller. Rows are
never edited or deleted; a correction is a new row.

**It is not mirrored to `audit_logs`, and a service that writes both is wrong.**

> **⚠ Corrected 2026-08-12.** This paragraph previously ended *"Also mirrored to
> `audit_logs` (Phase 0)."* That contradicted `adr/0003` decision 8, which rejects
> duplication on the ground that **two records of one fact will eventually disagree** — the
> same reasoning that keeps `CORE_ROLE` out of the `change_type` set below, and that
> rejected `secondary_company_id`, `is_enabled`, `primary_role` and `hr_scope`. A mirror is
> that mistake under another name, and here the stale copy would sit **inside the record
> whose entire value is being trustworthy**.
>
> **The audit report reads both tables and merges them on display**
> (`audit-trail.spec.md` BR-AT5, §5.5) — the same read-side merge §7 already performs for
> `employee_status_history` and `employee_roles`, carrying the same warning: the merge
> exists **so the data need not be stored twice**, and must not tempt a writer into
> recording the event in both places to make a query simpler.

**Those four are the complete `change_type` set. `CORE_ROLE` is deliberately not among
them** (`adr/0003` decision 8). Role grants and revocations are **not** written here:
`employee_roles` already records every one of them with dates, actors and reasons, and
writing the same event to a second table would create two records of one fact that can
disagree. A role change must therefore write **only** the pivot row — a service that also
appends a status-history row is wrong.

**`old_label` / `new_label` are a snapshot of the display text at the time.** Storing only
`department_id = 7` would need a join to render, and that join shows the department's name
**today**, not its name **then** — renaming a department would retroactively rewrite
history, and a ledger that changes retroactively is not a ledger. The labels are redundant
for enum types (`CONFIRMED` / `CONFIRMED`); that is accepted, because one uniform row
shape costs a few bytes and avoids per-type branching in every reader.

### 5.4 List & search

- Default list is tenant-scoped and excludes soft-deleted rows.
- Search on `employee_no`, `full_name`, `nickname`, `email`, `phone_no`.
- Filters: company, branch, department, position, `staff_status`, `employment_type`,
  `level`.
- Paginated. Query lives in a model scope or repository, not inline in the controller
  (`conventions.md` §1).

### 5.5 Legacy import

One-off, idempotent, re-runnable command. Matches on `employee_no`. Writes an import
report of unmatched/ambiguous rows rather than guessing. Company names normalized against
the canonical table in `CLAUDE.md` §5 — the legacy data contains three spellings of
ES SOFEEYA ENTERPRISE and the importer must reject unknown spellings loudly, not silently
create a new company.

### 5.6 Role and job-function assignment

- **Granting** a role inserts an `employee_roles` row with `company_id`, `role`,
  `effective_date` and `assigned_by`. **`effective_date` is distinct from `created_at`** —
  a promotion is typically effective before HR gets to enter it, and the ledger records
  both the date it applies from and the date it was typed. Both must be capturable in the
  UI; `effective_date` must not silently default to today without HR seeing it.
- **Revoking** sets `revoked_date`, `revoked_by` and `revoke_reason`. It **never** deletes
  the row and never sets a flag.
- **Re-granting** later inserts a **new row**, preserving the full cycle — held Jan–Aug,
  revoked Aug, re-granted November — which a boolean toggle cannot express. The service
  must not "reactivate" the revoked row by clearing `revoked_date`.
- **Grant restrictions (BR-16) are enforced in the service layer and at the policy layer**,
  not in the Blade template. Hiding a restricted role from the picker is presentation; the
  authorization check must reject it on submit regardless of what the form offered.
- **Job-function assignment** writes `employee_job_functions`. HR may assign freely; only
  Master Admin may create or deactivate a `job_functions` row (BR-15).

### 5.7 Company transfer

A transfer updates `employees.company_id` **in place** and, in the same transaction,
cascades `company_id` to the four **descriptive** child tables only. It must **not** touch
`employee_status_history`, `employee_roles`, or `employee_job_functions` (BR-17). The
transfer is a distinct action (`App\Actions\Employee\TransferCompany`), not an ordinary
field edit on the update form — an edit path that lets `company_id` be changed like any
other column will miss the cascade entirely.

Writing a `STAFF_STATUS`-style history row for the transfer itself is **not** in the
current `change_type` set; if the client wants transfers on the timeline, that is a new
enum value and an ADR, not an improvisation here.

**Who may initiate a transfer — `HR` or Master Admin, either one, directly.** HR is the
ordinary actor: a transfer is an HR operation, not an administrative repair. Master Admin
exists on this action as a **support path** for when HR is unavailable — on leave, not
responding, or any situation where an employee would otherwise be left untransferred.

**This is not an approval hierarchy.** Neither party approves the other, and Master Admin
is not an escalation above HR. Both may execute the transfer outright. It is the same
pattern as HR's may-act-at-any-time position in approval routing (`adr/0003` § Confirmed
but not yet specced) — a second capable actor so the work never stalls on one person's
availability, not a second gate the work must pass through.

**Every transfer is written to `audit_logs` with the identity of whoever performed it.**
This is not routine change-logging. A transfer changes **which legal entity is responsible
for that employee's EPF, SOCSO and EA Form** — a statutory reassignment between two
companies. When a filing is later queried, the record must show which entity was
responsible from which date and **who made that so**. Since either of two parties may have
acted, the actor identity is the only thing distinguishing an ordinary HR transfer from a
Master Admin support intervention after the fact.

The audit entry is written **inside the same transaction** as the transfer and its
cascade (BR-17), so a transfer can never land without its audit record.

## 6. Permissions

Read from `employee_roles` only — **filtered to `revoked_date IS NULL`, and evaluated
against the company in question**, never from `employees.level` and never from a field on
`employees`. A person may qualify for a row below at one company and not at another; that
is the normal case, not an edge case.

| Action | Who |
|---|---|
| View own record | any employee |
| View department employees | `SUPERVISOR`, `MANAGER`, `HOD` — own department **and own `company_id`** |
| View all in **read scope** | `HR`, `ASSISTANT_DIRECTOR` — scope **derived from the employer's hierarchy position**, see below |
| Create / edit / archive | `HR`, `ASSISTANT_DIRECTOR` — within their read scope, **`phone_no` excepted** (§6.4) |
| Grant / revoke `MANAGER`, `SUPERVISOR` | `HR` — within their read scope |
| Grant / revoke `ACCOUNT`, `HR`, `ASSISTANT_DIRECTOR`, `HOD` | **Master Admin only** (BR-16) |
| Create / deactivate `job_functions` types | **Master Admin only** (BR-15) |
| Assign `job_functions` to an employee | `HR` — within their read scope |
| Edit `employee_no` | **Master Admin only**, audited (BR-13) |
| Transfer employee between companies | `HR` **or** Master Admin — either, directly; always audited with the actor's identity (§5.7) |
| Cross-tenant view | `system_access = FULL` (Master Admin) — explicit, audited |

### 6.1 Read scope — derived, never configured

**An account's read scope comes from where its employer sits in
`companies.parent_company_id`** (`adr/0004` decision 1), not from the role it holds:

| Employed by | Reads |
|---|---|
| **AHS** — the parent | The **whole group** |
| A **subsidiary** | That **subsidiary only** |

This applies uniformly. `HR` and `ASSISTANT_DIRECTOR` read across the group **because they
are employed by AHS**, not because of their role — the client has confirmed they sit under
AHS/HQ and administer every entity. An HR hired by a single subsidiary would read that
subsidiary only, with no code change, and a seventh entity added under AHS becomes visible
to group-level staff automatically.

**⚠ Scope and data type are separate axes, and conflating them is what made the previous
version of this table wrong.** Scope answers *which companies*; role answers *what data
within them*. `HR` and `ACCOUNT` employed at AHS have the **same** scope — the whole group
— and different data rights: only `ACCOUNT` reads salary (BR-9), and Employee Master holds
no salary anyway.

**There is no manual scope override, and none may be added.** Scope is derived, never
stored per account — the same reasoning that rejected `secondary_company_id` (`adr/0003`
decision 6). Where a narrower scope is wanted, employ the person at the subsidiary.

**A permission function without a `company_id` argument is a bug** (`adr/0003` decision 1),
and every read of `employee_roles` filters `revoked_date IS NULL`. Scope narrows *which*
companies may be named; it never removes the need to name one.

### 6.2 Tab-level read access on the employee detail view

Access differs **per tab, not per record** (`adr/0004` decision 8). The detail view's tabs
(§7) resolve as:

| Tab | Supervisor / Manager / HOD | HR / Asst Director / Account | Master Admin / Director | The employee |
|---|---|---|---|---|
| Employment | **Yes** | Yes | Yes | Own |
| Personal | **Yes** | Yes | Yes | Own |
| Family | No | Yes | Yes | Own |
| Education | No | Yes | Yes | Own |
| Employment History | No | Yes | Yes | Own |
| Documents | No | Yes | Yes | Own — see §6.3 |
| Status History | No | Yes | Yes | Own |

Supervisors, Managers and HODs read **within their own department and their own company** —
the existing double bound (BR-10, `adr/0002` decision 4) is unchanged by any of this.

**Why Employment and Personal, and nothing else.** A supervisor needs to know *who reports
to me* and *how do I reach them*. They do not need a copy of someone's IC, their spouse's
identity card number, or where they went to school — none of it bears on supervision.
Restricting them to Employment alone was rejected as too tight: a supervisor who cannot
find a phone number in the system will find it on WhatsApp instead, and the organisation
loses the control entirely.

**Emergency contact is the deliberate exception.** Name and phone number only, surfaced on
the **Employment** tab rather than behind Family — `employee_family_members` already carries
`is_emergency_contact` (§3). If there is an accident at work the supervisor is likely the
first person present; they need that number without being handed the whole family record.

### 6.3 Documents — the employee may retrieve their own

An employee may **view and download their own documents** for six of the seven types
(`adr/0004` decision 9):

```
IC · PASSPORT · EDUCATION_CERTIFICATE
OFFER_LETTER · CONFIRMATION_LETTER · RESIGNATION_LETTER
```

These are already theirs in any real sense — they submitted the identity documents, and the
letters are addressed to them. Withholding the scans protects nothing and turns every
routine request (a confirmation letter for a bank loan) into an HR errand.

**`OTHER` is not visible to the employee.** It is the escape hatch for documents that do not
fit the fixed types (§10 decision 4), which makes it the natural place for internal notes
and investigation material. Hiding it gives it a defined purpose rather than leaving it an
undifferentiated bucket.

### 6.4 `phone_no` is read-only on the employee form — for everyone

**The employee form displays `phone_no` and never edits it. This holds for every role,
`HR` and Master Admin included.** There is no edit affordance on this form, greyed out or
otherwise.

**Changing it is an account operation, not a profile edit.** It is done from the account
management screen specified in `auth-rbac.spec.md` §7 — the same place as password reset,
unlock, and QR regeneration — where `HR` and Master Admin are the only ones who may do it
(`auth-rbac.spec.md` §6, `adr/0004` decision 6).

**`ASSISTANT_DIRECTOR` keeps full create / edit / archive rights** on the employee record.
The exception is not a demotion: `phone_no` is not a profile field, so it is not theirs to
edit here — and it is not `HR`'s to edit here either.

**Why the field leaves the form entirely rather than carrying a role check.** `phone_no`
serves two masters: it is **profile data** (how you reach a person) and it is an **account
credential** (the login username, `adr/0004` decision 6). Putting it on the employee form
treats it as only the first. A greyed-out field invites the question "why can't I edit
this?" every time someone opens the form; removing it makes the boundary clean — **the
employee form is for employee data, the account screen is for account credentials.**

It also keeps account operations coherent. An `ASSISTANT_DIRECTOR` who could change
someone's **username** but could not reset their **password** is a combination that makes
sense from no direction at all.

**Approval authority is not on this table, and that is deliberate.** `HR` and
`ASSISTANT_DIRECTOR` may *approve* across companies (BR-14); that grants them **no** read
access here. Their group-wide reads come from §6.1 — **being employed by AHS** — and an
`HR` employed by a subsidiary approves across the group while reading one company only.
The two axes never meet: **holding an approval stage is never an input to a visibility
check** (`adr/0004` decision 1, `adr/0002` decision 5). An HOD's read access is bounded
twice over: own department **and** own company, since a shared department can contain other
companies' staff (BR-10).

**`ACCOUNT` grants nothing in this module.** It is the only role that may read salary
(BR-9), but Employee Master holds no salary data (§10 decision 3), so `ACCOUNT` confers no
Employee Master permission whatsoever — it appears in no row above except as a role that
may be *granted*. Do not anticipate the Payroll check here.

**HR cannot grant itself upward.** The split in the table is the whole enforcement
mechanism for BR-9: HR may appoint Managers and Supervisors, but `ACCOUNT`, `HR`,
`ASSISTANT_DIRECTOR` and `HOD` are Master Admin's alone. An implementation that lets HR
grant any role, with the restriction enforced only by hiding options in the UI, defeats
BR-16 entirely.

**✅ The `own company only` scope is resolved and corrected — 2026-08-11.** This section
previously carried a ⚠ flag: its HR rows read `own company only`, which was **known-wrong,
not merely unconfirmed**, because `HR` and `ASSISTANT_DIRECTOR` work at group level under
AHS/HQ and administer every entity. Shipping it would have blocked HR on the system's first
day.

It was deliberately left as written until the answer arrived from the right place, so that
the scope would be decided **once**, in Auth & RBAC, rather than twice in two documents.
`adr/0004` decision 1 is that answer, and §6.1 above now carries it. **The flag is
discharged; §6 is implementable.**

Note what the fix actually was: not "HR is group-level," but **"scope is derived from the
employer's position in the hierarchy."** The first would have hardcoded today's staffing
into the permission layer; the second produces the same result today and still works when a
subsidiary hires its own HR.

This also disposes of the question raised against **cross-company transfers** (§5.7): there
is no "source HR vs destination HR", because HR is not a per-company role in this group. A
group-level HR moving an employee from AIM to TURSENIA is the ordinary case.

## 7. UI

Blade + Livewire 3. Screens: employee list (search/filter/paginate), employee detail
(tabbed — Employment, Personal, Family, Education, Employment History, Documents,
**Roles & Functions**, Status History), create/edit form, archive confirmation.

Status History tab is **read-only** in the UI — reinforcing §5.3 at the interface level.

**Roles & Functions tab** shows current authority grouped by company, with revoked rows
available but visually separated — a revoked role is history, not a current grant, and the
two must never read as the same thing. The Employment tab shows the payroll employer and
the derived cross-company line (BR-12):

> **Employer (payroll):** AHS
>
> **Also serving at:** AHS — BDO, Account · AIM — Manager, Account, Admin

**The Status History tab merges `employee_status_history` and `employee_roles` into one
chronological timeline**, so HR reads a single history without the data being stored twice
(§5.3, `adr/0003` decision 8). Each entry indicates its source:

```
15 Jan 2026 · Role → Manager (AIM)        [employee_roles]
01 Mar 2026 · Status → CONFIRMED          [employee_status_history]
08 Aug 2026 · Account (AIM) revoked       [employee_roles]
```

This merge is a **read-side concern only**. It must not tempt a writer into recording role
changes in the ledger to make the query simpler.

## 8. Testing

Pest. Not strictly mandatory under `conventions.md` §9 (no money or statutory
entitlement here), but required for this module because the branching logic is
non-trivial and everything downstream depends on it:

1. Tenant scope — a user of company A cannot read, list, or update company B's employees,
   including via child tables.
2. `company_id` cannot be overridden via request input (mass-assignment probe).

**Shared org structure (`adr/0002`) — the highest-risk area in this module:**

1a. A shared branch/department (`company_id IS NULL`) **is visible** to users of every
    company. This is the inverse of the usual tenant test and is the bug most likely to
    ship: a plain `where company_id = :current` returns fewer rows rather than erroring,
    so it fails silently.

1b. A company-dedicated branch/department (`company_id` set) is **not** visible to other
    companies — the carve-out must not become a blanket bypass.

1c. An employee whose `branch_id`/`department_id` belongs to a different company, or to a
    shared unit, saves successfully and is **not** rejected by validation (BR-12).

1d. An HOD of a shared department **cannot** act on — or read — an employee of a
    different `company_id` sitting in that same department (BR-10, `adr/0002` decision 4).
    A shared department is exactly where this is most likely to be got wrong, since the
    two employees are visibly colleagues.

1e. A shared department with **two** HODs employed by different companies saves
    successfully, and each resolves as HOD only for their own company's staff in it
    (BR-10). An employee in that department whose company has no HOD there falls back to
    the standard chain rather than erroring.

1f. `HR` / `ASSISTANT_DIRECTOR` cross-company **approval** is permitted, and grants **no**
    read access to that employee's salary, documents, family records, or leave history
    (BR-14). Both halves must be asserted; testing only the permission turns the narrow
    exception into an open door. *(The approval half exercises the Approval engine —
    assert here only what Employee Master owns: that the read stays denied.)*

    ⚠ **This test must use an HR employed by a *subsidiary*, not by AHS.** An AHS-employed
    HR reads the whole group legitimately (§6.1), so writing the test with one asserts
    nothing — it would pass or fail for the wrong reason and would hide a merged-axes bug.
    The subsidiary-employed HR is the only case where "approves across, reads one" is
    observable.

**Read scope derived from the hierarchy (`adr/0004` decision 1) — required by the ADR:**

1g. An `HR` employed by **AHS** (the parent) lists employees of **every** group company.
    This is the row that was known-wrong before 2026-08-11 and the one that blocks HR on
    day one if it regresses.

1h. An `HR` employed by a **subsidiary** lists that subsidiary's employees **only** — and
    the scope is read from `companies.parent_company_id`, not from the role. Assert by
    moving the same HR's `employees.company_id` and re-reading, with **no other change**.

1i. **A subsidiary mis-parented under AHS grants its staff group-wide reads.** The
    hierarchy is small and rarely changes, but it is load-bearing — `adr/0004` requires
    this covered by a test rather than left to seeding discipline.

1j. Scope is **derived, never stored**: no column, request field, or config value can widen
    or narrow it (mass-assignment probe, as in test 2).
3. Status history row written on every qualifying change; none written on a no-op save.
4. Status history rows cannot be updated or deleted.
5. Status transitions — permitted ones succeed, forbidden ones rejected; terminal
   statuses stay terminal.
6. `BR-11` — the `employee_roles.role` enum contains exactly the six permitted values and
   **rejects `MASTER_ADMIN`** at the database level (a guard against a future migration
   quietly re-adding it, since this rule is structural and has no runtime check behind
   it); it also **rejects `STAFF`**, since ordinary staff are expressed by the absence of
   a row; and a Master Admin user has null `employee_id` and therefore no pivot row.
7. Supervisor/manager self-reference and cycle rejection (BR-8).
8. `working_days` / times persist and cast as structured values, not strings (BR-5).
9. Soft delete hides from list, retains child records.
10. Importer idempotency — re-running produces no duplicates; unknown company spelling
    is rejected, not auto-created.
11. `employee_no` generation — sequential, zero-padded, `AHS` prefix for employees of
    **every** subsidiary; the sequence is group-wide, so two employees of different
    companies never collide (BR-13).
12. `employee_documents.type` accepts each of the seven enum values and rejects an
    unlisted one.

**Multi-role authority (`adr/0003`) — the second highest-risk area, for the same reason as
the shared-structure block above: every failure here returns a wrong answer rather than an
error.**

13. **A revoked role is not current authority.** Grant a role, revoke it, then assert the
    authority check returns false. This is the single most dangerous omission in the
    module: a query missing `WHERE revoked_date IS NULL` returns revoked authority as
    live, and **nothing fails** — the request is simply approved by someone who should no
    longer be able to. Assert it through the model's default scope, not by hand-writing
    the condition in the test.
14. **Re-granting creates a second row, not a resurrection.** Grant → revoke → grant again
    leaves **two** rows for that (employee, company, role), the first with `revoked_date`
    set and the second null. A service that clears `revoked_date` on the original row
    fails this (§5.6).
15. **Authority is per company.** An employee who is `MANAGER` at AIM and holds no role at
    AHS resolves as `MANAGER` when asked about AIM and as **no authority** when asked
    about AHS. Assert both directions from the same employee record — asserting only the
    positive passes trivially against a company-blind implementation.
16. **An employee with no `employee_roles` row anywhere holds no authority**, and is not
    an error state — that is ordinary staff (BR-9).
17. **Grant restrictions hold at the service layer.** An `HR` user attempting to grant
    `ACCOUNT`, `HR`, `ASSISTANT_DIRECTOR` or `HOD` is **rejected on submit**, not merely
    offered no button (BR-16). Probe it as a direct request, bypassing the form, or the
    test proves only that the Blade template is tidy.
18. **`effective_date` and `created_at` are independent** — a grant back-dated to last
    month persists both values distinctly (§5.6).

**Company transfer (BR-17) — every assertion here fails silently in production:**

19. On transfer, the four **descriptive** child tables have `company_id` updated; **the
    ledger, the role pivot and the job-function rows are unchanged**. Assert all three
    categories in one test — the failure mode is cascading too much, and a test covering
    only the descriptive tables cannot see it.
20. **A transferred employee's Status History remains fully visible** through the employee
    relationship, including pre-transfer rows carrying the old `company_id`. This is the
    silent-missing-rows failure the carve-out exists to prevent (BR-17); the tab appearing
    to start at the transfer date raises **no error**.
21. **The same table queried directly for reporting stays tenant-scoped** — a report run
    as the new employer does **not** pick up the old employer's frozen rows. Test 20 and
    21 must both pass; 20 alone turns the carve-out into a blanket scope bypass.
22. **`employee_no` survives a transfer unchanged**, and a rejoining employee gets a **new
    number and a new record** with `previous_employee_id` set, never a reactivated one
    (BR-13, BR-2).
23. **The sequence never rewinds** — concurrent inserts produce no duplicate
    `employee_no`. Exercise the `lockForUpdate()` path with two concurrent transactions
    rather than trusting the unique index to be the only guard, since the index reports a
    collision as a crashed save rather than preventing it (BR-13).

**Job functions (BR-15):**

24. Deactivating a `job_functions` row removes it from the assignment picker while
    **existing `employee_job_functions` rows stay intact and readable**, and it can be
    reactivated.
25. `HR` cannot create or deactivate a `job_functions` type; Master Admin can (BR-15, §6).
26. **Both `HR` and Master Admin can transfer an employee**, each acting alone with no
    approval step — assert both actors, since implementing only one still passes a
    single-actor test (§5.7). A user holding neither is rejected.
27. **Every transfer writes an `audit_logs` entry naming the actor**, and a transfer that
    fails mid-cascade leaves **neither** the transfer nor the audit row behind — both are
    in one transaction (§5.7). The actor identity is what later distinguishes an ordinary
    HR transfer from a Master Admin support intervention.

## 9. Definition of Done

The full `conventions.md` §10 checklist — `optimize:clear`, syntax check, `route:list`,
`php artisan test`, `npm run build`, migration test against an **empty** database, and
the sensitive-file check (no `.env`, no employee documents, no salary files staged).

Plus, specific to this module: `schema.md` updated in the same commit as each migration,
and no migration timestamp collisions.

## 10. Resolved Decisions

The five questions that previously blocked approval of this spec are **closed**. Item 6
records a sixth decision that arrived later and superseded part of the foundation the
others were written on; item 7 closes the one question that supersession opened.
**Nothing in this section is outstanding.** Recorded here with their answers so the
reasoning survives.

**1. `employee_no` format — RESOLVED.** Group-wide unique, format `AHS-0001`: `AHS`
prefix + sequential zero-padded number, single group-wide sequence.

⚠ The prefix is **always `AHS`** — the parent company — **regardless of which subsidiary
employs the person**. An AIM employee is `AHS-0042`, not `AIM-0042`. This is
counterintuitive enough to be "corrected" by mistake, so: it is intentional. The unique
index stays group-wide, not composite with `company_id`.

**Extended by `adr/0003` decision 9**, which answered what the format question left open:
*generation* (a `lockForUpdate()` row in `sequences`, never `MAX() + 1`) and *lifecycle*
(kept on transfer, retired permanently on exit, new number on rejoin, and a Master-Admin
correction burns the vacated number). See BR-13 — that rule now carries the detail a
migration author needs.

**2. `fingerprint_id` — RESOLVED.** HR-managed on the employee record, **current value
only**. No enrolment-history table in Phase 1; a re-enrolment overwrites in place. If
historical punch-to-employee resolution proves necessary, that is a Phase 2 Attendance
decision, not a Phase 1 table.

**3. Salary — RESOLVED as deferred.** Not built in Employee Master; belongs to Phase 2
Payroll. The permission-model concern that made this a question does not arise, because
no salary data exists on the record.

Two structural facts confirmed while resolving it are captured in
`docs/modules/payroll-notes.md` so they survive to the Payroll spec: **basic salary is
not static** (HR raises it over tenure → needs a history ledger, not an overwritable
field), and **allowances are not a fixed set** (HR creates types manually → needs
dynamic `allowance_types` + `employee_allowances` tables, not fixed columns).

**4. Document types — RESOLVED.** Fixed enum for Phase 1:

```
IC, PASSPORT, EDUCATION_CERTIFICATE, OFFER_LETTER, CONFIRMATION_LETTER,
RESIGNATION_LETTER, OTHER
```

A **starting set, not exhaustive** — amendable by a future migration when HR needs more
types. `OTHER` is a deliberate escape hatch so an unanticipated document is never blocked
from upload while that migration is written.

**5. Branches / departments spanning companies — RESOLVED, and larger than the original
question.** The question asked about departments spanning *branches*; the real answer is
that branches and departments span **companies**, and this is a **common pattern in the
group, not an edge case** — AIM, TURSENIA and ES SOFEEYA staff share one Logistics
branch, HQ Marketing draws from several companies.

`branches.company_id` and `departments.company_id` are therefore **nullable**: `NULL` =
shared/group-level, set = company-dedicated. `employees.company_id` stays **mandatory**
and independent — it is the payroll/legal employer, and need not match the employee's
branch or department. Full decision and reasoning: **`adr/0002`**.

This also closes the HOD dynamic-routing question from `adr/0001` decision 3 — see BR-10.

⚠ **Corrected 2026-08-08.** An earlier reading of this decision had HOD authority
following the shared *department* across companies. It does not: **HOD authority is
strictly same-company**, and `HR` / `ASSISTANT_DIRECTOR` are the only tiers that approve
across companies. Shared org structure and approval scope are separate questions, and one
was wrongly inferred from the other. See BR-10, BR-14, and `adr/0002` decision 4's
amendment note.

**The two Auth & RBAC concerns previously listed here as not-blocking are now closed** by
`adr/0004`, and both landed in §6:

- the `system_access` value-set question — **closed**, three values, `NOT NULL` defaulting
  to `STANDARD` (`adr/0004` decision 2, `schema.md` `users`);
- the **data-visibility permission check** that had to sit separate from cross-company
  approval authority (BR-14) — **closed**, read scope derives from the employer's position
  in `companies.parent_company_id` (`adr/0004` decision 1, §6.1), and approval is still
  never an input to it.

Salary visibility was answered earlier and is unchanged: it is the `ACCOUNT` role, and no
`HR` holds it at any scope (`adr/0003` decision 5). The `hr_scope`
(`PAYROLL | OPERATIONS`) distinction previously listed here is **withdrawn**, not deferred
— the Payroll HR / Operations HR split it modeled does not exist.

**6. Multi-role authority — RESOLVED by `adr/0003`, and it superseded a decision this
spec had already built on.** `adr/0001` decision 2 modeled authority as a single
`core_role` enum column on `employees`. Client review established that a person holds
**several roles, and the roles differ per company**, which that column could express in no
form at all. Authority moved to the `employee_roles` pivot; the column was never created.

Three consequences ripple through this spec, recorded here so they are not re-litigated:
`ACCOUNT` was added and `STAFF` removed from the authority list (§3); job functions became
a separate reference table rather than more enum values (BR-15); and salary visibility
became a role rather than an HR sub-scope (BR-9), which is what closed item 3's follow-on
above.

This is `CLAUDE.md` Principle #1 paying for itself: the assumption was falsified while it
was still a paragraph in a spec, not a migration with a backfill behind it.

**7. Who may initiate a company transfer — RESOLVED.** **Both `HR` and Master Admin, each
acting directly.** HR is the ordinary actor; Master Admin is a support path for when HR is
unavailable. Neither approves the other and Master Admin is not an escalation tier — the
same shape as HR's may-act-at-any-time position in approval routing. Every transfer is
audited with the actor's identity, because it reassigns statutory responsibility for EPF,
SOCSO and the EA Form between two legal entities. Full rule: §5.7; permission row: §6.

This closes the last question this spec was holding.
