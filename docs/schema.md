# Schema

> Living document. Update this file in the same commit as any migration —
> see `CLAUDE.md` Principle #5. This is a pre-implementation draft covering
> Phase 0 and Phase 1. Phase 2+ tables are added as those modules are speced.

---

## Status

**Pre-implementation, with one exception.** As of **2026-08-11** the `users`,
`password_reset_tokens` and `sessions` tables are **migrated** —
`0001_01_01_000000_create_users_table.php`, carrying the Phase 0 account columns the
Auth & RBAC spec requires. Everything else on this page is still a draft with no migration
behind it.

The Laravel base migration was **edited in place** rather than patched by a later `ALTER`.
That is deliberate and was only available because no migration had ever run against real
data: `conventions.md` §7 asks that a table's design not be patched by a later "repair"
migration where it can be avoided, and here it could. Once real data exists this option is
gone, and changes become forward-only migrations.

---

## Core / Company & Org (Phase 0)

### `companies`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string, unique | Canonical spelling only — see `CLAUDE.md` §5 |
| code | string, unique | `AHS`, `AIM`, `ES SOFEEYA`, `ZISH GLOBAL`, `TURSENIA TRADING`, `SLEGHO` — the complete set, see `CLAUDE.md` §5 |
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
> | Set | **Company-dedicated** — belongs to that one company | AIM's factory |
>
> Branches and departments spanning companies is a **common pattern in this group, not an
> edge case** — AIM, TURSENIA and ES SOFEEYA staff share one Logistics branch; HQ
> Marketing is staffed from several companies. See `adr/0002`.
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
| employee_no | string, unique | **Group-wide unique**, format `AHS-0001` — `AHS` prefix + sequential zero-padded number. ⚠ The prefix is **always `AHS`**, the parent company, **regardless of which subsidiary employs the person** — an AIM employee is still `AHS-0042`. Numbering is a single group-wide sequence, not per-company. |
| previous_employee_id | FK → employees, self-referencing, nullable | Links a **rejoiner's** new record to their old one. `RESIGNED` and `TERMINATED` are terminal (`business-rules.md` BR-2), so a returning employee gets a **new record with a new `employee_no`** — never a reactivated one — and this column is the only thread back. BR-2 already required the reference; no column existed for it, leaving the rule unimplementable. Employee Master **stores** the link only: whether prior service counts toward leave entitlement is a Leave-spec decision, and it cannot be made at all unless the link is captured now. See `adr/0003` decision 9. |
| full_name, nickname, email | string, nullable | `email` is **nullable and frequently absent** — much of this workforce (factory crew, studio staff, live hosts) has no company email. That is precisely why login runs on `phone_no` and not on email (`adr/0004` decision 6). |
| phone_no | string, **NOT NULL**, **unique** | **This is the login username** (`adr/0004` decision 6). `NOT NULL` **and** uniquely indexed — it previously had neither. Normalised, validated, and restricted to HR / Master Admin for edits — see the note below. |
| company_id | FK, **NOT NULL** | **The payroll and legal employer — that meaning only.** Determines which company's leave entitlement, policy config, payroll and statutory rules apply. Mandatory, scoped from creation. **It no longer answers "what authority does this person have"** — `employee_roles` does (`adr/0003` decision 6). Approval scope still reads this value: a `SUPERVISOR`, `MANAGER` or `HOD` approves only for employees sharing it, shared department or not (`adr/0002` decisions 4–5) — but *which* role they hold comes from the pivot. **No `secondary_company_id` column exists and none may be added**: a person's involvement with other companies is derived by querying `employee_roles`, never stored a second time. **It additionally bounds read scope**, via the employer's position in `companies.parent_company_id` — see the read-scope note below (`adr/0004` decision 1). |
| branch_id, department_id, position_id | FK | Org assignment. **Independent of `company_id` and not required to match it** — an employee may sit in a shared branch/department belonging to no single company, or to a different one. This is valid and must not be rejected by validation. See `adr/0002` decision 2. |
| fingerprint_id | string, unique, nullable | Matches NGTime attendance export ID. **HR-managed on this record; current value only.** Phase 1 keeps no enrolment history — a re-enrolment overwrites the value in place. If historical punch-to-employee resolution later proves necessary, that is a Phase 2 Attendance decision, not a Phase 1 table. |
| level | enum: STAFF, SUPERVISOR, MANAGER, HOD | **Display field only** — org chart, directory grouping, seniority tier. Never drives an authorization or routing decision. `ADMIN` deliberately excluded: it conflated a system permission with an org-seniority tier — see `adr/0001`. Where a single headline value is needed for display, this is it — which is why no `primary_role` column exists on `employees` (`adr/0003` decision 1). |
| employment_type | enum: FULL-TIME, PART-TIME, CONTRACT, INTERN, FREELANCE | |
| staff_status | enum: PROBATION, ACTIVE, CONFIRMED, SUSPENDED, RESIGNED, TERMINATED | **Setting `RESIGNED` or `TERMINATED` has an immediate effect on the user account and on `employee_roles`, in the same transaction** — see § Account lifecycle under `users` (`adr/0004` decision 5). |
| join_date, probation_end_date, confirmation_date | date, nullable | |
| direct_supervisor_id, manager_id | FK → employees, self-referencing, nullable | Two-tier reporting confirmed from legacy Staff Master template |
| attendance_type | enum: FIXED, FLEXIBLE | FIXED = late after configured start time; FLEXIBLE = OT applied manually |
| work_start_time, work_end_time, ot_after_time | TIME | **Fixes legacy design** — old system stored these as free-text strings |
| working_days | JSON array | e.g. `["MON","TUE","WED","THU","FRI","SAT"]`. **Fixes legacy design** — old system stored `"ISNIN - SABTU"` as a string |
| offday | JSON array | |
| hours_enabled | boolean | Whether Saturday accumulated-hours banking applies to this employee |
| created_by, updated_by | FK → users, nullable | |
| timestamps, soft deletes | | |

> ### Read scope comes from the employer's position in the hierarchy — and `company_id` bounds it, never grants it
>
> An account's **read scope** — *which companies' employees it may see at all* — is derived
> from where its employer sits in `companies.parent_company_id` (`adr/0004` decision 1):
>
> | Employed by | Reads |
> |---|---|
> | **AHS** — the parent | The **whole group** |
> | A **subsidiary** | That **subsidiary only** |
>
> This applies uniformly and is **not** read off the role. `HR`, `ASSISTANT_DIRECTOR` and
> `ACCOUNT` see the whole group because they are employed by AHS, not because of the role
> they hold; an HR hired by a single subsidiary would see that subsidiary only, with no code
> change. A seventh entity added under AHS becomes visible to group-level staff
> automatically — nothing is provisioned, so nothing can be forgotten.
>
> **⚠ Word this precisely, because the inverted reading is the bug.** `company_id` does
> **not grant** visibility — **roles grant it**. `company_id` **bounds** the visibility a
> role has already granted. Scope answers *which companies*; role answers *what data within
> them*. Collapsing the two axes into one is exactly what made `employee-master.spec.md` §6
> wrong.
>
> **There is no manual scope override, and none may be added.** Scope is derived, never
> configured per account. A stored override would be a second answer to a question the
> hierarchy already answers, and the two would eventually disagree — the same reasoning that
> rejected `secondary_company_id` (`adr/0003` decision 6) and the `is_enabled` flag on
> `employee_roles` (`adr/0003` decision 1). Where a narrower scope is genuinely wanted, the
> answer is to employ the person at the subsidiary, not to add a switch.
>
> **Scope depends on the hierarchy being seeded correctly** — a subsidiary mis-parented
> under AHS grants its staff group-wide reads. The hierarchy is small and rarely changes,
> but it is now load-bearing and **must be covered by a test**.

> ### `employees.phone_no` is the login username — read before touching it
>
> Login runs on the phone number, not on email (`adr/0004` decision 6). `email` is nullable
> and much of this workforce has none; a phone number is something every employee has and
> remembers. Four consequences are binding on the schema and on any code that writes this
> column:
>
> - **Unique index, required.** It previously had none. Two employees sharing a number — a
>   married couple at the same workplace, or a typo — makes login ambiguous.
> - **The value is normalised before storing and before comparing**: strip spaces, dashes,
>   and a leading `+60` or `60`. `012-345 6789`, `0123456789` and `+60123456789` are one
>   number and must all resolve to the same stored value.
> - **Validation: 9–12 digits after normalisation.** Malaysian landlines run 9–10, mobiles
>   10, and `011` numbers 11.
> - **Only `HR` and Master Admin may change it.** An employee changing their own username
>   could take over another person's identifier or lock themselves out.
>
> **`NOT NULL`, decided 2026-08-11.** `adr/0004` requires the unique index but did not state
> nullability. It is **NOT NULL**: the column is the login username, and decision 7 requires
> **every** employee to hold an account in order to verify their own attendance data. An
> employee with no phone number is an employee with no account, and that **blocks payroll**,
> which cannot proceed on unverified attendance. Nullable-plus-unique would not have
> expressed the rule either — MySQL permits many `NULL` rows under a unique index, so it
> cannot say "every employee has a distinct username."
>
> **⚠ Operational implication — HR cannot register an employee without a phone number.**
> The registration form must require it, and there is no "add it later" path: the record
> cannot be created without it. This is the intended cost, not an oversight to work around
> with a placeholder value — a dummy number would occupy the unique index and hand one
> employee's username to another.

> ### Two different things are called `company_id` below `employees` — do not conflate them
>
> | Kind | On these tables | What it means | On company transfer |
> |---|---|---|---|
> | **Tenant marker** | `employee_family_members`, `employee_education_history`, `employee_employment_history`, `employee_documents`, `employee_status_history` | Denormalized from the parent employee so the tenant global scope applies uniformly | Cascades or freezes — see § Company transfer below |
> | **Company reference** | `employee_roles`, `employee_job_functions` | A real reference to *which company the row is about* — not a tenant marker at all | **Never touched** |
>
> **All child tables below carry `company_id` at creation**, per `conventions.md` §3 —
> they are business tables, not reference/lookup tables. On the tenant-marker tables it is
> denormalized from the parent employee so a compromised or mistaken `employee_id` cannot
> leak rows across tenants. An earlier draft of this file omitted the column; that omission
> contradicted `conventions.md` §3 and is corrected here.
>
> On `employee_roles` and `employee_job_functions` the column instead answers **"in which
> company does this apply"**, which is why a company transfer leaves those rows alone
> (`adr/0003` decision 7). Cascading them would corrupt the data outright: a Manager role
> at AIM is still a Manager role at AIM after the person's payroll moves elsewhere.

### `employee_roles`

**The authority pivot** — replaces the `employees.core_role` enum column, which no longer
exists (`adr/0003` decision 1). Authority is a triple: *who*, *at which company*, *what
role*. A single column on `employees` could express none of it — one person holds several
roles, and the roles differ per company, so "what authority does this employee have?" has
**no answer until a company is named**.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | FK → employees | |
| company_id | FK → companies | **Which company this role applies in.** A real company reference, **not** a tenant marker — see below |
| role | enum | Six values, listed below |
| effective_date | date | When the role takes effect |
| assigned_by | FK → users | Who granted it |
| revoked_date | date, nullable | **`NULL` = currently held** |
| revoked_by | FK → users, nullable | |
| revoke_reason | text, nullable | |
| created_by, updated_by | FK → users, nullable | |
| timestamps | | **No soft deletes** — see "rows are never deleted" below |

`role` enum — **six** values:

```
ASSISTANT_DIRECTOR, HR, ACCOUNT, HOD, MANAGER, SUPERVISOR
```

**Indexes: `(employee_id, company_id)` and `(company_id, role)`.** Every authority check is
now a query rather than a field read, so eager loading must be disciplined or the employee
list will N+1.

**`company_id` here is a real company reference, not a tenant marker.** It answers "in
which company does this role apply," not "which tenant owns this row." That distinction is
load-bearing: it is why these rows are **never cascaded on a company transfer**
(`adr/0003` decision 7). Do not apply the ordinary tenant global scope to it unthinkingly.

**`STAFF` is deliberately not a value.** With a pivot, an ordinary staff member is someone
with **no row here at all**. Defining a value for the absence of authority would create a
second way to express the same state, and the two would eventually disagree.

**`MASTER_ADMIN` and `DIRECTOR` are deliberately absent**, and for the reasons `adr/0001`
gives, both of which still hold. A Master Admin has **no employee record**, so it can hold
no `employee_roles` row — the rule "Master Admin never has an Employee record" stays
**structurally impossible to violate** rather than test-enforced, now enforced by the
absence of any pivot row rather than by the absence of an enum value. Master Admin is
identified only at the `users` level, by **`system_access = FULL` with a null
`employee_id`** (`adr/0004` decision 2). `DIRECTOR` is absent because Director authority is
exercised **off-system** (`adr/0001` decision 7).

**Rows are never deleted.** Revoking a role sets `revoked_date`; re-granting it later
inserts a **new row**. This preserves the full cycle — held Jan–Aug, revoked Aug, re-granted
November — which a boolean toggle cannot express. **Every authority query must filter
`WHERE revoked_date IS NULL`.** Omitting it returns revoked authority as current: a silent
security failure, not an error. It belongs in a default model scope, not repeated at each
call site.

**No `is_enabled` flag exists on this table, and none may be added.** A flag would mean
every authority check must test two conditions instead of one, and the check that forgets
the second is a silent security hole. Revocation is the single mechanism. This is also why
the table carries no soft deletes: a deleted-at column would be a second way to say
"revoked," and the two would drift.

**`effective_date` is distinct from `created_at`.** A promotion is typically effective
before HR gets to enter it; the ledger records both the date it applies from and the date
it was typed.

**Grant restrictions (`adr/0003` decision 3).** `ACCOUNT`, `HR` and `ASSISTANT_DIRECTOR`
are **hardcoded restricted** — only Master Admin may grant them, and that cannot be
configured away. Without this, "only `ACCOUNT` sees salary" is unenforceable: an HR who can
grant roles freely grants themselves `ACCOUNT`. `HOD` is restricted but Master-Admin
changeable — granting it *bypasses two stages* rather than adding one. `MANAGER` and
`SUPERVISOR` are unrestricted routine appointments. Every change to a restriction flag is
written to `audit_logs`.

### `job_functions`
`id`, `name`, `description`, `is_active` (boolean), timestamps, soft deletes

**What work a person does**, as distinct from what they may approve. A **reference table,
not an enum**, because the list is not stable: it grows as the remaining workplaces
(factory, studio, galleria, restaurant) are mapped (`adr/0003` decision 2).

Starting set: `BDO`, `Admin`, `Media`, `Live Host`, `Operation Crew`.

**Master Admin creates and deactivates these types; HR only assigns them.** Keeping the
vocabulary under one account is what stops it drifting into three spellings of the same
thing (`CLAUDE.md` §5).

**Removal is soft delete only.** Hard-deleting a function that employees currently hold
would orphan their rows and break history. A "deleted" function is deactivated: it
disappears from the assignment picker, existing assignments stay intact, and it can be
reactivated if the workplace reopens.

### `employee_job_functions`
`id`, `employee_id` (FK), `company_id` (FK), `job_function_id` (FK), `created_by`,
`updated_by`, timestamps, soft deletes

`company_id` has the **same nature as on `employee_roles`** — a real company reference
saying where the person performs this function, **not** a tenant marker, and **not
cascaded** on a company transfer (`adr/0003` decision 7).

Two things deliberately **not** modeled here, because they are already other fields:
`Intern` is an `employment_type`, and `Logistic` is a **branch** (`adr/0002`). An intern
doing media work has job function `Media` and `employment_type = INTERN` — two facts, two
fields.

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
`id`, `company_id` (FK), `employee_id` (FK), `change_type` (enum), `old_value`,
`old_label`, `new_value`, `new_label`, `effective_date`, `reason`,
`changed_by` (FK → users), `created_at`

`change_type` enum — **four** values, and only four:

```
STAFF_STATUS, POSITION, DEPARTMENT, LEVEL
```

**`CORE_ROLE` is deliberately not among them.** Role history lives in `employee_roles`,
which already records every grant and revocation with dates, actors and reasons. Writing
the same event to a second table would create two records of one fact that can disagree
(`adr/0003` decision 8).

**`old_label` / `new_label` hold a snapshot of the display text at the time.** Storing only
`department_id = 7` would require a join to render, and that join shows the department's
name **today**, not its name **then** — so renaming a department would retroactively
rewrite history. A ledger that changes retroactively is not a ledger. The labels are
redundant for enum types (`CONFIRMED` / `CONFIRMED`); that is accepted, because one uniform
row shape costs a few bytes and avoids per-type branching in every reader. **To be reviewed
once the system is running on real data.**

**Append-only ledger — deliberate exception to `conventions.md` §3.** No `updated_by`,
no soft deletes, no `updated_at`: rows are never edited or deleted, only inserted. A
correction is a new row, not an edit. Mutability would defeat the point of the table.

> Every employment status / grade / position change is a **new row**, never an
> overwrite of the current record — required to answer "when did this employee move
> from Grade C to D," which the legacy system's flat-field design could not do.

> **The UI merges this table and `employee_roles` into one timeline**, so HR reads a single
> chronological history without the data being stored twice:
>
> ```
> 15 Jan 2026 · Role → Manager (AIM)        [employee_roles]
> 01 Mar 2026 · Status → CONFIRMED          [employee_status_history]
> 08 Aug 2026 · Account (AIM) revoked       [employee_roles]
> ```

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

### Company transfer — three cascade categories

Transfers between group entities **do occur, rarely**. When they do, `employees.company_id`
changes **in place**; the record and the `employee_no` stay with the person. Child tables
then fall into three categories, distinguished by what `company_id` *means* on each
(`adr/0003` decision 7):

| Category | `company_id` means | On transfer | Tables |
|---|---|---|---|
| **Descriptive** | Tenant marker; the row describes the *person* | **Cascade** | `employee_family_members`, `employee_education_history`, `employee_employment_history`, `employee_documents` |
| **Event** | The employer *at the time it happened* | **Frozen forever** | `employee_status_history`, and all Phase 2 leave / payroll / attendance tables |
| **Company-reference** | A real reference to a company, unrelated to employment | **Untouched** | `employee_roles`, `employee_job_functions` |

**The test to apply when adding any new table** — if this person's payroll employer changed
tomorrow, would this row still be true?

- Yes, and it is about the person → **descriptive**, cascade
- Yes, because it happened under the previous employer → **event**, freeze
- Yes, because `company_id` here is not about the employer at all → **company-reference**,
  leave alone

Freezing event records is what keeps payroll and statutory history attributable to the
entity that actually paid. A payslip issued by AIM must not be rewritten as TURSENIA's
because the employee later transferred; that is not an update, it is falsification.

> **⚠ Consequence — frozen rows fall outside the new tenant scope.** After a transfer, the
> employee's pre-transfer history carries the **old** `company_id`, and the new employer's
> tenant scope excludes it. Their Status History tab would appear to begin on the transfer
> date, with no error — the same silent-missing-rows failure mode `adr/0002` flags for
> shared branches.
>
> **Therefore: event tables accessed through an employee relationship release the tenant
> scope.** Permission has already been decided at the employee level — if the user may read
> this employee, they may read this employee's history, and filtering again per row adds no
> security while breaking the record. Queried **directly** for reporting, tenant scope
> applies in full, so "how many promotions did TURSENIA make this year" stays correctly
> scoped. Test both directions. This carve-out is recorded in `conventions.md` alongside
> the `adr/0002` one, because Phase 2 will create many event tables.

---

## Core Engine Tables (Phase 0)

### `sequences`
`id`, `key` (string, unique), `next_value` (bigint), timestamps

**Generic gap-free counter.** Its first consumer is Employee Master, via the row for
`key = 'employee_no'`, but the table is deliberately generic — claim numbers and letter
numbers will use it later rather than each inventing their own counter.

**The row is taken with `lockForUpdate()` inside the same transaction as the insert it
numbers.** `MAX() + 1` is **not** acceptable: it collides whenever two requests read the
current maximum before either writes — a double-clicked Save button, two open tabs, a
legacy import running alongside manual entry, a seeder. The client's operating rule that
**one HR does all registration** does not remove this; that rule prevents duplicate
*people*, not duplicate *numbers*, and the two protections are complementary rather than
alternatives (`adr/0003` decision 9).

Deriving the number from `employees.id` was **rejected**: it leaves visible gaps whenever a
transaction rolls back, couples the number to a primary key, and makes Master Admin
correction impossible, since a derived value cannot be edited.

**The sequence never rewinds.** A resigned or terminated employee's number is retired with
them, permanently, and is never reissued. A number vacated by a Master Admin correction is
likewise **burned, not returned to the pool** — reissuing it would point previously printed
letters and payslips at the wrong person.

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
> `employee_roles.role` values not restricted to their own `company_id`; `SUPERVISOR`,
> `MANAGER` and `HOD` are confined to their own company, and an employee with **no**
> `employee_roles` row holds no approval authority at all. `ACCOUNT` is not a routing tier
> in either direction — it is functional authority over money, not a management rank, and
> is ignored for routing entirely (`adr/0003` decision 4).
> Every cross-company approval is written to `audit_logs`.
> **Approval authority is not data visibility** — approving a cross-company request grants
> the request and its deciding context, and **never** that employee's salary, payroll,
> documents, family records, disciplinary history, or full leave history. Visibility runs
> through a **separate permission check, not yet defined** — Auth & RBAC spec. **Salary is
> the exception and is already settled: only the `ACCOUNT` role may read it, and no `HR`
> may** (`adr/0003` decision 5); the `hr_scope` distinction previously noted here is
> withdrawn and must not be modeled. Test both directions: the approval is allowed, and it
> grants no wider read. See `adr/0002` decision 5.

> **Routing must resolve the HOD chain dynamically, per (department, company).** An HOD is
> optional per department — some departments have one, some don't, and it varies between
> departments *within the same company*. The stage order therefore **cannot** be
> precomputed from the requester's roles alone. At request time, the engine must
> check whether that department has an assigned HOD **employed by the requester's own
> company** before deciding stage order: if so, the Manager/Supervisor stage is skipped;
> if not — no HOD at all, or one belonging to another company — the standard chain applies
> unchanged and the request is **not** blocked. A shared department may hold one HOD per
> company represented in it. See `adr/0001` decision 3 and `adr/0002` decision 4.

> **Multi-role routing (`adr/0003` decision 4).** One person may hold several roles across
> several companies, so the engine reads them differently depending on the direction:
>
> - **As an approver — no ambiguity.** If they hold *any* role qualifying for the stage,
>   they may act on it, subject to the existing checks: same company (except `HR` and
>   `ASSISTANT_DIRECTOR`) and never their own request. Multiple roles mean *more* things
>   approvable, never a conflict.
> - **As a requester — rank is the highest role held anywhere in the group**, not only at
>   their payroll employer, on the hierarchy
>   `ASSISTANT_DIRECTOR → HR → HOD → MANAGER → SUPERVISOR`. An employee with no
>   `employee_roles` row anywhere is routed as ordinary staff. Accepted consequence: rank
>   can be conferred by a position at another group company.
> - **Entitlement and approvers still come from `employees.company_id`** — leave is drawn
>   from the payroll employer's quota and approved by that company's people. Only the
>   requester's *rank* is read group-wide.
>
> **This table must record which role was used to route each request**, so that "why did
> this skip the Manager stage?" has a written answer months later. The column shape belongs
> to the Approval Engine spec, which has not been written.

### `audit_logs`
`id`, `user_id`, `action`, `auditable_type`, `auditable_id`, `old_values` (json),
`new_values` (json), timestamps

### `users`
Standard Laravel `users` table — **with `email` changed to nullable and `remember_token`
dropped, both below** — plus `employee_id` (FK → employees,
**nullable**), `system_access` (enum, **NOT NULL, default
`STANDARD`**), `must_change_password` (boolean, **default true**), `password_changed_at` (timestamp,
nullable), `activation_token` (string, unique, nullable), `activation_expires_at`
(timestamp, nullable), `activation_downloaded_at` (timestamp, nullable),
`activation_used_at` (timestamp, nullable).

> **⚠ `role` and `company_id` withdrawn from `users` — 2026-08-11.** This list previously
> carried both. **Neither exists, and neither may be added.** They were drafted before the
> decisions that replaced them, were never written into a migration, and each is now a
> second answer to a question something else answers better.
>
> **`role` is answered by `employee_roles`.** Authority is per company and a person holds
> several — *"what authority does this person have?"* has **no answer until a company is
> named** (`adr/0003` decision 1). A single column on `users` can express none of that, and
> it is the same mistake `employees.core_role` was removed for. This is also why
> `employee_roles` is a pivot rather than a field in the first place.
>
> **`company_id` is answered by derived read scope.** An account's scope comes from where
> its **employer** sits in `companies.parent_company_id`, never from a value stored on the
> account (`adr/0004` decision 1). A stored `users.company_id` would be exactly the manual
> override that decision forbids, and it could not describe Master Admin or Director
> accounts at all — they belong to **no** company and carry a null `employee_id`.
>
> Same reasoning as the withdrawals above and in `adr/0003`: two ways to state one fact
> eventually disagree, and the stored one is the copy that goes stale.

> **⚠ `remember_token` withdrawn — 2026-08-11.** Laravel's default `users` table creates it
> via `rememberToken()`. **This migration does not, and it may not be added back.**
> Remember-me is removed entirely — checkbox gone from the login form *and* the driver
> disabled (`auth-rbac.spec.md` BR-A4).
>
> **The column is not merely unused; it is an invitation.** Left in place, the next person
> reads it as "remember-me exists, it just isn't wired up yet" and wires it up. Its absence
> is what makes BR-A4 hold without depending on anyone remembering the rule.
>
> The reasoning behind BR-A4 is worth keeping in view here: a persistent cookie
> re-authenticates a user past the 2-hour inactivity window, which matters because much of
> this workforce logs in from **shared terminals** at the factory, studio and galleria. It
> is also a second credential that must be invalidated on password change and on freeze —
> not having it removes something that can be forgotten.

#### `email` — nullable, unique retained

**`users.email` is `nullable` and keeps its unique index.** This is a deliberate change to
Laravel's default, which declares it NOT NULL + unique.

**Email is not a login credential here.** The username is `employees.phone_no`
(`adr/0004` decision 6). `employees.email` is already nullable because most field staff —
Operation Crew, Live Host, factory — have no company email, and a `users` row is created for
**every** employee in the same transaction as their employee record. NOT NULL would
therefore fail on the **second** employee without an email. That is not a risk; it is a
certainty, and it would surface as a failed employee registration.

**A placeholder address is not the workaround.** `AHS-0042@placeholder.local` is rejected for
the same reason a placeholder phone number is (`auth-rbac.spec.md` BR-A1): it occupies the
unique index and manufactures data that is not true.

**Nullable + unique states exactly the intent.** MySQL permits many `NULL` rows under a
unique index, so the pair reads as *email is optional, but where present it is unique*.

> **This is the inverse of `phone_no`, and the difference carries the meaning.**
> `employees.phone_no` is **NOT NULL** because it *is* the username. `users.email` is
> **nullable** because it is not. Same unique index on both, opposite nullability, for one
> reason: which of the two is a credential.

#### `system_access` — three values

```
FULL       Master Admin. Reads and writes everything, bypasses tenant scope.
VIEW_ONLY  Read-only across the group. Writes nothing, approves nothing.
STANDARD   Everyone else. Permissions come entirely from employee_roles + read scope.
```

| Value | Employee record | Scope | Salary |
|---|---|---|---|
| `FULL` | **None** — `employee_id` is null | Whole group, **tenant scope bypassed** | Yes |
| `VIEW_ONLY` | **None** — `employee_id` is null | Whole group, **read-only** | Yes |
| `STANDARD` | Yes | From the employer's hierarchy position (see § Read scope) | Only via the `ACCOUNT` role |

**This is an account dimension, not an authority role** (`adr/0001` decision 5,
`adr/0004` decision 2). It answers **"what kind of account is this"** — a question roles
**cannot** answer for accounts that have no employee record at all, and therefore no
`employee_roles` row to read. Do not merge it with `employee_roles.role`; they are
orthogonal, exactly as `level` and authority are.

**`STANDARD` deliberately covers everyone from an intern to an Assistant Director.** They
differ by *role*, not by *account type*, and this field is not the place to express that.
Their permissions come **entirely** from `employee_roles` plus the read scope derived from
their employer's position in the hierarchy — this column adds nothing on top.

**`VIEW_ONLY` currently has no holder, and that must be documented rather than corrected.**
The Director — its intended user — holds a **Master Admin account** instead
(`adr/0004` decision 4). The value is retained rather than removed because `adr/0001`
decisions 5 and 7 both name it and a genuine use is foreseeable: an external auditor, or a
second Director who should not hold write access. It is **defined but unused** — so nobody
searches for the Director's `VIEW_ONLY` account and concludes data is missing.

**A fourth value for ordinary employees was rejected.** An employee with no
`employee_roles` row *already* has exactly self-service access; a `SELF_SERVICE` value would
restate a state the absence of rows already expresses, and the two would eventually
disagree.

**`NOT NULL`, default `STANDARD` — decided 2026-08-11.** The three values above cover every
account, so a nullable column would admit a fourth, undefined state: exactly the "two ways
to express one fact" pattern `adr/0003` rejects for `is_enabled` on `employee_roles` and for
`secondary_company_id`. There is no account this column cannot describe, so there is nothing
for `NULL` to mean.

**`STANDARD` is the default because it is the narrowest of the three.** An account created
by a code path that forgets to set the column gets the *least* privileged type, not a
group-wide reader — the same secure-by-omission reasoning that makes
`must_change_password` default to **true**. Neither `FULL` nor `VIEW_ONLY` may ever be
reached by omission; both are deliberate grants.

#### Master Admin is a distinct account type

> **⚠ `is_master_admin` withdrawn — 2026-08-11.** This table previously carried an
> `is_master_admin` boolean, and `adr/0001` decision 2 identified Master Admin by it. **The
> column does not exist and must not be added.** `system_access = FULL` says the same thing,
> and two columns asserting one fact eventually disagree — the pattern already rejected for
> `secondary_company_id` (`adr/0003` decision 6), `is_enabled` on `employee_roles` and
> `primary_role` (`adr/0003` decision 1), and `hr_scope` (`adr/0003` decision 5).
> `is_master_admin` survived only because it predates `system_access` being defined at all.
> **Master Admin is `system_access = FULL` with a null `employee_id`** (`adr/0004`
> decision 2).

**Master Admin is not a permission flag on a normal staff login.** A Master Admin user has
**no `employee_id` and no linked Employee record** — the FK is null and stays null. It
submits nothing (no Employee profile means no entitlements and no requests), approves
nothing in the normal chain, and exists solely for oversight and data-repair access.

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

#### Master Admin account limits — enforced by the system, not by policy

| Rule | Value |
|---|---|
| First account | Created by `MasterAdminSeeder`, credentials from `.env` (`adr/0001` decision 5) |
| Subsequent accounts | Created by an existing Master Admin |
| **Maximum** | **3** — an attempt to create a fourth is **rejected** |
| **Minimum** | **1** — an attempt to delete or disable the last one is **rejected** |

Both limits are **enforced by the system**, not left to policy (`adr/0004` decision 4). A
single Master Admin is a single point of failure: lose that credential and nobody can grant
the `ACCOUNT` role, repair data, or manage job functions. Unlimited Master Admins is
unbounded full access.

**The Director holds a Master Admin account (`FULL`), not a `VIEW_ONLY` one**, and has **no
employee record** — `employee_id` null, the same structural shape as Master Admin
(`adr/0001` decision 4). Giving `VIEW_ONLY` the power to create accounts was **rejected
outright**: an account that can create a full-access account *is* a full-access account with
one extra step. `VIEW_ONLY` stays strictly read-only.

#### Activation — single-use QR, not a temporary password

`activation_token`, `activation_expires_at`, `activation_downloaded_at` and
`activation_used_at` implement `adr/0004` decision 7 and `auth-rbac.spec.md` BR-A22.

| Column | Meaning |
|---|---|
| `activation_token` | The single-use activation secret encoded into the QR image. Unique. |
| `activation_expires_at` | **48 hours** from issue. Past this, HR regenerates. |
| `activation_downloaded_at` | **`NULL` = HR has not fetched the image.** Set **automatically** when HR downloads the QR — no button, no user action. |
| `activation_used_at` | **`NULL` = not yet scanned.** Stamped on first scan, after which the token is dead. |

**Regenerating a token clears both `activation_downloaded_at` and `activation_used_at`**,
and invalidates the previous token.

**Why a download timestamp and not a "mark as sent" button.** The system records what it can
**observe**. Delivery happens over WhatsApp, outside the system, so a "sent" button would
record an *assertion*, not a fact — and a timestamp reading "HR sent this at 2:15pm" looks
authoritative while meaning only that someone clicked. The download is observable, and it
settles half the question with certainty: **if it was never downloaded, it was certainly
never sent.** The other half is not fabricated.

Three states follow, and the HR dashboard shows them:

| State | Meaning |
|---|---|
| Generated, not downloaded | HR has not acted |
| Downloaded, not redeemed | In flight — or the employee is ignoring it |
| Redeemed | Done |

**The account is created in the same transaction as the employee record**, not as a separate
step HR must remember. This is an operational requirement, not a convenience: the client
requires every employee to verify their own attendance data, and payroll is blocked on
incomplete attendance — an employee without an account cannot verify.

**No temporary password is issued, and none may be reintroduced.** On creation the system
generates an image containing a **QR code, the employee's full name, and the validity
period**; HR forwards it by WhatsApp or shows it in person. The employee scans it, lands in
the system already authenticated, and is **forced to set their own password** before
anything else — which is what `must_change_password` gates. **HR is notified when the code
is used.**

A temporary password is a secret HR knows and the employee also knows, and it stays valid
until changed, so a saved WhatsApp message stays usable. A single-use token is dead after
first scan: even if the image is kept, it opens nothing, and there is no window in which HR
holds working credentials to someone else's account. The 48-hour window bounds the one
remaining exposure — a forwarded image scanned before the real employee gets to it.

**Using the employee's IC number as a first password was proposed and rejected**
(`adr/0004` decision 7): an IC number is not a secret and, unlike a password, can never be
changed.

#### Account lifecycle — `staff_status` freezes the account, then expires it

Setting `employees.staff_status` to `RESIGNED` or `TERMINATED` triggers the following **in
the same transaction** (`adr/0004` decision 5). Without it, a resigned employee's login
keeps working — if they held `HR` they can still create accounts, if they held `ACCOUNT`
they can still read every salary in the group.

| Stage | When | Effect |
|---|---|---|
| **1 — Freeze** | **Immediately** | The account may read **its own data only**. No writes, no approvals, no account creation, no role grants. **All `employee_roles` rows are revoked** (`revoked_date` set) — the rows remain, for history. |
| **2 — Expire** | **10 days after `effective_date`** | **No access at all.** All data remains in the system. |

The 10 days run from **`effective_date`** — the person's actual last working day — not from
the date HR typed the change. `employee_status_history.effective_date` already exists for
exactly this (`adr/0003` decision 8), so a departure can be recorded in advance without
cutting the person off early.

**Freezing writes while allowing self-reads is the point.** The dangerous act is writing,
not reading: cutting writes immediately closes the leak, while leaving self-reads open lets
the person retrieve their own final payslip or letters during handover.

**Revocation goes through `revoked_date`, not deletion** — consistent with `employee_roles`
having no soft deletes and no `is_enabled` flag (`adr/0003` decision 1). The history of what
the person held stays intact.

**No account may be reactivated after `RESIGNED` or `TERMINATED` — by anyone, including
Master Admin.** A rejoining employee gets a **new employee record, a new `employee_no`, and
a new account**, linked back through `employees.previous_employee_id` (`adr/0003`
decision 9, `business-rules.md` BR-2). Pay and allowances on return are frequently different
from before, and a new record keeps that clean rather than overwriting history.

**Resignation and termination differ in who approves and when the clock starts:**

| | `RESIGNED` | `TERMINATED` |
|---|---|---|
| Initiated by | **The employee** — one month's notice | **HR** |
| Manager / Supervisor | Reviews and **approves** | Reviews — **non-blocking** |
| Countdown starts | On the last working day | **Immediately** |

Termination does not wait for approval because it may follow serious misconduct, and waiting
would leave full access in the hands of the person being dismissed.

**The countdown is a UI requirement on five dashboards** — the employee's own, HR's,
Account's, Master Admin's, and the employee's manager or supervisor's. It is the **only**
correction mechanism for a status set in error, since there is no cancel path and no
reactivation.

#### Password change gate

`must_change_password` defaults to **true** so that a new account is secure by omission —
an account created by a code path that forgets to set the flag is gated, not exposed.
It is set on every provisioned account (Master Admin creating a Director, HR creating
staff, and the seeded Master Admin account itself) and cleared only on a successful
password change, which stamps `password_changed_at`. While the flag is true, a logged-in
user is forced to the password-change screen before any other access — enforced by global
middleware, not per-controller checks. See `adr/0001` decision 5.

The **QR activation flow above is what triggers this gate in practice** for staff accounts:
the employee arrives already authenticated by the token and is stopped at the
password-change screen before reaching anything else.

### `sessions`
The standard Laravel session table — `id`, `user_id` (**indexed**), `ip_address`,
`user_agent`, `payload`, `last_activity`.

**The session driver is `database`, not `file`** (`auth-rbac.spec.md` BR-A5). This is not a
preference: it is what makes the termination in BR-A15 possible.

`DELETE FROM sessions WHERE user_id = ?` ends someone's access **immediately** when
`staff_status` becomes `TERMINATED`. File sessions cannot be located by user without reading
every session file, so "immediately" would in practice mean "on their next request" — which
may never come while a screen sits open in front of the person being dismissed. **`user_id`
carries an index for exactly this query.**

**Expired session rows are pruned on a schedule.** Without it the table grows without bound.

### `policy_configurations`
`id`, `company_id` (FK), `key`, `value`, `effective_from`, timestamps

Holds every configurable HR policy number per company (annual leave days, OT rate,
EPF base, sick leave tiers, etc.) — see `conventions.md` §5 "Config Over Hardcode."

**Authentication numbers live here too, never hardcoded** (`adr/0004` decision 6):

| Setting | Value |
|---|---|
| Password minimum length | **6 characters, no composition rules** — no forced uppercase, digits or symbols |
| Failed-login throttle | **3** failures → locked 5 min · **6** → 10 min · **9** → 15 min · **12** → **locked permanently**, HR or Master Admin must unlock |
| Failed-attempt counter | **Resets on successful login** |
| Session | Expires after **2 hours of inactivity** — inactivity, not time since login |
| Activation validity | **48 hours** (see § Activation under `users`) |

**Complexity rules were rejected deliberately.** They produce `Abcd1234!` and passwords
written on paper; a memorable phrase is stronger than a short complex string kept on a
sticky note.

**⚠ The throttle tiers are load-bearing, not defence in depth.** Six characters was chosen
by the client over the recommended eight, and **the username is not secret** — it is the
employee's phone number. Password length is therefore not carrying the security here; the
throttling is. If the tiers are relaxed, or the counter is not enforced server-side, brute
force becomes practical. **Failed attempts are written to `audit_logs`**, so a hundred
overnight failures against the `ACCOUNT` holder's login is visible.

**Password reset is `HR` and Master Admin only** — not self-service by email (most employees
have none), and **not `ACCOUNT`**, who reads everything but administers nothing. Seeing data
and controlling access are different jobs.

The 2-hour inactivity window matters most for field staff, who may use a shared terminal at
the factory or studio: a session left open there is the next person's session.

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
