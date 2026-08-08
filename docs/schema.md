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
`id`, `company_id` (FK, **nullable**), `name`, `address`, timestamps

### `departments`
`id`, `company_id` (FK, **nullable**), `branch_id` (FK, nullable), `name`, timestamps

> ### Shared vs company-dedicated org structure — read before querying either table
>
> `company_id` on **both** tables is nullable, and `NULL` is a **meaningful value, not
> missing data**:
>
> | Value | Meaning | Example |
> |---|---|---|
> | `NULL` | **Shared / group-level** — available across all companies | HQ, Marketing, Logistics |
> | Set | **Company-dedicated** — belongs to that one company | THALHAH's factory |
>
> Branches and departments spanning companies is a **common pattern in this group, not an
> edge case** — THALHAH and TURSENIA staff share one Logistics branch; HQ Marketing is
> staffed from several companies. See `adr/0002`.
>
> **⚠ Query scope must be `company_id IS NULL OR company_id = :current_company`.** A
> plain `where company_id = :current` silently hides every shared branch and department —
> it returns fewer rows rather than an error, so it presents as "Logistics disappeared"
> rather than as a bug. The global scope on these two tables must include the shared rows
> by default.
>
> **This is not a multi-tenancy violation.** `branches` and `departments` are
> org-structure reference tables holding no personal or financial data — a department row
> is a name and a position in a hierarchy. Sensitive employee data (leave, payroll,
> salary, documents, family, disciplinary) remains strictly scoped to
> `employees.company_id` and is unaffected. The rule: **shared structure, scoped data.**
> See `conventions.md` §2 for the carve-out and `adr/0002` decision 3.

### `positions`
`id`, `department_id` (FK), `title`, timestamps

---

## Employee Master (Phase 1)

### `employees`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_no | string, unique | **Group-wide unique**, format `AHS-0001` — `AHS` prefix + sequential zero-padded number. ⚠ The prefix is **always `AHS`**, the parent company, **regardless of which subsidiary employs the person** — a THALHAH employee is still `AHS-0042`. Numbering is a single group-wide sequence, not per-company. |
| full_name, nickname, phone_no, email | string, nullable | |
| company_id | FK, **NOT NULL** | The employee's **payroll and legal employer** — determines which company's leave entitlement, policy config, payroll and statutory rules apply. Mandatory, scoped from creation. **Also bounds approval authority** for every `core_role` except `HR` and `ASSISTANT_DIRECTOR`: a `SUPERVISOR`, `MANAGER` or `HOD` approves only for employees sharing this value, shared department or not (`adr/0002` decisions 4–5). |
| branch_id, department_id, position_id | FK | Org assignment. **Independent of `company_id` and not required to match it** — an employee may sit in a shared branch/department belonging to no single company, or to a different one. This is valid and must not be rejected by validation. See `adr/0002` decision 2. |
| fingerprint_id | string, unique, nullable | Matches NGTime attendance export ID. **HR-managed on this record; current value only.** Phase 1 keeps no enrolment history — a re-enrolment overwrites the value in place. If historical punch-to-employee resolution later proves necessary, that is a Phase 2 Attendance decision, not a Phase 1 table. |
| core_role | enum: STAFF, SUPERVISOR, MANAGER, HOD, HR, ASSISTANT_DIRECTOR | **Authority field** — the only field consulted for approval routing and RBAC. Six values. See `business-rules.md` § Approval Hierarchy and `adr/0001`. `HOD` added (missing from legacy). **`MASTER_ADMIN` is deliberately not a value**: a Master Admin has no employee record, so the value could only ever be set in error. Excluding it makes "Master Admin never has an Employee record" **structurally impossible to violate** rather than test-enforced — there is no value to set. Master Admin is identified only at the `users` level (`is_master_admin` + null `employee_id`). `DIRECTOR` is likewise absent — see `adr/0001` decision 7. |
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
`id`, `company_id` (FK), `employee_id` (FK), `type` (enum), `file_path`, `uploaded_by`
(FK → users), `created_by`, `updated_by`, timestamps, soft deletes

`type` enum — Phase 1 starting set:

```
IC, PASSPORT, EDUCATION_CERTIFICATE, OFFER_LETTER, CONFIRMATION_LETTER,
RESIGNATION_LETTER, OTHER
```

A fixed enum rather than free text, per `conventions.md` §4. **This list is a starting
set, not exhaustive** — it may be amended by a future migration when HR needs more types.
`OTHER` is a deliberate escape hatch so an unanticipated document is never blocked from
being uploaded while that migration is written.

---

## Core Engine Tables (Phase 0)

### `approval_requests`
`id`, `requestable_type`, `requestable_id` (polymorphic), `requested_by`, `current_stage`,
`status`, timestamps

Routing logic sourced from the legacy system's `AGENTS.md` (a genuinely well-designed
part of it) — see `business-rules.md` § Approval Hierarchy.

> **The chain terminates at HR ↔ ASSISTANT_DIRECTOR peer approval** — HR requests are
> approved by an Assistant Director and vice versa, a single stage with nothing above it.
> No stage routes to Master Admin, and **no stage routes to a Director** — Director
> authority is exercised off-system and recorded as an audited manual override, not an
> approval action (`adr/0001` decisions 6–7, `business-rules.md` § Director Discretion).
> The counterpart search is **group-wide** — these two roles approve across companies, so
> a company holding an HR but no Assistant Director routes to another company's Assistant
> Director rather than blocking. Only where no counterpart exists **anywhere in the group**
> is the request held **blocked with a reason** — never auto-approved, never escalated to
> Master Admin.

> **HOD authority is strictly same-company.** A shared department may contain staff from
> several companies (`adr/0002`), but its HOD approves **only** for those who share the
> HOD's own `employees.company_id`. An HOD acting on a request from an employee of a
> different company is a **scope violation and must be rejected**, shared department or
> not. `approver.company_id === requester.company_id` is an **invariant** for every HOD
> approval, not a rule with an exception. See `adr/0002` decision 4 (amended 2026-08-08 —
> an earlier draft of this file stated the opposite).

> **Cross-company approval is `HR` and `ASSISTANT_DIRECTOR` only.** They are the only two
> `core_role` values not restricted to their own `company_id`; `STAFF`, `SUPERVISOR`,
> `MANAGER` and `HOD` are. Every cross-company approval is written to `audit_logs`.
> **Approval authority is not data visibility** — approving a cross-company request grants
> the request and its deciding context, and **never** that employee's salary, payroll,
> documents, family records, disciplinary history, or full leave history. Visibility runs
> through a **separate permission check, not yet defined** — Auth & RBAC spec, along with
> the unmodeled `hr_scope` (`PAYROLL | OPERATIONS`) distinction it needs. Test both
> directions: the approval is allowed, and it grants no wider read. See `adr/0002`
> decision 5.

> **Routing must resolve the HOD chain dynamically, per (department, company).** An HOD is
> optional per department — some departments have one, some don't, and it varies between
> departments *within the same company*. The stage order therefore **cannot** be
> precomputed from the requester's `core_role` alone. At request time, the engine must
> check whether that department has an assigned HOD **employed by the requester's own
> company** before deciding stage order: if so, the Manager/Supervisor stage is skipped;
> if not — no HOD at all, or one belonging to another company — the standard chain applies
> unchanged and the request is **not** blocked. A shared department may hold one HOD per
> company represented in it. See `adr/0001` decision 3 and `adr/0002` decision 4.

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

> **This correction pattern generalizes.** `old_value` / `new_value` / `reason` /
> `corrected_by` + `audit_logs` is also the mechanism by which off-system Director
> decisions are recorded (Haji/Umrah beyond entitlement, disciplinary appeal outcomes,
> bonus declaration and withholding) — see `business-rules.md` § Director Discretion and
> `adr/0001` decision 7. The concrete table for each is defined by that module's own
> spec (Leave, Payroll, Discipline); the column shape and the audit requirement are
> fixed here so the pattern stays consistent across modules.

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
  **Note:** "exists at creation" is about the *column*, not its nullability.
  `branches.company_id` and `departments.company_id` are nullable by design (`adr/0002`)
  and still satisfy this rule — the column is there from the first migration, and `NULL`
  carries the defined meaning "shared." This is not the legacy retrofit pattern.
