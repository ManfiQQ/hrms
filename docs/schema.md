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
| core_role | enum: ASSISTANT_DIRECTOR, HR, MANAGER, SUPERVISOR, STAFF | Drives approval routing — see `business-rules.md`. ⚠ Pending reconciliation with `level` — see `CLAUDE.md` §10 |
| level | enum: STAFF, SUPERVISOR, MANAGER, HOD, ADMIN | Org display tier |
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

### `employee_family_members`
`id`, `employee_id` (FK), `relationship`, `name`, `contact_no`, `is_emergency_contact`, timestamps

### `employee_education_history`
`id`, `employee_id` (FK), `level` (SPM/Diploma/Degree/etc.), `institution`, `year`, timestamps

### `employee_employment_history`
`id`, `employee_id` (FK), `company_name`, `position`, `start_date`, `end_date`, timestamps

### `employee_status_history`
`id`, `employee_id` (FK), `status`, `effective_date`, `reason`, `changed_by`, timestamps

> Every employment status / grade / position change is a **new row**, never an
> overwrite of the current record — required to answer "when did this employee move
> from Grade C to D," which the legacy system's flat-field design could not do.

### `employee_documents`
`id`, `employee_id` (FK), `type`, `file_path`, `uploaded_by`, timestamps

---

## Core Engine Tables (Phase 0)

### `approval_requests`
`id`, `requestable_type`, `requestable_id` (polymorphic), `requested_by`, `current_stage`,
`status`, timestamps

Routing logic sourced from the legacy system's `AGENTS.md` (a genuinely well-designed
part of it) — see `business-rules.md` § Approval Hierarchy.

### `audit_logs`
`id`, `user_id`, `action`, `auditable_type`, `auditable_id`, `old_values` (json),
`new_values` (json), timestamps

### `users`
Standard Laravel `users` table + `company_id`, `role`, `is_master_admin` (boolean).
Master Admin is not an Employee profile — no employee record, submits no requests,
full oversight/override authority (carried from legacy `AGENTS.md`).

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
