# Module Spec — Employee Master

- **Phase:** 1 — Employee & Org
- **Status:** **Accepted — 2026-08-13.**

  > **This header read `Draft — awaiting approval` until 2026-08-13**, while `CLAUDE.md` §11
  > had already declared this spec **fully unblocked**. Three migrations — `employees`,
  > `employee_roles`, `employee_status_history` — landed during that window under §11's
  > authority. The contradiction is **recorded, not deleted**. Cause: the status header was
  > not updated when §11 changed.
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
- **Nine migrations** belong to this module — one per table created here. Previously six;
  `adr/0003` adds three.

  **✅ All nine have landed — the last six on 2026-08-13 (slice 1).** The order below is the
  order that **actually happened**, not a plan: an earlier version of this list recorded an
  FK-safe order that was never followed (`employee_roles` was written second, not ninth), and
  the two were never reconciled until the pre-slice-1 audit.

  1. ✅ `employees` — `2026_08_11_100400_create_employees_table.php`
  2. ✅ `employee_roles` — `2026_08_11_100500_create_employee_roles_table.php`
  3. ✅ `employee_status_history` — `2026_08_12_100300_create_employee_status_history_table.php`
  4. ✅ `employee_family_members` — `2026_08_13_100000_…`
  5. ✅ `employee_education_history` — `2026_08_13_100100_…`
  6. ✅ `employee_employment_history` — `2026_08_13_100200_…`
  7. ✅ `employee_documents` — `2026_08_13_100300_…`
  8. ✅ `job_functions` — `2026_08_13_100400_…`, before the pivot that FKs to it
  9. ✅ `employee_job_functions` — `2026_08_13_100500_…`

  Only 8 → 9 was an ordering constraint among the six; 4–7 depend solely on tables that
  already existed. Nothing in 1–3 FKs to another of the nine either, which is why the wrong
  recorded order never broke anything.

  **Two decisions were taken while writing the six, and both amend `schema.md`:**
  `job_functions` carries **no `company_id`** — the vocabulary is group-wide and Master Admin
  owns it — and its **`is_active` column was withdrawn**, because soft delete already expresses
  deactivation and two columns for one state is the pattern this project has rejected six
  times. See `schema.md` § `job_functions`.

  `sequences` is **not** in this count — it is a Phase 0 Core Engine table (see the
  dependency note above). Timestamps were verified with `ls database/migrations | sort`
  before committing (`conventions.md` §6; the legacy system shipped three colliding
  timestamps, and six generated in one session was exactly the condition that produced them).
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

> **⚠ The rejoining row above could not be executed until 2026-08-17, and `adr/0015` is what
> makes it executable.** A rejoiner brings the same `ic_no` and the same `users.phone_no`; both
> are unique, the old rows still hold them, and the new record therefore could not be created
> at all. **The rule was never the problem — the indexes were**, so this row and the whole
> lifecycle table stand exactly as written.
>
> `adr/0015` adds a nullable `superseded_at` to `employees` and `users` and rebuilds four
> unique indexes over an expression, so a superseded row releases its claim on a value without
> giving the value up. `CreateEmployee` sets it on the prior record and its account **before**
> writing the new rows, in the transaction it already opens — order is load-bearing.
>
> **✅ BUILT 2026-08-17.** `superseded_at` is on both tables, the four unique indexes are
> functional, and `CreateEmployee` releases the prior claim as its first act inside the
> transaction. **§8 test 22 now passes** — `RejoinerIdentityTest` exercises it end to end,
> carrying the same IC and the same phone number, and asserts that the prior record keeps both
> values.
>
> ⚠ **ONE THING IS STILL NOT BUILT, and it is the user-facing half.** `adr/0015` decision 5
> makes the form ask *"has this employee worked here before?"* and then search prior records.
> **That search does not exist**: a prior record is routinely soft-deleted and may sit under a
> former employer, so it needs a scope the employee list deliberately does not have. Until the
> registration screen lands, **the rejoiner path is reachable only through `CreateEmployee`
> directly** — the constraint is fixed, the workflow is not.

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

> **⚠ One phrase above is superseded by `adr/0010`; the rest of BR-17 stands unchanged.**
>
> The cascade table is still correct — existing event rows are **never** rewritten by a
> transfer, and that is the whole point of the category. What `adr/0010` changes is the
> **new** rows a transfer now writes itself: for those, *"the employer at the time it
> happened"* resolves to the **new** company, not the old one. Freezing them to the old
> employer would open the new company's history with a gap, which is the silent-missing-rows
> failure this very section exists to prevent.
>
> The full amendment lands with the implementation PR; this pointer exists so the phrase is
> not read as current in the meantime.

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

> **⚠ "Populated automatically" describes a mechanism that does not exist — found 2026-08-13,
> and NOT fixed in Employee Master slice 2.**
>
> There is no observer, no trait, no base model and no hook anywhere in the codebase that
> fills either column. `CreateEmployee` does not set them either. **Every row written so far —
> employees, roles, and the slice 1 child tables — carries `NULL` in both.**
>
> **This is a data bug that has already happened**, not a missing feature, and it reaches
> further than the line above suggests. `schema.md` removed
> `employee_documents.uploaded_by` on the argument that *"`created_by` already records the
> same person"*. **That sentence has never been true.** The write-once lock on `file_path`,
> built specifically to keep `created_by` honest as the uploader, is currently protecting a
> `NULL`.
>
> Slice 2's Actions set both columns **explicitly**, which is correct and is not wasted work.
> What is deliberately **not** done here is the general mechanism: it touches every model in
> the system, it needs a decision between an observer, a trait and explicit assignment, and it
> needs a backfill position for the rows already written. **That is an ADR and a PR of its
> own, and it must not be slipped into a module slice.**
>
> **✅ Decided by `adr/0009`** — that ADR is the answer to this note, and the mechanism it
> specifies lands in its own PR.

### 5.2 Delete

Soft delete only. An employee with dependent records is archived, never hard-deleted.
Hard deletion is not exposed in the UI at all.

> **⚠ NOTHING IN THE APPLICATION TOUCHES `employees.deleted_at`, AND THAT IS THE RECORDED
> STATE RATHER THAN A GAP TO CLOSE — 2026-08-18.**
>
> The column exists because `conventions.md` §3 requires it on every business table, and no
> code path writes it. `Employee` uses `SoftDeletes`, no `->delete()` is called on it anywhere
> in `app/`, and no `->restore()` exists anywhere in the repository. `EmployeePolicy::archive()`
> is an alias for `update()` whose only caller is a test. The exclusion from the list in §5.4
> is Eloquent's default `SoftDeletingScope` doing it — neither `TenantScope` nor
> `Employee::scopeVisibleTo()` knows anything about `deleted_at`.
>
> **A real case exists and has never occurred:** HR creates a duplicate record by mistake, and
> §5.2 bans hard deletion, so the duplicate has to go somewhere. **Do not build a screen for
> it until it happens.** A confirmation screen for an operation with no caller is a control
> that invites the operation.
>
> **Archiving and terminal status are two axes, not one.** A terminal `staff_status` is the
> employment lifecycle (BR-2); `deleted_at` is administrative cleanup. Setting a terminal
> status does not archive, and archiving does not freeze the account or revoke roles — BR-A15
> belongs to the status change alone. An employee record **stays in the list after they
> leave**. `superseded_at` is a third thing again: an identity claim released for a rejoiner
> (`adr/0015`), not a deletion of any kind.
>
> **Restoring a wrongly-deleted record is not forbidden.** BR-A18 refuses reactivation of an
> **account** after a terminal status; it says nothing about `deleted_at`, and the two must not
> be read as one rule.
>
> The one deliberate reader of archived rows is
> `App\Services\Employee\PriorEmploymentLookup` — one exact-match query returning six
> fields, with no HTTP route. `adr/0015`'s amendment states that an archived-record **browse**
> is not built and not authorised, and this note does not change that.

### 5.3 Status history is automatic

Any change to `staff_status`, `position_id`, `department_id`, or `level` writes a new
`employee_status_history` row inside the same transaction as the update — the caller
cannot forget to write it, because the service does it, not the controller.

**Both Actions exist.** `App\Actions\Employee\ChangeEmployeeStatus` (2026-08-12) handles
`STAFF_STATUS`; `App\Actions\Employee\ChangeEmployeeAssignment` (2026-08-13, PR #35) handles
`POSITION`, `DEPARTMENT` and `LEVEL`.

> **⚠ Corrected 2026-08-18.** This paragraph read *"The other three change types have no
> Action yet"* — true on 12 August and stale the following day. `conventions.md` §9.

`ChangeEmployeeStatus` validates the BR-2 transition **before** opening the transaction, then
writes the status, the ledger row, the audit row and — for a terminal status taking effect
today or earlier — the BR-A15 freeze, all inside one transaction.

⚠ **The ledger row is not optional, and BR-A17 is why.** Account expiry counts ten days from
`employee_status_history.effective_date`, so a terminal status written *without* its ledger
row has nothing to count from: the account never expires and keeps read access indefinitely,
wider than the rule allows and with nothing to notice. That failure is the reason this is an
Action rather than an `$employee->update()` in a controller. Rows are
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

> **⚠ The set is five, not four — `EMPLOYER` joined it on 2026-08-13 (`adr/0010`, migration
> `2026_08_13_100700`).** The paragraph above is retained as written because its subject is the
> `CORE_ROLE` exclusion, which `adr/0010` leaves untouched. `StatusHistoryScopeTest`'s guard
> already reads *five* and asserts all five.

**`old_label` / `new_label` are a snapshot of the display text at the time.** Storing only
`department_id = 7` would need a join to render, and that join shows the department's name
**today**, not its name **then** — renaming a department would retroactively rewrite history,
and a ledger that changes retroactively is not a ledger. The labels are redundant for enum
types (`CONFIRMED` / `CONFIRMED`); that is accepted, because one uniform row shape costs a few
bytes and avoids per-type branching in every reader.

#### 5.3.1 A future `effective_date` splits the write in two

> **⚠ Amended 2026-08-18 — `adr/0016`.** The paragraphs above describe a single transaction
> that writes the status **and** the ledger row **and** the freeze together, always. That is
> now true only when the change takes effect **today or earlier**.

**`effective_date` may be in the future, and the ordinary case is that it is.** An employee
who gives notice on the 18th for a last working day on the 31st is not a resigned employee
on the 18th. They come to work, verify their own attendance, endorse their subordinates'
leave, and are paid for August in full. **Notice periods are how people resign.**

**`staff_status` answers one question — what is true today.** The ledger answers a different
one — what was decided, and from which date it applies. `effective_date` has existed for
exactly this since `adr/0003` decision 8, where a promotion is typically effective before
HR gets to enter it. A future-dated departure uses the same column for the same reason.

The write divides by date, not by status:

| `effective_date` | Written immediately | Left to the scheduler |
|---|---|---|
| **Today or earlier** | Ledger row, audit row, `staff_status`, BR-A15 freeze — one transaction, exactly as before | nothing |
| **Later than today** | Ledger row, audit row | `staff_status`, BR-A15 freeze |

**A backdated status is in the first row, not the second.** Paperwork that arrives late is
recorded with a past `effective_date` and freezes in the transaction like any same-day
change. Only *later than today* defers.

**No second Action for the ordinary case, and BR-A15 stands as written.**

**`TERMINATED` follows the same rule as `RESIGNED`.** `adr/0004` decision 5 diverges the two
statuses in two places — who approves (the manager approves a resignation; a termination
review is non-blocking) and the session kill (BR-A15, `TERMINATED` only). **Neither is a rule
about dates.** A dismissal for misconduct carries an `effective_date` of *today*, so it lands
in the first row above and freezes immediately, exactly as that decision requires. A
retrenchment or an expiring contract carries a real future date, and the employee works out
the notice they are legally owed.

**The scheduler completes the deferred half. It does not repeat the recorded half.**

When the date arrives, the scheduled task sets `staff_status`, revokes the `employee_roles`
rows, kills sessions if the status is `TERMINATED`, and emits the BR-A16 Approval Engine
event. **It writes no ledger row.** The row was written on the day HR recorded the decision
and is already correct; a second row would show the employee resigning twice on the §7
timeline.

⚠ **`adr/0016`'s sentence *"A scheduled freeze writes a row like any other"* means the row HR
wrote is an ordinary row — not that the task writes one.** Read the other way it produces the
duplicate above.

**The ledger row's `changed_by` stays with the human who decided.**
`employee_status_history` is append-only, carries no `updated_by`, and is **not** in
`AuthorshipObserver::MODELS` — nothing about authorship touches it. The system user appears on
`employees.updated_by` only, which is accurate: the decision was HR's on the 18th; the
execution was the scheduler's on the 1st.

**The deferred half has its own Action:**
`App\Actions\Employee\ApplyPendingStatusChange`, invoked only by the scheduled task.

It sets `staff_status`, revokes the `employee_roles` rows, kills sessions for `TERMINATED`,
emits the BR-A16 event, and writes the audit row. **It writes no ledger row** — the row exists
already. `ChangeEmployeeStatus::execute()` cannot serve here for exactly that reason: it always
writes one.

**It still calls `assertPermitted()`.** The transition was validated when HR recorded it, and
re-validating costs nothing — but the gap between recording and effect is days long, and a net
that catches an unanticipated route into that window is worth more than the call it saves.

**The two Actions record different actors, and both are true.** The ledger's `changed_by` is
the HR user who decided (§5.3.1); the audit row's actor is the system user who executed
(`adr/0016` decision 1). *Who decided* and *who performed* are separate questions, which is why
the two tables answer them separately (`adr/0003` decision 8).

#### 5.3.2 The divergence window

Between the recording and the effective date, **`employees.staff_status` and the latest
`employee_status_history` row deliberately disagree.** This is a named, bounded state, and
every reader must be written knowing it exists.

| Reader | Behaviour during the window |
|---|---|
| Employee list, headcount, payroll eligibility, leave accrual, attendance import | Read `staff_status`. The employee is **active**, because they are. |
| `EnsureAccountIsActive` | Reads `staff_status`. Not frozen, not expired. |
| `AccountExpiry` | Returns `null` throughout — see §5.3.3 |
| §7 timeline | Reads the ledger, and therefore holds an event that **has not happened yet** |
| Pending-departure indicator | Reads the ledger — and is the reason the window is visible at all |

**§7 must render a future ledger row as future.** The timeline is a chronological history and
a reader scanning it will otherwise read a dated resignation as one that occurred. The row is
separated and labelled; *"render it inline with the past"* is not an available choice.

⚠ **A recorded-but-not-effective departure needs its own indicator, and BR-A19 is not it.**

BR-A19's five-dashboard countdown is the **expiry** countdown: it reads
`AccountExpiry::expiresAfter()`, which returns `null` until `staff_status` is terminal
(§5.3.3). It therefore appears **after** the freeze and cannot catch a date typed wrongly on
the 18th.

That matters because `adr/0004` decision 5 gives the countdown a specific job — it is *"the
correction mechanism"*, the reason there is no cancel button. Under a same-day-only rule the
countdown appeared the moment HR acted, so the job was done. A future-dated departure opens a
gap of days in which the decision is recorded and nothing announces it.

**BR-A19 is not widened to close that gap** — it would mean `AccountExpiry` reporting a date
for an employee who is not leaving yet, which is a second meaning for one method. What is
needed is a **separate** indicator, reading the ledger, showing *"departure recorded,
effective 31 August"* to the same five parties from the day it is recorded.

> **⚠ This indicator has no BR number and no home yet.** It belongs either here or beside
> BR-A19 in `auth-rbac.spec.md`; it must not be left as prose in this subsection, or it will
> be built as a UI afterthought — which is exactly what it cannot be.

#### 5.3.3 `AccountExpiry` is gated on `staff_status`, and that is correct

`AccountExpiry::terminalEffectiveDate()` returns `null` unless `employees.staff_status` is
already terminal, before it reads the ledger at all. **During the divergence window it
resolves nothing**, so `expiresAfter()` is `null` and `hasExpired()` is false.

**That is the right answer.** The employee has not left; the account must not expire. The
guard reaches it by a shorter path than the arithmetic would, and both paths agree.

> **⚠ The comment above that guard, dated 2026-08-12, must be amended in the same commit as
> this section.** It reads that §5.3 *"makes the status-change service write the ledger row in
> the same transaction as the change, so the two cannot come apart."* §5.3.1 now separates
> them deliberately. The guard's behaviour does not change; the justification written beside
> it is no longer true, and a reader trusting it will write something on top of an assumption
> that has been withdrawn. `conventions.md` §9.

#### 5.3.4 What the scheduler selects — and the two meanings of "latest"

The predicate belongs to `adr/0016` decision 3; it is restated here because §5.3 is what makes
it correct or incorrect.

**An employee is due for completion when the *latest* row on their status ledger is terminal
and its `effective_date` is strictly earlier than today.**

- **Latest, not "a terminal row exists."** The ledger is append-only, so a decision withdrawn
  before it takes effect is a **new row**, not a deletion. A predicate asking whether the
  ledger *holds* a terminal row still matches after the withdrawal and freezes an employee who
  is staying.
- **Latest by `created_at desc, id desc`.** `created_at` is second-resolution
  (`useCurrent()`), so two decisions recorded in one second tie — and the tie is broken by
  insertion order, because the later insert is the later decision. Without the `id` fallback
  the predicate picks arbitrarily between a departure and its withdrawal, and the wrong pick
  freezes an employee who is staying. `AccountExpiry` already models the shape for the same
  reason on the other axis: `reorder('effective_date','desc')->orderByDesc('id')`.
- **Strictly earlier than today.** `effective_date` is the last working day; the freeze is the
  midnight *after* it. `<= today` freezes the employee on the morning of their final shift —
  one day early, through a mechanism that would report success.

> **⚠ `adr/0016` decision 3 as merged reads *"effective today or earlier"*.** It is off by one
> and must carry a dated pointer to this subsection in the same commit, or the two documents
> disagree in writing.

⚠ **"Latest" means different things to the two readers of this table, and neither is wrong.**

| Reader | Asks | Orders by |
|---|---|---|
| Scheduler predicate | *Which decision is currently in force?* | `created_at desc, id desc` — the later **decision** governs |
| `AccountExpiry` | *From which date do I count ten days?* | `effective_date desc, id desc` — the later **event** is the one that ended the employment |

A withdrawal recorded on the 25th for the 25th sits *earlier* on the effective-date axis than
the resignation it withdraws, which is dated the 31st. Ordered by `effective_date` the
resignation still wins and the withdrawal does nothing — which is why the predicate cannot use
that ordering. `AccountExpiry` cannot use `created_at` either, for the reason its own comment
gives.

**Do not reconcile these.** They are two questions, and the next reader who notices the
mismatch will align one to the other and break it silently. The divergence is recorded here so
that reader finds this table first.

#### 5.3.5 Withdrawing a recorded departure

**A departure recorded for a future date can be withdrawn:**
`App\Actions\Employee\CancelPendingStatusChange`.

An employee who gives notice and then agrees to stay is ordinary. Under a same-day-only rule
the case could not arise — a terminal status meant the person had already gone, and BR-2
refused every transition out of it precisely to enforce BR-A18. §5.3.1 creates a state where a
terminal row means the person *will* go, and that is a decision, not a fact.

**BR-2 is not relaxed.** Its refusal of transitions out of a terminal status stands unchanged,
and BR-A18's no-reactivation rule stands unchanged. Both are about employment that has
**ended**. This Action operates only on employment that has not.

> **⚠ `ChangeEmployeeStatus` does not refuse this as `fromTerminal()`.** `assertPermitted`
> reads `employees.staff_status`, which during the window still holds the pre-departure value,
> so the refusal arrives as `InvalidStatusTransitionException::between()` on `$from === $to`.
> A test asserting `fromTerminal` here passes vacuously (`conventions.md` §9). Confirmed
> against the code 2026-08-18.

**A withdrawal writes a ledger row, and this is forced rather than chosen.** §5.3.4's predicate
reads the latest ledger row. A withdrawal recorded only in `audit_logs` leaves the terminal row
latest, and the scheduler freezes the employee on the effective date with a full audit trail
explaining that they were staying.

**The row carries the `effective_date` of the row it withdraws — not today's date.**

| Column | Value |
|---|---|
| `effective_date` | **the withdrawn row's `effective_date`** |
| `new_value` | the employee's current `staff_status` — unchanged, because it never moved |
| `old_value` | the withdrawn terminal value |
| `changed_by` | the acting user |
| `created_at` | the day the withdrawal was decided |

**Because that is the date the withdrawal takes effect.** Between the recording and the
departure date nothing differs between the two worlds: the employee is active either way,
holds their roles either way, is paid either way. **The only day the withdrawal changes
anything is the day the freeze would have happened.** `created_at` records when it was
decided; `effective_date` records the day from which it applies. That is the split
`adr/0003` decision 8 established, applied literally.

⚠ **Dating the row today produces a ledger that lies, and the failure is invisible until the
date passes.** `Employee::statusHistory()` orders by `effective_date` ascending. A withdrawal
dated the 18th sorts *before* the resignation dated the 31st it cancels, so once the 31st has
passed a reader sees `18 Aug → ACTIVE` followed by `31 Aug → RESIGNED` as the final event —
**a working employee displayed as having resigned.** §5.3.2's future-row rendering does not
catch it: by then the withdrawn row is not a future row, it is a past-labelled row for an event
that never occurred.

**Two rows now share one `effective_date`.** The §7 timeline is not rendered through
`Employee::statusHistory()`'s ordering — `StatusTimeline` re-sorts by
`TimelineEntry::sortKey()`: date, then source rank, then a ten-digit padded `sourceId`. Two
`employee_status_history` rows on one date share a rank and fall through to ascending `id`, so
the withdrawal — inserted later — renders last and the timeline ends in the employee's actual
state. **This already holds; nothing needs changing for it.**

⚠ **`Employee::statusHistory()` itself carries no tie-break at all** — `orderBy('effective_date')`
and nothing more, so same-day rows return in whatever order the engine gives.

**No production caller is exposed to that today**, and how they avoid it is the finding.
`StatusTimeline` re-sorts entirely through `sortKey()`. `AccountExpiry` and
`PriorEmploymentLookup::terminalEffectiveDate()` both call
`reorder('effective_date','desc')->orderByDesc('id')` — and both carry a comment explaining why
`reorder()` rather than `orderByDesc()`: an added clause does not replace an existing one, so
`value()` would silently return the oldest row.

**All three found the relation's default ordering to be something they had to discard, and two
needed a comment to explain how to discard it correctly.** That is an argument about the
default, not about its tie-break. Adding `->orderBy('id')` is worth doing as a defensive
default for the next reader, but it closes no gap that exists — and this section does not
mandate it, because a change to a relation three services deliberately override belongs in its
own decision, not in a subsection about withdrawals.

> The `SOURCE_RANK` docblock already states why: `effective_date` and `revoked_date` are
> **dates, not timestamps**, so two events on one day are the ordinary case, not a rare
> collision.

**Three alternatives were rejected:**

- **Ordering `statusHistory()` by `created_at`.** It fixes this pair and breaks the ordinary
  case: a backdated correction recorded today belongs at its historical position on the
  timeline, which is the entire reason the ordering is by `effective_date`.
- **A `withdrawn_at` column on the withdrawn row.** An update to an existing ledger row. The
  table is append-only with no exception (`audit-trail.spec.md` BR-AT6).
- **No row at all, with the withdrawal held in `audit_logs`.** See the paragraph above: the
  predicate would still freeze the employee.

⚠ **`old_value` here names the row being superseded, not a value `employees` ever held** —
`staff_status` never moved. This is the one place in the ledger where that is true. It is a
consequence of the split in §5.3.1, not a new convention, and must not be generalised.

**After the date passes, the timeline reads `31 Aug → RESIGNED` then `31 Aug → ACTIVE`.** That
is correct and should not be smoothed away: HR decided one thing and then decided another, and
an append-only ledger shows both. A correction is a new row (`conventions.md` §3).

**Preconditions, all three required:**

1. The latest status row **by `created_at`** is terminal.
2. Its `effective_date` is later than today — once the scheduler has run, BR-A18 governs and
   there is no path back.
3. The acting user is `HR` or Master Admin, following §5.7's two-capable-actors pattern: HR is
   the ordinary actor, Master Admin the support path, neither approving the other.

#### 5.3.6 Confined to `staff_status`

**This section governs `staff_status` alone.** Whether a future-dated promotion or department
move defers the same way is undecided. `ChangeEmployeeAssignment` exists and writes all three
types today; it has **not** been examined against a future `effective_date`, and it must not be
assumed to follow this rule by having the rule copied into it. That is a separate decision.

#### 5.3.7 Must be asserted

1. Same-day terminal status freezes inside the transaction — no window in which writes succeed.
2. Backdated terminal status freezes inside the transaction, like same-day.
3. Future-dated terminal status writes the ledger row and leaves `staff_status` untouched.
4. Future-dated terminal status leaves roles live and sessions alive, `TERMINATED` included.
5. `AccountExpiry::expiresAfter()` is `null` throughout the divergence window.
6. The scheduled task, run on the effective date, changes nothing.
7. The scheduled task, run the day after, sets the status, revokes roles, emits BR-A16.
8. The scheduled task writes **no** ledger row — the timeline holds exactly one departure.
9. Running the task twice changes nothing the second time (`adr/0016` decision 3).
10. The employee appears as active in the list and in headcount throughout the window.
11. A withdrawal recorded during the window leaves the employee unfrozen after the effective
    date has passed and the task has run. ⚠ **This is the test that proves §5.3.4's ordering** —
    it fails against an `effective_date`-ordered predicate and against a
    ledger-holds-a-terminal-row predicate. Verify it fails before trusting it
    (`conventions.md` §9).
12. `CancelPendingStatusChange` is refused once the effective date has passed.
13. `ChangeEmployeeStatus` still refuses a transition out of a genuinely terminal status.
14. `ApplyPendingStatusChange` and `CancelPendingStatusChange` each declare an `AUDITS`
    constant and the `AuditedFields` architecture test stays green (BR-AT13). No new registry
    entry — `staff_status` is already listed.
15. ⚠ **An audit row actually lands for each of the two new Actions.** `AuditAuthorshipTest`
    states its own limit on line 17: it does not catch an Action that declares `AUDITS`
    correctly and never calls the logger. Assumption 14 alone can be green while nothing is
    audited — the empty-guard shape `conventions.md` §9 records three times.
16. ⚠ **Withheld.** The scheduled task's audit row currently lands with a **null actor and null
    `company_id`**, silently. `AuditLogger` writes `auth()->id()` — *"From the authenticated
    context, NEVER from arguments"* — and `AuthorshipContext` feeds `AuthorshipObserver` only.
    `adr/0016` decision 1 closed the **authorship** path; the **audit** path is a second
    requirement no document has named. See the note at the end of this section.

---

**Two things in this section are recorded as open. Neither blocks writing it down; both block
building it.**

#### Open — the recorded-departure indicator has no BR number

§5.3.2 establishes that a departure recorded for a future date must be visible to the five
BR-A19 parties **from the day it is recorded**, and that BR-A19 itself cannot carry it —
`AccountExpiry::expiresAfter()` returns `null` until `staff_status` is terminal (§5.3.3), so
that countdown appears only after the freeze.

`adr/0004` decision 5 gives the countdown a specific job: it is *"the correction mechanism"*,
the reason there is no cancel button. §5.3.1 opens a gap of days in which a departure is
recorded, a wrong date is uncorrected, and nothing announces it. `CancelPendingStatusChange`
(§5.3.5) is the remedy — and a remedy nobody knows they need is not one.

**It needs a BR number and a home**, most likely beside BR-A19 in `auth-rbac.spec.md`, since
the five dashboards are defined there. ⚠ **Left as prose it becomes a UI afterthought** — a
requirement stated in the reasoning of a section about something else, which nobody
implementing a screen will ever read.

**It is a third reader of "latest".** It must order by `created_at desc, id desc` like the
scheduler predicate (§5.3.4), not by `effective_date` — a withdrawal shares the withdrawn row's
date (§5.3.5), so an `effective_date` ordering keeps announcing a departure that has been
called off. When the indicator lands, §5.3.4's table gains a third row.

#### Open — how the system user reaches `AuditLogger`

Assumption 16 is withheld against this.

`adr/0016` decision 1 provisions a system user so the scheduled task can write
`created_by` / `updated_by`. That closes the **authorship** path only. `audit_logs` is written
by a different service with a different actor source: `AuditLogger` writes `auth()->id()`,
under a comment reading *"From the authenticated context, NEVER from arguments."* In a
scheduled task there is no authenticated user, so the row lands with a **null actor**, without
error — the silent-null failure `adr/0009` decision 2 exists to refuse, in the one table whose
entire value is answering *who*.

**Direction chosen 2026-08-18 — `AuditLogger` consults `AuthorshipContext` when no
authenticated user is present.**

The alternative — the task authenticating the system user into the guard — was rejected: it
would log in an account `adr/0016` decision 1 states cannot log in, refused at the form and
unrepresentable by `PhoneNumber::normalise()`. One account with a locked front door and a
nightly back door is two rules disagreeing about the same thing.

**`AuthorshipContext` is not an argument.** It is process context, set once at the boundary and
readable by anything inside it. The `NEVER from arguments` comment exists to stop a **caller**
injecting a false actor into a single call, and that prohibition is untouched — but the comment
must be rewritten, because as worded it forbids the fix.

⚠ **`company_id` goes the same way, and `security_events` already holds the rule to apply.**
`currentCompanyId()` resolves through `auth()->user()?->employee?->company_id`; the system user
holds no employee, so it is `null` under either form. `schema.md` states the principle for
`security_events`: `company_id` is filled where knowable and left null where it is not — a
*"reporting convenience, never an access control."* It maps directly. A scheduled sweep acting
across every company has no knowable company; an audit row about one employee's departure does —
the subject's.

**The per-table answer is itself the precedent, and it was written before this question
arose.** `schema.md` closes that passage: *"Two nullable `company_id` columns in one module, two
different answers — which is why the choice is made per table and declared on the model."* The
amendment adopts that reasoning rather than inventing a second one, and cites it.

⚠ **The two tables do not share a scope, and the amendment must not imply they do.** `AuditLog`
adds `SystemTenantScope` in `booted()`. `SecurityEvent` declares **no scope class at all** —
the documented opt-out `adr/0005` decision 6 requires, so that *"deliberately unscoped"* and
*"someone forgot"* stay distinguishable, with a guard test failing any model that is merely
silent. Borrowing the null-where-unknowable rule is not borrowing the scope.

**This is an amendment to `adr/0016` decision 1, not a new ADR** — the same question (*who acts
when no human does*) which that decision answered half of. ⚠ **No code changes to `AuditLogger`
until the amendment lands**: it is a Phase 0 service every module writes through.

### 5.4 List & search

- Default list is tenant-scoped and excludes soft-deleted rows.
- Search on `employee_no`, `full_name`, `nickname`, `email`. ⚠ **Not on `phone_no`** — the
  employee record no longer holds one (`adr/0006`). Searching by number means searching
  accounts, which belongs to the account management screen.
- Filters: company, branch, department, position, `staff_status`, `employment_type`,
  `level`.
- Paginated. Query lives in a model scope or repository, not inline in the controller
  (`conventions.md` §1).

**The list is bounded by `Employee::scopeVisibleTo()`, which is not a filter** — landed
2026-08-15 with the list itself (`adr/0011`). It resolves in four branches:

| Account | Sees |
|---|---|
| `system_access` `FULL` / `VIEW_ONLY` | everything in read scope |
| Holds `HR`, `ASSISTANT_DIRECTOR` or `ACCOUNT` in scope | everything in read scope |
| Holds `SUPERVISOR`, `MANAGER` or `HOD` at their own company | employees of that company whose `direct_supervisor_id` **or** `manager_id` names them (BR-8) — **plus their own record** |
| No role at all | their own record only |

**The `department` entry in the filter list above is a filter the reader picks, not a boundary
the system imposes.** The boundary is the scope, and the two must not be confused: clearing
every filter widens nothing.

> **⚠ It is a LOCAL scope, called explicitly, and it must never become a global one.**
> `TenantScope` is global because tenancy is a property of the table; the supervisory narrowing
> is the question one screen asks. **A scope that must be called cannot narrow a query that
> does not call it** — so `CreateEmployee`, `TransferCompany`, the audit reader and the seeders
> are untouched, and HR cannot silently lose rows. That containment is the answer to
> `adr/0011`'s own objection that a tier-branching scope has no precedent here and fails by
> returning fewer rows rather than erroring.
>
> **⚠ The actor's own record is always included**, because `EmployeePolicy::viewTab()` grants it
> before any role check. A supervisor nobody reports to therefore sees exactly one row —
> themselves — and that is `adr/0011` decision 4 in plain sight, not a defect.
>
> **The rule now exists in two forms** — this scope and `EmployeePolicy::view()` — and
> `EmployeeListVisibilityTest` compares their outcomes across a population rather than
> comparing code. It compares against `view()`, never `viewTab()`, which is what removes the
> proxy-tab problem `adr/0011` deferred the guard over. **Its four stated limits are on its
> face**, including the one that matters: agreement is not correctness.

**Screen decisions taken 2026-08-15, with the list:**

- **Six columns**: `employee_no`, `full_name`, company, department, position, `staff_status`.
  ⚠ **None of `adr/0013`'s twelve identity or statutory columns appear.** Those are the
  Personal tab's, behind a per-tab check (§6.2) — a list identifies people, it does not
  display their identity.
- **Default sort `full_name` ascending** — HR looks people up by name.
- **25 rows a page.**
- **Search is a substring match** (`%term%`), ORed across the four fields, one box.
  ⚠ A leading wildcard **cannot use an index**; there is no index on `full_name` and the scale
  is hundreds of rows. **At a much larger scale this is a decision to revisit.**
- **The company filter is hidden when the account reads one company**, so a group-scoped and a
  subsidiary-scoped account see **different forms**. A select with one option is a control that
  cannot change the answer. Do not unify them.
- **No per-row link yet** — the detail screen does not exist, and a row linking to a 404 is
  worse than a row that does not link.

### 5.5 Legacy import

One-off, idempotent, re-runnable command. Matches on `employee_no`. Writes an import
report of unmatched/ambiguous rows rather than guessing. Company names normalized against
the canonical table in `CLAUDE.md` §5 — the legacy data contains three spellings of
ES SOFEEYA ENTERPRISE and the importer must reject unknown spellings loudly, not silently
create a new company.

> **⚠ BLOCKED — recorded 2026-08-13. The five sentences above state intent, not a
> mechanism, and the missing half cannot be designed from here.**
>
> **The legacy data file does not exist in this repository and has never been seen.** No
> `.csv`, `.xlsx`, `.sql` or dump exists anywhere reachable. Only three facts about the old
> data are recorded anywhere, and all three describe what was *wrong* rather than what the
> columns are: working days and hours held as free text (`"ISNIN - SABTU"`,
> `"9.00 AM - 5.00 PM"`), three spellings of one company across three files, and two-tier
> reporting taken from the legacy Staff Master template.
>
> **Source format, column mapping and table scope are therefore unknown**, and deciding them
> against an assumed shape is exactly the pattern `CLAUDE.md` §10 already records against
> NGTime — structure confirmed from a sample, full export never reviewed. Do not repeat it
> deliberately.

#### The four decisions that block any code

1. **Source format and column mapping.** Needs the file. Nothing else will do.
2. **Unimportable rows** — no phone number, no department, unparseable working hours.
   **Reject the row, or reject the whole run?** §5.5's *"rather than guessing"* argues for
   loudness; it does not say which.
3. **Transaction boundary**, and what *"re-runnable"* means precisely.
4. **Scope.** `employees` only, or the child tables too? Are accounts and activation tokens
   created? Are `employee_roles` imported? Is legacy history written into the ledger?

#### ⚠ Two contradictions inside §5.5's own words

**"Idempotent, re-runnable" contradicts the append-only ledger.** If the importer writes
`employee_status_history` rows for legacy history, those rows **cannot be updated and cannot
be deleted** — enforced on the model, by design (`adr/0003` decision 8). A second run must
either duplicate them or skip them, and there is no third mechanism. **This is a
contradiction to be decided, not a detail to be handled in code.**

**"Re-runnable" pulls against atomicity.** Re-runnable implies per-row commits, so a second
run continues where the first stopped — but per-row commits leave a half-populated system
**with no marker saying so**. All-or-nothing means one bad row in two hundred and fifty
aborts everything, which suits *"rather than guessing"* but makes the import report nearly
useless: nothing was imported to report against.

#### What every enforcement built since will do to this data

Not theoretical. Each of these is already enforced and will meet the legacy rows at once:

| Rule | Consequence for a legacy row |
|---|---|
| `users.phone_no` NOT NULL, unique, 9–12 digits, **no placeholder** | An employee with no usable number **cannot be created at all** (BR-A1, and `schema.md` says so explicitly). Two employees sharing a number: the second fails |
| `work_start_time` / `work_end_time` NOT NULL `TIME` | `"9.00 AM - 5.00 PM"` must be parsed into two columns with no null path |
| `working_days` / `offday` JSON | `"ISNIN - SABTU"` must be parsed into `["MON", …]`; ambiguity is guaranteed |
| `employee_no` from the locked `sequences` row | `CreateEmployee` **discards** any supplied number, so the importer cannot use it — its own comments say so twice. And **nothing advances the counter past imported numbers**, so the next new hire collides with the group-wide unique index |
| `department_id` NOT NULL | The legacy system added `department_id` late, with a SQL backfill (`CLAUDE.md` §9) — rows existed without one |
| BR-A20: every employee holds an account | Importing *n* employees mints *n* activation tokens with a **48-hour** validity, which expire before anybody distributes them |
| `AuthorshipObserver` (`adr/0009`) | The importer **must** enter `AuthorshipContext`; without it the first insert throws. `created_by` will name whoever ran the import, never who created the record in the old system — honest, but lossy, and it must be said out loud |
| BR-16 hook | Needed only if legacy data carries `ACCOUNT`, `HR`, `ASSISTANT_DIRECTOR` or `HOD`. ⚠ Entering `RestrictedRoleContext` does **not** waive `employee_roles.assigned_by` being NOT NULL |

**The first row and `assigned_by` are not design problems. They are questions for the
client**, and they are recorded as such in `CLAUDE.md` §10.

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

> **⚠ Superseded by `adr/0010`** — a transfer now writes an `EMPLOYER` ledger row, and §5.3
> already forced a `DEPARTMENT` row before that. The full amendment lands with the
> implementation PR; this pointer exists so the sentences above are not read as current in the
> meantime.
>
> Both paragraphs are affected: the *"must not touch `employee_status_history`"* rule, and the
> demand for an ADR before a fifth `change_type` — which `adr/0010` discharges.

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
| View employees who report to them | `SUPERVISOR`, `MANAGER`, `HOD` — `direct_supervisor_id` **or** `manager_id` names them (BR-8), **and own `company_id`** |
| View all in **read scope** | `HR`, `ASSISTANT_DIRECTOR` — scope **derived from the employer's hierarchy position**, see below |
| Create / edit / archive | `HR`, `ASSISTANT_DIRECTOR` — within their read scope. `phone_no` is not on this record at all (§6.4) |
| Grant / revoke `MANAGER`, `SUPERVISOR` | `HR` — within their read scope |
| Grant / revoke `ACCOUNT`, `HR`, `ASSISTANT_DIRECTOR`, `HOD` | **Master Admin only** (BR-16) |
| Create / deactivate `job_functions` types | **Master Admin only** (BR-15) |
| Assign `job_functions` to an employee | `HR` — within their read scope |
| Edit `employee_no` | **Master Admin only**, audited (BR-13) |
| Transfer employee between companies | `HR` **or** Master Admin — either, directly; always audited with the actor's identity (§5.7) |
| Cross-tenant view | `system_access = FULL` (Master Admin) — explicit, audited |

> **The supervisory row was rewritten 2026-08-15 (`adr/0011` decision 1), and it previously
> read *"own department and own `company_id`"*.** Department equality was borrowed from BR-10,
> a rule about **approval authority**, and it answered a different question from the one
> `adr/0004` decision 8 asks: it is symmetric where supervision is not, granting colleagues who
> report to nobody here and refusing this actor's own subordinates sitting elsewhere.
>
> **Approval routing is untouched.** BR-10 and `adr/0002` decision 4 still resolve
> `(department, company) → HOD` from the role row, and the two axes now say visibly different
> things — which is `adr/0002` decision 5 working rather than failing.
>
> **The rule is enforced in two places and both are tested**: `EmployeePolicy::viewTab()` per
> record, and `Employee::scopeVisibleTo()` for the list (§5.4). Every other row of this table
> is unchanged.
>
> **⚠ Opening the LIST is a separate question from seeing anybody in it** —
> `EmployeePolicy::viewAny()`. Every account can see its own record, so "can see at least one
> employee" is true for everybody and would put the screen in front of a clerk whose list is
> one row long. The gate is **any authority role, or a `system_access` other than STANDARD**.

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
| Roles & Functions | No | Yes | Yes | Own |
| Status History | No | Yes | Yes | Own |

> **⚠ The Personal row was tiered by field on 2026-08-17 — `adr/0014`.** The row still
> reads **Yes**, and it is still Yes: the supervisory tier opens the tab. What it now
> opens is **four fields** — `full_name`, `nickname`, `email` and `users.phone_no` — and
> not the twelve identity and statutory columns `adr/0013` put behind it.
>
> **The matrix is per tab and this one row is no longer only that**, which is the whole
> of the change. Every other row answers Yes or No and stops; the Personal row answers
> Yes *and then a field list*, resolved by `EmployeePolicy::personalFieldsFor()` — from
> which `viewTab(TAB_PERSONAL)` is **derived**, not written a second time.
>
> **The code does not say this yet.** `EmployeePolicy::SUPERVISORY_TABS` still grants
> Employment and Personal without qualification and no second method exists. The full
> amendment lands with the UI-2 PR.

> **The Roles & Functions row was decided 2026-08-13** — `adr/0004` decision 8's table named
> seven tabs while §7 lists eight, so this one had never been decided at all, and the code
> answered `false` **silently**. See that decision's amendment note.
>
> **⚠ The question is not "may a supervisor see who holds what authority?" — they already
> can.** §7 puts the BR-12 cross-company line on the **Employment** tab, which supervisors
> read:
>
> > **Employer (payroll):** AHS · **Also serving at:** AHS — BDO, Account · AIM — Manager, …
>
> That line stays, and hiding it would mean deleting something §7 specifies with its own
> reasoning. Supervision needs *who holds what authority **today***, and that is exactly what
> the line gives.
>
> **This tab is not the long version of that line.** It adds three things the line does not
> have, and each lands a supervisor outside a boundary already drawn elsewhere:
>
> | It adds | Why that is not a supervisor's |
> |---|---|
> | **Revoked** roles — history | `adr/0004` decision 8 already answers **No** for supervisors on every history tab: Employment History and Status History both. This row follows that line rather than drawing a new one |
> | **Job functions** | What a person does, per company — not a supervision input, and BR-15 keeps the vocabulary under Master Admin precisely because it is administrative |
> | **Grant / revoke controls** | `EmployeePolicy::grantRole()` already refuses supervisors. **Rendering a button the policy will reject is a UI that lies**, and §5.6 is explicit that hiding a control is presentation while the authorisation is the rule |

Supervisors, Managers and HODs read **within their own department and their own company** —
the existing double bound (BR-10, `adr/0002` decision 4) is unchanged by any of this.

> **⚠ The sentence directly above was superseded by `adr/0011` and is kept only as the record
> of what changed — amended 2026-08-15.** The department half is replaced by the **reporting
> line** — `direct_supervisor_id` or `manager_id` pointing at the actor (BR-8) — while the
> company half stands exactly as written. It is one level, not a traversal, and an employee
> with both columns empty is read by nobody below `HR`.
>
> **The tab matrix above it is unaffected**: `adr/0011` changes *which employees* a supervisor
> may open, never *which tabs*. That distinction is also why the list guard compares against
> `EmployeePolicy::view()` rather than `viewTab()` — a list answers the *which employees*
> question and no tab question at all (§5.4).

**Why Employment and Personal, and nothing else.** A supervisor needs to know *who reports
to me* and *how do I reach them*. They do not need a copy of someone's IC, their spouse's
identity card number, or where they went to school — none of it bears on supervision.
Restricting them to Employment alone was rejected as too tight: a supervisor who cannot
find a phone number in the system will find it on WhatsApp instead, and the organisation
loses the control entirely.

> **⚠ This paragraph is unchanged, and since 2026-08-17 it carries a second load —
> `adr/0014`.** It was written when the Personal tab held `full_name`, `nickname` and
> `email`. `adr/0013` then placed twelve identity and statutory columns behind that tab,
> and the sentence above — *they do not need a copy of someone's IC* — became a
> description of what the tab actually contains.
>
> **The argument is not overturned; it is applied.** `adr/0014` reads it as the
> definition of the supervisory **field set**, not only of the tab list: the tier keeps
> the tab and reads the four fields that answer *how do I reach them*. Nothing here is
> rewritten, because nothing here was wrong.

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

> **⚠ This section defines READING only, and its silence about writing is now deliberate
> rather than accidental — `adr/0012`, 2026-08-14.**
>
> Every verb above is a read verb. Who may **upload** a document, who may **replace** or
> **delete** one, and who may put anything into `OTHER` were undecided here, and `adr/0012`
> decides them: `HR` and Master Admin upload directly, an employee **submits** to a separate
> table and never names a `type`, HR sets the `type` at acceptance because `type` drives the
> read rule above, and a file is served by a **controller that asks
> `EmployeePolicy::viewDocument()`** rather than by a signed URL.
>
> **The reading rule on this page is unchanged** — `adr/0012` decides writing, and
> `adr/0004` decision 9 still governs which of the seven types an employee may retrieve.
>
> The full amendment lands with the Documents tab implementation PR (`adr/0012`
> decision 11); this pointer exists so the section is not read as saying that writing is
> unrestricted in the meantime.

### 6.4 There is no `phone_no` on the employee record — for anyone

> **⚠ Rewritten 2026-08-12 — `adr/0006`.** This section previously said the employee form
> *displays* `phone_no` read-only. It no longer displays it, because **the column is not on
> `employees`**: the login username lives on `users`, since Master Admin has no employee
> record and had nowhere to keep one (`adr/0001` decision 4). The reasoning below is the
> reasoning that moved it, and is kept because it is what makes the absence make sense.
>
> **⚠ There is no separate contact number either, and none may be added**
> (`adr/0006` decision 7). The employee's personal number **is** their login username — one
> number, one meaning — so a `contact_no` beside it would be the same fact written twice,
> and the copy goes stale the first time someone changes one and not the other.
>
> **Changing a personal number therefore changes a login.** HR does it from the account
> management screen (`auth-rbac.spec.md` §7), where it sits beside password reset and
> unlock, because it is the same kind of operation: a credential change, not a profile edit.
> *"HR needs to reach people"* is already met — the login number **is** the number HR
> reaches them on.
>
> A genuinely different fact — a next-of-kin number, a company-issued handset — would be a
> different column with its own name and its own reasoning, not a second copy of this one.
> `employee_family_members.is_emergency_contact` already covers the first.

**Changing the number is an account operation, not a profile edit.** It is done from the account
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

> **⚠ The last sentence above is superseded by `adr/0011`** — the third place this spec states
> the department bound, and the one most likely to be read by someone checking this rule,
> since the paragraph around it is about the two axes. The bound is still double; the
> department half becomes the **reporting line** (`direct_supervisor_id` or `manager_id`
> pointing at the actor, BR-8), and the own-company half stands exactly as written — a shared
> department containing other companies' staff is still why it exists.
>
> **`EmployeePolicy::viewTab()` implements this today**; the full amendment to this sentence,
> to §6 and to §6.2 lands with the list-narrowing PR.

**`ACCOUNT` grants nothing in this module.** It is the only role that may read salary
(BR-9), but Employee Master holds no salary data (§10 decision 3), so `ACCOUNT` confers no
Employee Master permission whatsoever — it appears in no row above except as a role that
may be *granted*. Do not anticipate the Payroll check here.

> **⚠ Read that sentence as scoped to §6's ACTION matrix, because §6.2's tab matrix says
> something different and both are correct — recorded 2026-08-13.**
>
> | Question | Answer for `ACCOUNT` |
> |---|---|
> | May it create, edit, archive, grant, transfer? (§6) | **Nothing.** It holds no row in that table |
> | May it read the employee detail tabs? (§6.2) | **Every tab, within its read scope** |
>
> `adr/0004` decision 8 groups **"HR / Asst Director / Account"** as reading every tab, and
> that is deliberate: `ACCOUNT` runs payroll and cannot do it blind. Reading and writing are
> two different questions, and this section answers only the second.
>
> Without this note the two sections read as a contradiction, and whoever resolves it in a
> hurry will resolve it by narrowing the ADR — which would leave payroll unable to see the
> records it pays. `App\Policies\EmployeePolicy` implements both: `ACCOUNT` sits in
> `ADMINISTRATIVE_ROLES` for reads and in none of the write paths.

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
**Roles & Functions**, Status History), create/edit form, ~~archive confirmation~~.

> **⚠ "Archive confirmation" is residue of a model this project rejected — struck 2026-08-18.**
> It described a world where ending an employment removed the record from view. **It does not:
> an employee record stays in the list after they leave, and there is no separate "archive"
> act** — see §5.2, which records that no application path touches `deleted_at` at all. The
> screen it names must not be built. Left struck rather than deleted so a reader who remembers
> it finds this note instead of rebuilding it.

### 7.1 What each tab displays

**The record header sits outside the tabs**, because it identifies the record rather than
describing it: `employee_no`, `full_name`, and the current `staff_status`. Anyone who may
open the record at all sees it — the list already shows all three to the same readers
(§5.4).

**Employment.** Org assignment (`branch`, `department`, `position`, `level`), employment
terms (`employment_type`, `staff_status`, `join_date`, `probation_end_date`,
`confirmation_date`), the working pattern (`attendance_type`, `work_start_time`,
`work_end_time`, `ot_after_time`, `working_days`, `offday`, `hours_enabled`),
`fingerprint_id`, the two BR-8 reporting columns (`direct_supervisor_id`, `manager_id`,
shown as names), and the rejoiner link where `previous_employee_id` is set (BR-13). The
**emergency contact — name and number only** — is surfaced here rather than behind Family,
which is §6.2's deliberate exception. The payroll employer and the derived BR-12
cross-company line are specified below. **Both `adr/0013` flags render on this tab** — an
expired permit, and a `CONFIRMED` employee missing an EPF **or** SOCSO number (`adr/0013`
decisions 4 and 5, `adr/0014`). The flags state that a gap exists; the numbers themselves
are Personal-tab data and are not shown here.

**Personal.** ⚠ **This tab is tiered by field, and the two sets are not the same screen
with things greyed out — see `adr/0014` decision 1.** The supervisory tier reads **four
fields**: `full_name`, `nickname`, `email`, and `users.phone_no` (read-only, through
`Employee::user()` — the employee record holds no number of its own, `adr/0006`). The
administrative tier, `FULL`, `VIEW_ONLY`, and the employee on their own record read those
four **plus** the twelve identity and statutory fields `adr/0013` added: `ic_no`,
`passport_no`, `permit_expiry`, `date_of_birth`, `gender`, `nationality`, `address`,
`epf_no`, `socso_no`, `tax_no`, `bank_name`, `bank_account_no`. The field list is resolved
by `EmployeePolicy::personalFieldsFor()`, and `viewTab()`'s answer for this tab is derived
from it rather than written twice.

**Family.** Every `employee_family_members` row: `relationship`, `name`, `contact_no`, and
which row carries `is_emergency_contact`. Nothing further — the emergency contact's name
and number also appear on Employment, and that duplication is the point of §6.2's
exception rather than an oversight.

**Education.** Every `employee_education_history` row: `level`, `institution`, `year`.

**Employment History.** Every `employee_employment_history` row: `company_name`,
`position`, `start_date`, `end_date`. ⚠ **This is employment before this group, not
movement between its entities.** A company transfer is `employee_status_history` and
appears on the Status History tab; a reader who confuses the two will read a transfer as a
resignation.

**Documents.** ⚠ **Present as a tab, and empty.** It states that the document path is not
built rather than listing files nothing can open. `adr/0012` decision 11 binds the serving
controller, the routes, the FormRequests, the Actions and the submissions table to the PR
that builds this tab, and that PR is not the one that builds the other seven. **No `PHOTO`
is rendered anywhere on this screen, including Personal** — a photo is a file (`adr/0013`
decision 7) and displaying one pulls in the same serving path.

**Roles & Functions.** Current authority grouped by company — `role`, `effective_date`,
and who granted it — with revoked rows available but visually separated, carrying
`revoked_date` and who revoked them; and the job functions this person performs, per
company. **Read-only.** Grant and revoke controls are not on this screen at all: §5.6 is
explicit that hiding a control is presentation while the authorisation is the rule, and a
control that was never rendered cannot be one the policy has to reject. **Who may grant or
revoke, per role and per company, is §6's matrix** — this tab neither restates it nor
depends on it, and the controls land with the create/edit screens.

**Status History.** The merged chronological timeline specified below — each entry
carrying its date, its label, its source, the company it happened at, and who made it.
**Read-only**, reinforcing §5.3 at the interface level.

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
