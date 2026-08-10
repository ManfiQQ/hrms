# Module Spec — Employee Master

- **Phase:** 1 — Employee & Org
- **Status:** Draft — awaiting approval. **No code until this is approved** (`CLAUDE.md`
  Principle #1).
- **Branch:** `feat/employee-master`
- **Depends on:** `companies`, `branches`, `departments`, `positions`, `users` (Phase 0);
  `adr/0001` (taxonomy — resolved), `adr/0002` (shared org structure — resolved)
- **Date:** 2026-08-07 — approval-scope rules corrected 2026-08-08 (BR-10, BR-14)

---

## 1. Purpose

The single authoritative record of a person's employment with the group. Every later
module reads from it: Attendance matches punches to it via `fingerprint_id`, Leave
computes entitlement from `join_date` and `staff_status`, Payroll reads salary and
statutory config against it, Approval routes on `core_role` and department, Org
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

- `employees` — the master record.
- `employee_family_members`, `employee_education_history`,
  `employee_employment_history`, `employee_documents` — child records, each with
  `company_id` at creation per `conventions.md` §3.
- `employee_status_history` — append-only ledger; see §5.3.

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
- Six migrations will be generated in this module — verify with
  `ls database/migrations | sort` that no two share a timestamp before committing
  (`conventions.md` §6; the legacy system shipped three colliding timestamps).
- `schema.md` updated in the **same commit** as each migration (`CLAUDE.md` Principle #5).

**Indexes**

- `employees`: unique on `employee_no` — **group-wide, not composite with `company_id`**
  (§10 decision 1); unique on `fingerprint_id` (nullable — unique index must permit
  multiple nulls); composite index on `(company_id, staff_status)` for the default list
  query; index on `department_id`, `direct_supervisor_id`, `manager_id`.
- `branches`, `departments`: index on `company_id` — the column is nullable, so the index
  must serve `IS NULL` lookups as well as equality.
- Child tables: index on `(company_id, employee_id)`.

**Enums** — final per `adr/0001`, no longer pending:

- `core_role`: `STAFF, SUPERVISOR, MANAGER, HOD, HR, ASSISTANT_DIRECTOR` — authority
  only, six values. `MASTER_ADMIN` and `DIRECTOR` are deliberately absent (`adr/0001`
  decisions 2 and 7).
- `level`: `STAFF, SUPERVISOR, MANAGER, HOD` — display only.

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

**BR-9 — Authority vs display.** `core_role` drives approval and RBAC; `level` drives
org display. They may legitimately differ for the same person. No code may read `level`
for an authorization decision (`adr/0001`).

**BR-10 — HOD assignment is per department, optional, and same-company in authority.** A
department may or may not have an assigned HOD, and this varies between departments
within one company. Employee Master stores the `HOD` role on the employee; the
*department → HOD* resolution consumed by approval routing must be queryable dynamically
(`adr/0001` decision 3).

An HOD's approval authority is **strictly same-company** — it covers only employees
sharing the HOD's own `employees.company_id`, **even inside a shared department or
branch** (`adr/0002` decision 4). Consequences for this module:

- The resolution Employee Master must support is **(department, company) → HOD**, not
  department → HOD. A shared department may legitimately hold **more than one** `HOD`
  employee, one per company represented in it — the data model must not assume at most
  one HOD per department, and no validation may reject the second.
- Where a department's only HOD belongs to a different company than an employee in it,
  that employee simply has no HOD stage and falls back to the standard chain. This is a
  correct configuration, not an error state to flag.

**BR-14 — Cross-company approval is `HR` / `ASSISTANT_DIRECTOR` only, and grants no
visibility.** These two `core_role` values are the only ones whose approval authority
crosses `company_id`; `STAFF`, `SUPERVISOR`, `MANAGER` and `HOD` are all confined to their
own company. Approving a cross-company request **does not** confer read access to that
employee's sensitive data — a separate visibility permission check governs that, and it
belongs to the Auth & RBAC spec, which does not exist yet (`adr/0002` decision 5). Employee
Master must not implement, imply, or anticipate that check; §6's permission table stays
company-scoped as written.

**Salary is the one part already answered:** only the `ACCOUNT` role may read salary data,
and no `HR` may (`adr/0003` decision 5). The rest of the check — personal documents, family
records, disciplinary history, full leave history — remains undefined.

**BR-12 — Org assignment is independent of employing company.** `employees.company_id`
(NOT NULL) is the payroll/legal employer. `branch_id` and `department_id` may point at
shared org units (`company_id IS NULL`) or at units belonging to a different company.
**Validation must not require them to match** — an employee of TURSENIA in the shared
Logistics branch is a correct record. Sensitive data stays scoped to
`employees.company_id` regardless of org placement. See `adr/0002` decisions 2–3.

**BR-13 — `employee_no` is group-wide.** Format `AHS-0001`, sequential and zero-padded,
**always the `AHS` prefix regardless of employing subsidiary**. Generation must draw from
a single group-wide sequence — a per-company counter would produce collisions against the
group-wide unique index. See §10 decision 1.

**BR-11 — Master Admin has no employee record.** No Employee row may be created for a
Master Admin account. This is **enforced structurally, not by assertion**: `core_role`
has no `MASTER_ADMIN` value (`adr/0001` decision 2), so no employee row can express
Master Admin authority in the first place. Master Admin is identified solely by
`users.is_master_admin` with a null `employee_id`.

The migration must therefore define `core_role` with exactly the six values — adding
`MASTER_ADMIN` "for completeness" would reintroduce the hole this closes.

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
never edited or deleted; a correction is a new row. Also mirrored to `audit_logs`
(Phase 0).

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

## 6. Permissions

Read from `core_role` only.

| Action | Who |
|---|---|
| View own record | any employee |
| View department employees | `SUPERVISOR`, `MANAGER`, `HOD` — own department **and own `company_id`** |
| View all in company | `HR`, `ASSISTANT_DIRECTOR` — **own company only** |
| Create / edit / archive | `HR`, `ASSISTANT_DIRECTOR` — own company only |
| Cross-tenant view | Master Admin only, explicit, audited |

**Approval authority is not on this table, and that is deliberate.** `HR` and
`ASSISTANT_DIRECTOR` may *approve* across companies (BR-14) — that grants them no read
access here, so "View all in company" stays company-scoped. An HOD's read access is
bounded twice over: own department **and** own company, since a shared department can
contain other companies' staff (BR-10). The separate visibility check that would ever
widen any of this is an Auth & RBAC concern and is not yet defined; Employee Master
implements the table above as written.

## 7. UI

Blade + Livewire 3. Screens: employee list (search/filter/paginate), employee detail
(tabbed — Employment, Personal, Family, Education, Employment History, Documents,
Status History), create/edit form, archive confirmation.

Status History tab is **read-only** in the UI — reinforcing §5.3 at the interface level.

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
3. Status history row written on every qualifying change; none written on a no-op save.
4. Status history rows cannot be updated or deleted.
5. Status transitions — permitted ones succeed, forbidden ones rejected; terminal
   statuses stay terminal.
6. `BR-11` — the `core_role` enum contains exactly the six permitted values and
   **rejects `MASTER_ADMIN`** at the database level (a guard against a future migration
   quietly re-adding it, since this rule is structural and has no runtime check behind
   it); and a Master Admin user has null `employee_id`.
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

## 9. Definition of Done

The full `conventions.md` §10 checklist — `optimize:clear`, syntax check, `route:list`,
`php artisan test`, `npm run build`, migration test against an **empty** database, and
the sensitive-file check (no `.env`, no employee documents, no salary files staged).

Plus, specific to this module: `schema.md` updated in the same commit as each migration,
and no migration timestamp collisions.

## 10. Resolved Decisions

All five questions that previously blocked approval of this spec are **closed**. Recorded
here with their answers so the reasoning survives.

**1. `employee_no` format — RESOLVED.** Group-wide unique, format `AHS-0001`: `AHS`
prefix + sequential zero-padded number, single group-wide sequence.

⚠ The prefix is **always `AHS`** — the parent company — **regardless of which subsidiary
employs the person**. An AIM employee is `AHS-0042`, not `AIM-0042`. This is
counterintuitive enough to be "corrected" by mistake, so: it is intentional. The unique
index stays group-wide, not composite with `company_id`.

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

**Not blocking this module** — all Auth & RBAC concerns, which Employee Master does not
depend on:

- the `system_access` value-set question (`CLAUDE.md` §10);
- the **data-visibility permission check** that must sit separate from cross-company
  approval authority (BR-14) — for everything except salary.

Salary visibility is **no longer open**: it is the `ACCOUNT` role, and no `HR` holds it
(`adr/0003` decision 5). The `hr_scope` (`PAYROLL | OPERATIONS`) distinction previously
listed here is **withdrawn**, not deferred — the Payroll HR / Operations HR split it
modeled does not exist.
