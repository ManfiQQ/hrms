# Module Spec — Employee Master

- **Phase:** 1 — Employee & Org
- **Status:** Draft — awaiting approval. **No code until this is approved** (`CLAUDE.md`
  Principle #1).
- **Branch:** `feat/employee-master`
- **Depends on:** `companies`, `branches`, `departments`, `positions`, `users` (Phase 0);
  `adr/0001` (taxonomy — resolved)
- **Date:** 2026-08-07

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
  (`CLAUDE.md` Principle #4).
- Tenant global scope applied on every model, bypassable only from an explicit Master
  Admin context.
- Six migrations will be generated in this module — verify with
  `ls database/migrations | sort` that no two share a timestamp before committing
  (`conventions.md` §6; the legacy system shipped three colliding timestamps).
- `schema.md` updated in the **same commit** as each migration (`CLAUDE.md` Principle #5).

**Indexes**

- `employees`: unique on `employee_no`; unique on `fingerprint_id` (nullable — unique
  index must permit multiple nulls); composite index on
  `(company_id, staff_status)` for the default list query; index on `department_id`,
  `direct_supervisor_id`, `manager_id`.
- Child tables: index on `(company_id, employee_id)`.

**Enums** — final per `adr/0001`, no longer pending:

- `core_role`: `STAFF, SUPERVISOR, MANAGER, HOD, HR, ASSISTANT_DIRECTOR, MASTER_ADMIN`
  — authority only.
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

**BR-10 — HOD assignment is per department, optional.** A department may or may not have
an assigned HOD, and this varies between departments within one company. Employee Master
stores the `HOD` role on the employee; the *department → HOD* resolution consumed by
approval routing must be queryable dynamically (`adr/0001` decision 3).

**BR-11 — Master Admin has no employee record.** No Employee row may be created for a
Master Admin account, and `employees.core_role` must never be set to `MASTER_ADMIN`.
Assert as a test-enforced invariant (`schema.md` § `employees`, `adr/0001` decision 4).

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
ESSOFEEYA ENTERPRISE and the importer must reject unknown spellings loudly, not silently
create a new company.

## 6. Permissions

Read from `core_role` only.

| Action | Who |
|---|---|
| View own record | any employee |
| View department employees | `SUPERVISOR`, `MANAGER`, `HOD` (own department) |
| View all in company | `HR`, `ASSISTANT_DIRECTOR` |
| Create / edit / archive | `HR`, `ASSISTANT_DIRECTOR` |
| Cross-tenant view | Master Admin only, explicit, audited |

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
3. Status history row written on every qualifying change; none written on a no-op save.
4. Status history rows cannot be updated or deleted.
5. Status transitions — permitted ones succeed, forbidden ones rejected; terminal
   statuses stay terminal.
6. `BR-11` invariant — no employee row can be created with `core_role = MASTER_ADMIN`;
   a Master Admin user has null `employee_id`.
7. Supervisor/manager self-reference and cycle rejection (BR-8).
8. `working_days` / times persist and cast as structured values, not strings (BR-5).
9. Soft delete hides from list, retains child records.
10. Importer idempotency — re-running produces no duplicates; unknown company spelling
    is rejected, not auto-created.

## 9. Definition of Done

The full `conventions.md` §10 checklist — `optimize:clear`, syntax check, `route:list`,
`php artisan test`, `npm run build`, migration test against an **empty** database, and
the sensitive-file check (no `.env`, no employee documents, no salary files staged).

Plus, specific to this module: `schema.md` updated in the same commit as each migration,
and no migration timestamp collisions.

## 10. Open Questions — resolve before approval

1. **`employee_no` format.** Is it group-wide unique or per-company? Is there a required
   prefix per entity (e.g. `AHS-0001`)? The schema currently says globally unique — needs
   confirmation, since per-company numbering would change the unique index.
2. **`fingerprint_id` source of truth.** Assigned by the NGTime device or by HR? What
   happens when an employee's device enrolment changes — new value on the same record, or
   history required? Affects Phase 2 matching.
3. **Salary fields.** Deliberately excluded here (Phase 2 Payroll). Confirm HR does not
   need a basic-salary field visible on the employee record before then — if they do, it
   changes the permission model, since salary is more sensitive than the rest of the
   record.
4. **Document types.** Fixed enum (IC, passport, certificate, offer letter, …) or free
   text? Fixed is preferred per `conventions.md` §4; needs the actual list from HR.
5. **Departments spanning branches.** `departments` has a nullable `branch_id`. Confirm
   whether one department can span multiple branches — affects BR-10's department → HOD
   resolution.

**Not blocking this module:** the remaining `system_access` value-set question
(`CLAUDE.md` §10) belongs to the Auth & RBAC spec, which Employee Master does not depend
on. The HR ↔ Assistant Director approval routing question that previously sat here is
**resolved** — peer approval, `adr/0001` decision 6.
