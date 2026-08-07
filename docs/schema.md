# Schema

> Living document. Update this file in the same commit as any migration —
> see `CLAUDE.md` Principle #5. This is a pre-implementation draft covering
> Phase 0 and Phase 1. Phase 2+ tables are added as those modules are speced.

---

## Status

Draft — pre-implementation. No migrations have been written yet.

---

## Core / Company & Org (Phase 0)

### `companies`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string, unique | Canonical spelling only — see `CLAUDE.md` §5 |
| code | string, unique | e.g. `AHS`, `AIM HQ`, `ZISH`, `THALHAH`, `TURSENIA`, `ESSOFEEYA` |
| parent_company_id | FK → companies, nullable, self-referencing | **Fixes legacy design.** The old system stored the parent company as a free-text string (`main_company`) repeated on every row. This is a proper hierarchy instead. |
| status | enum: ACTIVE, INACTIVE | |
| timestamps, soft deletes | | |

### `branches`
`id`, `company_id` (FK), `name`, `address`, timestamps

### `departments`
`id`, `company_id` (FK), `branch_id` (FK, nullable), `name`, timestamps

### `positions`
`id`, `department_id` (FK), `title`, timestamps

---

## Employee Master (Phase 1)

### `employees`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_no | string, unique | |
| full_name, nickname, phone_no, email | string, nullable | |
| company_id, branch_id, department_id, position_id | FK | Scoped from creation |
| fingerprint_id | string, unique, nullable | Matches NGTime attendance export ID |
| core_role | enum: STAFF, SUPERVISOR, MANAGER, HOD, HR, ASSISTANT_DIRECTOR, MASTER_ADMIN | **Authority field** — the only field consulted for approval routing and RBAC. See `business-rules.md` § Approval Hierarchy and `adr/0001`. `HOD` added (missing from legacy). ⚠ `MASTER_ADMIN` can never legitimately appear on an employee row — Master Admin accounts have no Employee record (see `users` below). Value retained so one authority taxonomy lives in one enum; assert the invariant in tests. |
| level | enum: STAFF, SUPERVISOR, MANAGER, HOD | **Display field only** — org chart, directory grouping, seniority tier. Never drives an authorization or routing decision. `ADMIN` deliberately excluded: it conflated a system permission with an org-seniority tier — see `adr/0001`. |
| employment_type | enum: FULL-TIME, PART-TIME, CONTRACT, INTERN, FREELANCE | |
| staff_status | enum: PROBATION, ACTIVE, CONFIRMED, SUSPENDED, RESIGNED, TERMINATED | |
| join_date, probation_end_date, confirmation_date | date, nullable | |
| direct_supervisor_id, manager_id | FK → employees, self-referencing, nullable | Two-tier reporting confirmed from legacy Staff Master template |
| attendance_type | enum: FIXED, FLEXIBLE | FIXED = late after configured start time; FLEXIBLE = OT applied manually |
| work_start_time, work_end_time, ot_after_time | TIME | **Fixes legacy design** — old system stored these as free-text strings |
| working_days | JSON array | e.g. `["MON","TUE","WED","THU","FRI","SAT"]`. **Fixes legacy design** — old system stored `"ISNIN - SABTU"` as a string |
| offday | JSON array | |
| hours_enabled | boolean | Whether Saturday accumulated-hours banking applies to this employee |
| created_by, updated_by | FK → users, nullable | |
| timestamps, soft deletes | | |

> **All child tables below carry `company_id` at creation**, per `conventions.md` §3 —
> they are business tables, not reference/lookup tables. `company_id` is denormalized
> from the parent employee so the tenant global scope applies uniformly and a
> compromised or mistaken `employee_id` cannot leak rows across tenants. An earlier
> draft of this file omitted it; that omission contradicted `conventions.md` §3 and is
> corrected here.

### `employee_family_members`
`id`, `company_id` (FK), `employee_id` (FK), `relationship`, `name`, `contact_no`,
`is_emergency_contact` (boolean), `created_by`, `updated_by`, timestamps, soft deletes

### `employee_education_history`
`id`, `company_id` (FK), `employee_id` (FK), `level` (SPM/Diploma/Degree/etc.),
`institution`, `year`, `created_by`, `updated_by`, timestamps, soft deletes

### `employee_employment_history`
`id`, `company_id` (FK), `employee_id` (FK), `company_name`, `position`, `start_date`,
`end_date` (nullable), `created_by`, `updated_by`, timestamps, soft deletes

### `employee_status_history`
`id`, `company_id` (FK), `employee_id` (FK), `status`, `effective_date`, `reason`,
`changed_by` (FK → users), `created_at`

**Append-only ledger — deliberate exception to `conventions.md` §3.** No `updated_by`,
no soft deletes, no `updated_at`: rows are never edited or deleted, only inserted. A
correction is a new row, not an edit. Mutability would defeat the point of the table.

> Every employment status / grade / position change is a **new row**, never an
> overwrite of the current record — required to answer "when did this employee move
> from Grade C to D," which the legacy system's flat-field design could not do.

### `employee_documents`
`id`, `company_id` (FK), `employee_id` (FK), `type`, `file_path`, `uploaded_by`
(FK → users), `created_by`, `updated_by`, timestamps, soft deletes

---

## Core Engine Tables (Phase 0)

### `approval_requests`
`id`, `requestable_type`, `requestable_id` (polymorphic), `requested_by`, `current_stage`,
`status`, timestamps

Routing logic sourced from the legacy system's `AGENTS.md` (a genuinely well-designed
part of it) — see `business-rules.md` § Approval Hierarchy.

> **Routing must resolve the HOD chain dynamically, per department.** An HOD is optional
> per department — some departments have one, some don't, and it varies between
> departments *within the same company*. The stage order therefore **cannot** be
> precomputed from the requester's `core_role` alone. At request time, the engine must
> check whether the relevant department currently has an assigned HOD before deciding
> stage order: with an HOD, the Manager/Supervisor stage is skipped; without one, the
> standard chain applies unchanged. See `adr/0001` decision 3.

### `audit_logs`
`id`, `user_id`, `action`, `auditable_type`, `auditable_id`, `old_values` (json),
`new_values` (json), timestamps

### `users`
Standard Laravel `users` table + `company_id`, `role`, `employee_id` (FK → employees,
**nullable**), `is_master_admin` (boolean), `must_change_password` (boolean, **default
true**), `password_changed_at` (timestamp, nullable).

`must_change_password` defaults to **true** so that a new account is secure by omission —
an account created by a code path that forgets to set the flag is gated, not exposed.
It is set on every provisioned account (Master Admin creating a Director, HR creating
staff, and the seeded Master Admin account itself) and cleared only on a successful
password change, which stamps `password_changed_at`. While the flag is true, a logged-in
user is forced to the password-change screen before any other access — enforced by global
middleware, not per-controller checks. See `adr/0001` decision 5.

**Master Admin is a distinct account type, not a permission flag on a normal staff
login.** A Master Admin user has **no `employee_id` and no linked Employee record** —
the FK is null and stays null. It submits nothing (no Employee profile means no
entitlements and no requests), approves nothing in the normal chain, and exists solely
for oversight and data-repair access.

This is structural, not a policy check. The rule "no user may approve their own request"
holds for Master Admin because the account has nothing of its own to approve — there is
no code path to forget to guard. See `adr/0001` decision 4.

> **Two accounts for one person is intentional, not a bug.** A real person who needs
> both normal employee HR access *and* master admin access — e.g. a company director who
> is also an employee — holds **two separate user accounts with two separate logins**:
> one normal account with an `employee_id`, one Master Admin account without. Do not
> "fix" this by merging them; the merge is the exact conflation `adr/0001` rejects.
> Audit trails for such a person are split across two user IDs by design, which records
> which capacity each action was taken in.

### `policy_configurations`
`id`, `company_id` (FK), `key`, `value`, `effective_from`, timestamps

Holds every configurable HR policy number per company (annual leave days, OT rate,
EPF base, sick leave tiers, etc.) — see `conventions.md` §5 "Config Over Hardcode."

---

## Attendance (Phase 2 — structural placeholder, not yet built)

### `attendance_import_batches`
`id`, `uploaded_by`, `file_name`, `period_start`, `period_end`, `status`, timestamps

### `attendance_import_rows`
`id`, `batch_id` (FK), `fingerprint_id`, `matched_employee_id` (FK, nullable), `date`,
`in_time`, `out_time`, `work_time_minutes`, `note` (raw NGTime text, e.g. "Missing OUT"),
`status` (matched / unmatched / needs_review), timestamps

### `attendance_corrections`
`id`, `attendance_import_row_id` (FK), `corrected_by`, `old_value`, `new_value`,
`reason`, timestamps — also written to `audit_logs`

---

## Leave (Phase 2 — structural placeholder, not yet built)

`leave_types`, `leave_balances`, `leave_requests` — the legacy system's design for
these was reasonable; will be rebuilt clean with proper tenant scope from creation
rather than carried over directly.

---

## Notes Carried From AHS Audit

- Old `main_company` free-text field on `companies` → replaced with proper
  `parent_company_id` self-referencing FK.
- Old `working_days` / `working_hours` free-text fields → replaced with structured
  `TIME` and `JSON` columns.
- Scope columns (`company_id`, `branch_id`, `department_id`) must exist on every new
  business table **at creation** — never retrofitted, unlike the legacy system's
  `er_inquiry_findings` table, which had scope columns added via a later migration
  with a SQL backfill.
