# Module Spec — Audit Trail

- **Phase:** 0 — Core Engine
- **Status:** Draft — awaiting approval. **No code until this is approved** (`CLAUDE.md`
  Principle #1).
- **Branch:** `feat/audit-trail`
- **Depends on:** `users`, `companies` (migrated); `employees` (Phase 1, for read-scope
  resolution only); `auth-rbac.spec.md` §5.4 `ReadScopeResolver` and §5.5 `RoleChecker`;
  `adr/0003` decision 5 (salary is the `ACCOUNT` role), decision 8 (role history lives in
  the pivot, not in a second table), `adr/0004` decision 1 (read scope derives from the
  employer's hierarchy position), `adr/0005` decision 5 (the Master Admin bypass write this
  spec closes) and decision 6 (the scope guard test this module is an exception to)
- **Blocks:** nothing outright — but **eight rules across seven documents already say
  "written to `audit_logs`" against a table that does not exist**, and each of them is
  unenforceable until this module ships. See §1.
- **Date:** 2026-08-12

---

## 1. Purpose

Two questions, answered by two tables, and the separation runs through this whole
document:

**What changed, who changed it, and when** — `audit_logs`. Every write to business data
that someone may later have to account for: a salary adjustment, a company transfer, a
role grant, a restriction-flag change, an attendance correction, a Director decision
entered as a manual override.

**Who tried to get in** — `security_events`. Login success and failure, lockout, password
change by the account holder, activation redemption. What the *subject* did or attempted —
an action performed *on* an account by HR or Master Admin is a data change and belongs in
the first table (§3).

These are not two flavours of one thing, and §4's first rule is that they are never
merged.

**The rest of the system has been writing cheques against this module since `adr/0001`.**
Rules that already require an audit record, none of them enforceable today:

| Rule | What must be recorded |
|---|---|
| `adr/0005` decision 5 / `auth-rbac.spec.md` BR-A14 | Every Master Admin tenant-scope bypass, with its stated reason |
| `auth-rbac.spec.md` BR-A15 | Session deletion on `TERMINATED` |
| `employee-master.spec.md` §5.7 | Every company transfer, naming the actor |
| `employee-master.spec.md` BR-13 | Every Master Admin edit of an `employee_no` |
| `adr/0003` decision 3 | Every change to a role's `is_restricted` flag |
| `adr/0002` decision 5 | Every cross-company approval |
| `business-rules.md` § Director Discretion | Every off-system Director decision entered as a manual override |
| `schema.md` § `attendance_corrections` | Every attendance correction |

Until this module exists, each of those is a sentence in a document with nothing behind
it. `adr/0005` decision 5 is the sharpest case: it is **implemented in half**, knowingly —
`MasterAdminContext` takes a reason, holds it, and drops it on the floor, because there is
no table to write it to. **This spec closes that half.** The write itself is a one-line
change to `run()` once the migration lands.

## 2. Scope

**In scope**

- `audit_logs` — the field-level data-change record, and the batch that groups a
  transaction's rows
- `security_events` — the authentication event record
- The write path for both, and the transaction rules that differ between them (BR-AT7,
  BR-AT8)
- Read permissions, including the salary filter (BR-AT9, BR-AT10)
- Retention (BR-AT11)
- The audit report, and the read-side merge with `employee_status_history` (§5.5)

**Out of scope — explicitly**

- **What each module audits.** This module provides the mechanism and the guarantees; a
  module's own spec states which of its actions produce an audit batch. Same division as
  `auth-rbac.spec.md` BR-A8: mechanism here, catalogue there. There is no central list of
  auditable actions in this document and none may be added to it.
- **`employee_status_history`** — Employee Master's table, Employee Master's rules. This
  module reads it for the report (§5.5) and **never writes to it** (BR-AT5).
- **`employee_roles`** — role history is the pivot's own shape (`adr/0003` decision 8).
  Grants and revocations are audited like any other write, but the pivot remains the
  record of *what authority is held*; this table records *that someone changed it*.
- **Alerting on suspicious activity.** A hundred overnight failures against the `ACCOUNT`
  holder's login must be *visible* (`schema.md` § `policy_configurations`); making it
  *arrive* is the Notification Engine's job, and that spec does not exist.
- **The failed-attempt counter.** Throttling is `auth-rbac.spec.md` BR-A3's, and it must
  not be computed from this table — see BR-AT8.
- **Application log files.** `security_events` falls back to the file log when it cannot
  write (BR-AT8); the file log itself is infrastructure, not a module.
- **Backup and off-VPS archival** — `CLAUDE.md` §3 § Deployment constraints.

## 3. Data Model

Tables are specified in `docs/schema.md` and are **not duplicated here**. This section
records only what a migration author needs beyond the column list.

### `audit_logs`

| Concern | Decision |
|---|---|
| Row granularity | **One row per changed field.** Three fields changed in one save is three rows |
| Grouping | `batch_id`, a **UUID generated once per transaction** and stamped on every row it produces |
| Subject | `auditable_type` + `auditable_id`, **polymorphic** — not `employee_id` |
| Values | `old_value` / `new_value`, **`TEXT`, nullable** |
| Display text | `old_label` / `new_label`, a snapshot of how the value read **at the time** |
| Actor | `user_id` |
| Tenancy | `company_id`, with `TenantScope` |
| Mutability | Append-only: `created_at` only — no `updated_at`, no `updated_by`, no soft deletes |

**`reason` is nullable and it is not decoration.** `MasterAdminContext::run()` already
takes a reason and refuses a bypass without one (`adr/0005` decision 5), and the
correction pattern fixed in `schema.md` § `attendance_corrections` is
`old_value` / `new_value` / `reason` / `corrected_by`. Both need somewhere to put it. It is
nullable because an ordinary field edit has no reason to give and inventing one would make
the column meaningless.

**There is no `created_by`.** `user_id` is the actor, and `created_by` would be the same
person recorded twice — the duplication `adr/0003` decision 6 and `adr/0004` decision 2
reject everywhere else. `conventions.md` §3's `created_by` / `updated_by` requirement is
met by `user_id` and by the table being append-only; the exception is recorded in
`conventions.md` §3 alongside `employee_status_history`.

**There is no `value_type` column, and none may be added.** See §10 decision 4.

**Indexes**

- `batch_id` — the display query. Every reader of one row wants the other rows of its
  batch
- `(auditable_type, auditable_id)` — "everything that ever happened to this record"
- `(auditable_type, field)` — the salary filter (BR-AT10) runs on this, on every HR read
- `(company_id, created_at)` — the scoped report, in date order
- `user_id` — "everything this person did"

### `security_events`

| Concern | Decision |
|---|---|
| Subject | `user_id`, **nullable** — the account, when there is one |
| Identifier | The submitted login identifier, normalised per BR-A1, **always present** |
| Event | `event_type`, a fixed enum — see below |
| Tenancy | `company_id`, **nullable**, and **no scope class at all** |
| Mutability | Append-only, as above |
| Retention | Split by `user_id` nullness — BR-AT11 |

**`event_type` is read off `auth-rbac.spec.md`, not invented here.** This module records
what Auth emits; it does not decide what Auth emits. The set below is derived from the
events that spec already specifies, and it is the **one item in this section a reviewer
should check against the source** rather than take on trust:

```
LOGIN_SUCCESS · LOGIN_FAILED    (§5.1, BR-A3)
ACCOUNT_LOCKED                  (BR-A3 — the system's own reaction to accumulated failures)
PASSWORD_CHANGED                (BR-A23 — by the account holder, first login included)
ACTIVATION_REDEEMED             (BR-A21, §5.6)
```

A **fixed enum**, for the same reason `employee_roles.role` is one (`adr/0003` decision 2):
a new authentication event is a change to what Auth does, so it arrives with a migration
and an amendment to that spec, not by a caller passing a new string.

⚠ **An action performed *on* an account by someone else is a data change, and belongs in
`audit_logs`.** Password reset and unlock by `HR` (BR-A7), QR regeneration, a
`system_access` change by Master Admin (`auth-rbac.spec.md` §6), and the session deletion
on `TERMINATED` (BR-A15) all have an actor, a subject, and a before-and-after — the shape
`audit_logs` exists for. They are not attempts to authenticate.

**BR-A15 already routes the session kill to `audit_logs`, and it stays as written.** It
also has to: that write is required **inside the freeze transaction** and must roll back
with it, which BR-AT7 gives and BR-AT8 explicitly does not. The dividing line is therefore
not "anything touching a login" but **who the event is about** — `security_events` holds
what the *subject* did or attempted, `audit_logs` holds what was *done to* the account.

> **⚠ `security_events` is the tenant-scope exception, and it must declare itself as one.**
>
> **It carries no `TenantScope` and no `SharedTenantScope`, and `company_id` cannot be
> `NOT NULL`.** A security event happens **before authentication**: there is no
> authenticated user from whom to resolve a read scope, and in the failed-attempt case
> there may be **no account at all** — an attempt against a phone number that has never
> existed in this system has no subject, so it has no employer, so it has no company.
>
> `adr/0005` decision 6 requires every model over a table carrying `company_id` to declare
> its scope **explicitly**, precisely so that *"deliberately unscoped"* and *"someone
> forgot"* are distinguishable. This model therefore declares the **documented opt-out**,
> not silence. A `SecurityEvent` model with no declaration must fail the guard test exactly
> as any other model would.
>
> `company_id` is populated where it is knowable — the event has a `user_id`, the user has
> an employee, the employee has an employer — and left null where it is not. It is a
> **reporting convenience, never an access control**. Access control for this table is
> BR-AT9.

**Indexes**

- `(user_id, created_at)` — the per-account history, and the retention sweep, which is
  exactly `user_id IS NULL AND created_at < :cutoff`
- `(identifier, created_at)` — repeated attempts against one number that matches no account
- `(event_type, created_at)` — "all lockouts this month"

**Migration rules**

- Two migrations, timestamps spaced one minute apart. Verify with
  `ls database/migrations | sort` before committing (`conventions.md` §6).
- `audit_logs.company_id` is present from the creating migration, per Principle #4 — but
  see § Still open, which must be answered **before** the migration is written, because it
  decides that column's nullability.
- Neither table gets `updated_at`, `updated_by`, or soft deletes. This is a **deliberate
  exception to `conventions.md` §3**, recorded there and on the tables in `schema.md`.
  A migration author adding them back for consistency's sake is making the change this
  paragraph exists to stop.
- `schema.md` updated in the **same commit** as each migration (Principle #5). The
  `audit_logs` stub in `schema.md` was corrected ahead of the migration, in the same commit
  as this spec, because it described a shape §10 decision 2 rejects.

## 4. Business Rules

### Shape

**BR-AT1 — Two tables, and they are never merged.**

`audit_logs` records changes to data. `security_events` records authentication events.

The subjectless case is **not a variant of a data change**. A failed login has no
`old_value`, and there is no value it could be given: nothing changed, and in the
unknown-number case there is not even a record for something to have changed on. Forcing
both into one table means every reader has to know **which columns are meaningful for
which event type** — and that rule would live nowhere. It would not be written in the
migration, it would not be written on the model, and it would be rediscovered by each
reader, differently.

Two tables state the rule in the only place that cannot be skipped: the column list.

**BR-AT2 — One row per changed field, grouped by `batch_id`.**

A save that changes three fields writes three rows sharing one `batch_id`. The UUID is
generated **once per transaction** and stamped on every row that transaction produces,
across every table it touches.

**Not JSON.** `conventions.md` §4 forbids unstructured storage where the system needs to
query against it, and *"who changed this employee's salary, and when"* must be a `WHERE`
clause, not a scan-and-parse over every audit row in the table. A JSON blob also cannot be
indexed for the salary filter BR-AT10 runs on every HR read.

**A forgotten `batch_id` produces a scattered display, not lost data.** Every row still
carries its actor, its subject, its field, and its timestamp; what is lost is the grouping
that shows three changes were one save. That is the correct failure mode for the softer of
the two properties, and it is the reason `batch_id` is a grouping key rather than a
foreign key to a `audit_batches` table that would have to exist before any row could be
written.

**BR-AT3 — The subject is polymorphic: `auditable_type` + `auditable_id`, never
`employee_id`.**

Forced by the list of writers this table already has. Three of them do not have an
employee to point at:

| Writer | Subject |
|---|---|
| `system_access` change (`auth-rbac.spec.md` §6) | a `users` row |
| Attendance correction (`schema.md` § `attendance_corrections`) | an `attendance_import_rows` row |
| Salary adjustment (`payroll-notes.md`) | a salary-ledger row |

An `employee_id` column would be null for all three, and a nullable subject on an audit
table is a subject nobody can rely on. It would also make *"everything that ever happened
to this record"* unanswerable for exactly the records where the question matters most.

**BR-AT4 — `old_label` / `new_label` hold the display text as it read at the time.**

Same pattern, same reason, as `employee_status_history` (`adr/0003` decision 8). Storing
only `department_id = 7` needs a join to render, and that join shows the department's name
**today**, not its name **then**. Renaming a department would retroactively rewrite the
audit trail, and **a record that changes retroactively is not a record**.

The labels are redundant for enum and scalar values (`CONFIRMED` / `CONFIRMED`,
`3200.00` / `3200.00`). That is accepted: one uniform row shape costs a few bytes and
avoids per-type branching in every reader.

**BR-AT5 — `employee_status_history` is *not* mirrored into `audit_logs`.**

The ledger is written by Employee Master (`employee-master.spec.md` §5.3) and read by the
audit report (§5.5). It is **not copied here**, and a service that writes both for one
status change is wrong.

This **corrects `employee-master.spec.md` §5.3**, which said the ledger was "also mirrored
to `audit_logs` (Phase 0)". That sentence contradicted `adr/0003` decision 8, which
rejects duplication on the ground that **two records of one fact will eventually
disagree** — the same reasoning that removed `CORE_ROLE` from the `change_type` set, that
rejected `secondary_company_id` (`adr/0003` decision 6), `is_enabled` and `primary_role`
(`adr/0003` decision 1), `hr_scope` (`adr/0003` decision 5), and `is_master_admin`
(`auth-rbac.spec.md` §3). A mirror is the same mistake with a different name: the copy is
the one that goes stale, and here it would go stale **inside the record whose whole value
is being trustworthy**.

**The merge is a read-side concern (§5.5).** `employee-master.spec.md` §7 already merges
`employee_status_history` and `employee_roles` on the Status History tab for the same
reason and with the same warning attached: *it must not tempt a writer into recording the
event twice to make the query simpler.* That warning now covers this table too.

§5.3 of `employee-master.spec.md` is corrected in the same commit as this spec.

**BR-AT6 — Both tables are append-only, and there is no exception.**

No update path, no delete path, no soft delete, no UI affordance, no service method, not
for Master Admin. A correction is a new row (`conventions.md` §3, the
`employee_status_history` pattern).

This is what makes BR-AT9's read permissions safe to grant: an `HR` who can read the log
still cannot alter what it says about them. **The one process that removes rows is the
retention sweep** (BR-AT11, §5.6), which has a single fixed predicate and is reachable only
from the scheduler.

### Writing

**BR-AT7 — `audit_logs` is written inside the same transaction as the action, and a
failed audit write rolls the action back.**

No queued job, no observer side effect, no after-commit hook. If the audit row cannot be
written, the change did not happen.

This is already what two accepted rules assume. `employee-master.spec.md` §5.7 requires
that a transfer failing mid-cascade leaves **neither** the transfer nor the audit row, and
`auth-rbac.spec.md` §5.7 puts the session-deletion audit write inside the freeze
transaction. The rule is stated here once so no module has to restate it.

The cost is accepted and is the point: an action whose audit record cannot be written is
an action nobody can later account for, and for a system holding salary, IC scans and
statutory filings, silently proceeding is the worse outcome.

**BR-AT8 — `security_events` is written *outside* the transaction, and it never blocks.**

A failure to write a security event is caught, logged to the **application file log**, and
the request continues.

**Authentication must not depend on a table write.** If it did, one database problem — a
full disk, a locked table, a failed migration, a connection limit — would make the system
**impossible to log into, including for the Master Admin who needs to log in to fix it.**
That is not a degraded system; it is a locked room with the key inside.

**Two consequences that must hold in the implementation:**

1. **Throttling works without this table.** `auth-rbac.spec.md` BR-A3's four tiers, and
   the counter that resets on successful login, are keyed on the account and stored by the
   Auth module. **The counter is never `SELECT COUNT(*) FROM security_events`** — that
   would reintroduce the dependency this rule removes, and it would make throttling fail
   open on exactly the fault that suppresses the log.
2. **A write failure is loud in the file log**, not swallowed. Silent loss of the security
   record is the failure mode this rule trades *for*; it is acceptable only if it is
   visible somewhere.

Note the asymmetry with BR-AT7 is deliberate and directional. Blocking a **data change**
on its audit row costs one rejected save. Blocking a **login** on its audit row costs the
whole system.

### Reading

**BR-AT9 — Who may read the trail:**

| Reader | `audit_logs` | `security_events` |
|---|---|---|
| Master Admin (`system_access = FULL`) | Everything | Everything |
| `HR` | Within their read scope, **salary rows filtered out** (BR-AT10) | Within their read scope |
| `ASSISTANT_DIRECTOR` | Within their read scope, **salary rows filtered out** | Within their read scope |
| `ACCOUNT` | Within their read scope, **including salary rows** | No |
| Everyone else | No | No |

Read scope is `ReadScopeResolver`'s (`auth-rbac.spec.md` §5.4) — derived from where the
account's employer sits in `companies.parent_company_id`, never stored, never overridden
(`adr/0004` decision 1). A subsidiary-employed `HR` reads one company's audit trail while
approving across the group; that is the two axes disagreeing by design
(`conventions.md` §2).

**Consequence for unattributed security events.** An event with a null `user_id` has no
account, therefore no employer, therefore no company — so it falls inside **no** narrower
read scope and is visible to **Master Admin only**. This follows from the table above
rather than adding to it, and it lines up with BR-AT11: those are exactly the rows that
expire at 90 days.

**Blocking `HR` from the audit log entirely was rejected.** The value of an audit trail
comes from **not being able to delete it, not from not being able to see it.** Both tables
are append-only, with no edit path and no delete path exposed anywhere (BR-AT6), so an HR
who reads the log cannot alter what it says about them. Hiding it would cost HR the
ability to investigate an ordinary incident — the routine, legitimate use — and would buy
nothing, because the person it would be protecting against is the person who cannot change
the record either way.

**BR-AT10 — Rows touching a salary field are filtered out of `HR` and
`ASSISTANT_DIRECTOR` reads, entirely.**

Not redacted, not shown as "value hidden", not aggregated. The row does not appear.

`adr/0003` decision 5 is unconditional: **only `ACCOUNT` reads salary, and no `HR` does at
any scope.** `adr/0004` decision 3 widens it only for accounts holding no roles at all
(`FULL`, `VIEW_ONLY`) — Master Admin and the Director were never the target. The target is
HR, and the line has not moved.

**The audit log is the easiest back door in the system to miss**, because it is the one
place where every value in the database is written down a second time, in a table nobody
thinks of as holding salary. A row reading

```
salary_ledger #482 · basic_salary · 3,200.00 → 3,800.00 · by Aminah · 3 Mar
```

discloses the salary as completely as the payroll screen does, and it does it from a
module whose permission table says nothing about money.

**A masked row is not a filtered row.** Showing the row with the values blanked still
discloses *that* this employee's salary changed, on what date, and by whom — which is
material, and which `ACCOUNT`-only means HR does not get. Hence "filtered out
**entirely**".

**Enforcement is a declaration, not a denylist grown by hand.** A model holding
salary-bearing fields **declares them**, and an architecture test asserts that every model
over a table carrying money columns has made a declaration — the same shape as
`adr/0005` decision 6's scope guard, adopted for the same reason: a denylist that a new
Payroll table forgets to join fails **open**, silently, and looks entirely normal doing
it. Mechanics in §5.4.

### Retention

**BR-AT11 — Retention.**

| Table | Rows | Kept |
|---|---|---|
| `audit_logs` | All | **Forever** |
| `security_events` | `user_id` **not null** — an attempt against an account that exists | **Forever** |
| `security_events` | `user_id` **null** — an attempt against a number that is in no account | **90 days** |

**This does not breach `CLAUDE.md` §3.** That rule forbids deleting data **for
performance** — "where reads get slow the answer is an index, or an archive table inside
the database, never a delete." Nothing here is deleted to make anything faster.

The distinction being drawn is between a **record** and **noise**. An attempt against a
number that has never existed in this system has **no subject**: no employee, no account,
no company, and no statutory retention period attached to anyone. It is not part of any
person's employment record and it never becomes evidence about anybody, because there is
nobody it is about. An attempt against a **real** account is the opposite on every count —
it is about a specific employee, and it is kept forever alongside everything else that is.

90 days is a `policy_configurations` value, not a literal (`conventions.md` §5).

**The sweep in §5.6 is the only process that removes a row from either table**, and it is
the single exception to BR-AT6. It is a scheduled command with one fixed predicate — not a
general delete capability with a filter, and not something reachable from a request.

## 5. Behaviour

### 5.1 Writing an audit batch

```
App\Services\Audit\AuditLogger
```

- `startBatch(): string` — generates the `batch_id` UUID, **once per transaction**, and
  holds it for the life of that transaction.
- `record(Model $subject, string $field, $old, $new, ?string $reason = null): void`
- `recordChanges(Model $subject, array $dirty, ?string $reason = null): void` — the usual
  entry point; one row per dirty attribute.

Rules the service enforces so no caller has to remember them:

- A `record()` call **outside a database transaction is rejected**, not quietly wrapped in
  one. BR-AT7 is the whole guarantee, and a call site that never opened a transaction is a
  call site where the action and its audit row can land separately.
- `batch_id` is taken from the transaction context. A second `startBatch()` inside an open
  batch returns the existing id rather than a new one — nested actions belong to the
  batch of the outermost one.
- `company_id` and `user_id` come from the authenticated context, **never** from method
  arguments and never from request input.
- Labels are resolved **at write time** (BR-AT4). A reader never joins to produce them.
- A no-op change writes nothing. `old_value === new_value` is not an audit row.

**No module writes to `audit_logs` directly.** A raw insert, or an `AuditLog::create()`
outside this service, is a review failure for the same reason a raw `employee_roles` query
is (`auth-rbac.spec.md` §5.5): it is the one place the transaction check and the batch id
get skipped.

### 5.2 Writing a security event

```
App\Services\Audit\SecurityEventLogger
```

- Called from `AuthenticationService` (`auth-rbac.spec.md` §5.1) and from the account
  lifecycle actions.
- Writes **outside** any transaction the caller may hold, and wraps the write so that a
  failure is caught, written to the application file log at error level, and swallowed as
  far as the request is concerned (BR-AT8).
- `identifier` is the **normalised** phone number (BR-A1), stored whether or not it
  matches an account. Normalising here matters: `012-345 6789` and `+60123456789` must
  group as repeated attempts against one number, not read as two.
- `user_id` is set when the identifier resolves to an account, null otherwise. **This
  column is the retention discriminator (BR-AT11)** — setting it defensively to some
  placeholder would silently convert a 90-day row into a permanent one.
- `company_id` is filled where resolvable and left null otherwise. It is never used to
  decide access.

⚠ **The failure path must be tested with the table actually broken**, not with a mocked
exception on the happy path. The rule this implements is "a database problem must not lock
everyone out", and only the first kind of test observes that (§8 tests 10–12).

### 5.3 Reading

```
App\Services\Audit\AuditLogReader
```

Every read goes through it. It applies, in order:

1. **Authorization** — the BR-AT9 table, via a Policy. `ACCOUNT`, `HR` and
   `ASSISTANT_DIRECTOR` are role checks and therefore need a `company_id`
   (`adr/0003` decision 1); Master Admin is `system_access = FULL`.
2. **Read scope** — `TenantScope` on `audit_logs` resolves to the account's read scope
   already; `security_events` carries no scope and is filtered here instead, explicitly,
   against the same resolver.
3. **The salary filter** (§5.4) — for `HR` and `ASSISTANT_DIRECTOR` only.

The order matters: the salary filter runs **last and unconditionally** for those two
roles, so no query path, export, count, or aggregate can reach a salary row by taking a
different route into the table.

### 5.4 The salary filter

A model holding salary-bearing fields declares them:

```php
protected array $salaryFields = ['basic_salary', 'allowance_amount'];
```

`AuditLogReader` excludes, for `HR` and `ASSISTANT_DIRECTOR`, every row whose
`(auditable_type, field)` pair appears in the declared set. The `(auditable_type, field)`
index exists for this query.

**An architecture test asserts the declaration exists** — every model over a table
carrying a money column either declares its salary fields or declares, explicitly, that it
has none. A model that declares neither fails the suite.

This is `adr/0005` decision 6's pattern, adopted deliberately rather than by analogy. That
ADR chose a guard test over review because **omission is the likely error and it gets
likelier over time**: the tables most at risk have not been written, and Phase 2's Payroll
will be built by someone who has this spec available but no reason to open it, because
nothing in the act of writing `Schema::create` prompts them to. Here the stakes are the
same shape — a Payroll table whose salary column is never declared leaks every salary
change in the group to HR, through a screen labelled "Audit Log", and **nothing errors**.

### 5.5 The audit report

The report reads **`audit_logs` and `employee_status_history`** and merges them
chronologically **on display** (BR-AT5). Each entry shows its source:

```
15 Jan 2026 · Basic salary  3,200.00 → 3,800.00        [audit_logs]        ← ACCOUNT only
01 Mar 2026 · Status → CONFIRMED                        [employee_status_history]
08 Aug 2026 · Company transfer  AIM → TURSENIA          [audit_logs]
```

Rows sharing a `batch_id` render as one entry with its fields listed beneath, so a
three-field save reads as one action and not three.

**This is the same read-side merge `employee-master.spec.md` §7 already performs** for
`employee_status_history` and `employee_roles`, and it carries the same warning: the merge
exists **so that the data need not be stored twice**. It must not be read as evidence that
one table should write into the other.

**The filter applies to the merged output, not only to the `audit_logs` half.** A salary
change reachable through the ledger's own history rows would defeat BR-AT10 as thoroughly
as reading it from `audit_logs` directly.

### 5.6 Retention sweep

```
App\Console\Commands\PruneSecurityEvents
```

Scheduled daily. One predicate, fixed in the command:

```
DELETE FROM security_events WHERE user_id IS NULL AND created_at < :cutoff
```

`:cutoff` is `now()` minus the `policy_configurations` retention value (BR-AT11). The
command **touches `audit_logs` never**, and it takes no filter arguments — a prune command
that accepts a `--where` is a delete capability with extra steps.

Each run logs the number of rows removed, so a sweep that suddenly deletes far more than
usual is visible.

## 6. Permissions

This module's own surface. Per-module rules for *what gets audited* live in those modules'
specs (§2).

| Action | Who |
|---|---|
| Read `audit_logs` | Master Admin; `ACCOUNT`, `HR`, `ASSISTANT_DIRECTOR` within read scope |
| Read salary rows in `audit_logs` | Master Admin; `ACCOUNT` — **never `HR` or `ASSISTANT_DIRECTOR`** |
| Read `security_events` | Master Admin; `HR`, `ASSISTANT_DIRECTOR` within read scope |
| Read unattributed `security_events` (null `user_id`) | Master Admin only |
| Export the audit report | Same rules as reading it, filter included |
| Edit or delete any row in either table | **Nobody, including Master Admin** (BR-AT6) |

`ACCOUNT` reads `audit_logs` and **not** `security_events`. It is the role that reads the
most data in the system and administers none of it (`auth-rbac.spec.md` §6); account
security is administration.

## 7. UI

Blade + Livewire 3. Two screens, deliberately separate — the same separation as the tables
(BR-AT1).

**Audit log** — filter by subject, actor, company, date range, action. Batches render as
one entry with their fields listed beneath (§5.5). Read-only, with **no edit or delete
affordance anywhere**, not greyed out and not hidden behind a permission — absent, so the
interface says the same thing the schema does.

**Security events** — filter by account, identifier, event type, date range. Repeated
failures against one identifier are grouped so a hundred overnight attempts read as one
line with a count, not a hundred lines to scroll past.

The audit report (§5.5) appears on the employee detail view as the merged timeline, next
to the existing Status History tab rather than replacing it.

## 8. Testing

Pest. **Mandatory for this module.** Not because money is computed here — none is — but
because these are the tables every other module's accountability rests on, and three of
their failure modes are silent: a salary row reaching HR, a security event quietly
unwritten, and an audit row that was never required in the first place because the caller
forgot the transaction.

**Shape**

1. A save changing three fields writes **three rows sharing one `batch_id`**; a save
   changing one writes one. A no-op save writes none.
2. Nested actions inside one transaction share **one** `batch_id`, not one each.
3. The subject is polymorphic — audit rows are written and retrieved for a `users` row and
   an `attendance_import_rows` row, neither of which has an `employee_id` (BR-AT3).
4. `old_label` / `new_label` are frozen: rename the referenced department **after** the
   audit row is written, re-read the row, and assert the label still shows the **old**
   name (BR-AT4). The failure this catches renders correctly on the day it ships.
5. Neither table accepts an update or a delete through any model or service path,
   including as Master Admin (BR-AT6).

5a. **The two tables hold the events the rule assigns them.** A failed login lands in
    `security_events` and produces **no** `audit_logs` row; the `TERMINATED` session
    deletion lands in `audit_logs`, **inside the freeze transaction**, and produces no
    `security_events` row (BR-A15, §3). Assert both — the boundary is "who the event is
    about", and an implementation that routes on "does it involve a login" fails exactly
    one of these.

**Not mirrored**

6. A `staff_status` change writes **one** `employee_status_history` row and **no**
   `audit_logs` row (BR-AT5). Assert both halves — asserting only the ledger write passes
   against an implementation that also mirrors.
7. The audit report shows that change exactly **once**, sourced from
   `employee_status_history` (§5.5).

**Transaction rules — the asymmetry, asserted in both directions**

8. A failing `audit_logs` write **rolls back the action** — the change is absent and so is
   the audit row (BR-AT7).
9. A `record()` call outside a transaction is **rejected**, not silently wrapped.
10. A failing `security_events` write **does not** roll back or reject anything —
    authentication completes, and the failure appears in the file log (BR-AT8).
11. **Login succeeds while `security_events` is unwritable.** The scenario is a Master
    Admin logging in to fix a database fault; if this test fails, the system locks its own
    administrator out of the repair.
12. **Throttling fires correctly while `security_events` is unwritable** — the BR-A3 tiers
    are unaffected, proving the counter does not read this table.

**Reading and the salary filter — the highest-risk area**

13. `ACCOUNT` sees a salary audit row within its scope; `HR` and `ASSISTANT_DIRECTOR`
    **do not see it at all** — not masked, not counted, absent from the result set
    (BR-AT10).
14. The same for an **AHS-employed** `HR`, whose read scope is the whole group. Scope
    width must not widen data rights — the two axes are separate
    (`conventions.md` §2), and this is the test that proves the implementation has not
    merged them.
15. A subsidiary-employed `HR` reads that subsidiary's audit rows only, while still
    approving across the group. Both halves asserted.
16. The filter holds on **export and on counts**, not only on the list view. An aggregate
    that reveals "3 salary changes this month" for an employee HR may not read is a leak
    with a smaller surface, not a different rule.
17. A model over a money-bearing table with **no salary-field declaration fails the
    architecture test** (§5.4). This is the test that catches the Payroll table nobody has
    written yet.
18. Someone holding none of the four qualifying positions reads nothing from either table.
19. An unattributed `security_events` row (null `user_id`) is invisible to `HR` and
    visible to Master Admin (BR-AT9).

**Retention**

20. A null-`user_id` event older than the configured window is pruned; one **inside** the
    window is not.
21. A non-null-`user_id` event of the **same age** is **not** pruned (BR-AT11). Assert
    alongside 20 in one test — the failure mode is a predicate that drops the `user_id`
    condition, and a test covering only the expiring row cannot see it.
22. The prune command never removes an `audit_logs` row, and the retention window is read
    from `policy_configurations`, not hardcoded.

**Scope**

23. `audit_logs` is tenant-scoped: a reader of company A cannot reach company B's rows.
24. `SecurityEvent` **declares its scope opt-out** and passes `adr/0005` decision 6's guard
    test on that basis. Removing the declaration must fail the suite — the distinction
    between "deliberately unscoped" and "someone forgot" is the entire value of that test.

## 9. Definition of Done

The full `conventions.md` §10 checklist — `optimize:clear`, syntax check, `route:list`,
`php artisan test`, `npm run build`, migration test against an **empty** database, and the
sensitive-file check.

Plus, specific to this module:

- `schema.md` updated in the same commit as each migration; no timestamp collisions
- **No direct write to either table outside `AuditLogger` / `SecurityEventLogger`** — grep
  and verify, as with `RoleChecker`
- **No read of `audit_logs` outside `AuditLogReader`** — the salary filter is only as good
  as the number of ways into the table
- `SecurityEvent` declares its scope opt-out; the `adr/0005` decision 6 guard test passes
- The salary-field architecture test is in the suite and passing
- The retention window resolves from `policy_configurations`
- **`MasterAdminContext::run()` now writes its reason**, closing the deferred half of
  `adr/0005` decision 5, and `auth-rbac.spec.md` §5.3's ⚠ note is discharged in the same
  commit
- No `updated_at`, `updated_by`, or `deleted_at` on either table

## 10. Resolved Decisions

Seven decisions, closed. Recorded with their reasoning so it survives.

**1. Two tables, not one.** `audit_logs` for data changes, `security_events` for
authentication events. The subjectless case is not a variant of a data change — a failed
login has no `old_value` and never will. Forcing both into one shape means every reader has
to know which columns are meaningful for which type, and **that rule would never get
written down**. See BR-AT1.

**2. One row per changed field, grouped by a `batch_id` UUID generated once per
transaction.** Not JSON: `conventions.md` §4 forbids unstructured storage where the system
must query against it, and *"who changed this salary, and when"* must be a `WHERE`, not a
scan. A forgotten `batch_id` produces a scattered display, not lost data — the right
failure mode for the softer property. See BR-AT2.

**3. `employee_status_history` is not mirrored here, and
`employee-master.spec.md` §5.3 is corrected.** That spec said the ledger was also mirrored
to `audit_logs`; it contradicted `adr/0003` decision 8, which rejects duplication because
**two records of one fact will disagree**. The audit report reads both tables and merges
them on display. §5.3 is corrected in the same commit as this spec. See BR-AT5, §5.5.

**4. `old_value` / `new_value` as `TEXT`, plus `old_label` / `new_label` for the display
text at the time.** Same pattern as `employee_status_history`: a join renders the name
**today**, not the name **then**, and a record that changes retroactively is not a record.

**`value_type` was proposed and rejected.** It is metadata that can be filled in wrong
without anyone noticing — nothing validates it against the value beside it, nothing breaks
when it drifts, and the only thing it buys is cosmetic formatting a reader can infer or do
without. A column whose errors are invisible and whose value is decorative is a column that
will be wrong and consulted anyway.

**5. Retention.** `audit_logs` forever. `security_events`: attempts against an account
that **exists** (`user_id` not null) forever; attempts against a phone number that is in no
account, **90 days**. `CLAUDE.md` §3 forbids deleting for **performance** — nothing here is
deleted to make anything faster. It distinguishes a record from noise: an attempt against a
number that never existed has **no subject** and therefore no statutory retention period,
because there is nobody it is about. See BR-AT11.

**6. Reading.** Master Admin sees everything. `HR` and `ASSISTANT_DIRECTOR` see within
their read scope, with rows touching a salary field **filtered out entirely** — `adr/0003`
decision 5 says no HR sees salary, and the audit log is the easiest back door in the system
to overlook, being the one table that writes down every value a second time. `ACCOUNT` sees
salary rows within its scope.

**Blocking `HR` from the log was rejected.** The value of an audit trail comes from **not
being able to delete it, not from not being able to see it** — both tables are append-only
with no edit and no delete path (BR-AT6), so a reader cannot alter what the log says about
them. Hiding it would cost the routine, legitimate investigation and buy nothing. See
BR-AT9, BR-AT10.

**7. Transactions, and the asymmetry between the two tables.** `audit_logs` is written
inside the same transaction as the action, and a failed audit write rolls the action back.
`security_events` is written outside any transaction and **never blocks** — a failure goes
to the file log and the request continues.

**Authentication must not depend on a table write.** If it did, one database problem would
make the system impossible to log into — including for the Master Admin who has to log in
to repair it. Throttling therefore works without reading this table (BR-A3's counter is the
Auth module's, keyed on the account), which is what makes the non-blocking write safe
rather than merely convenient. See BR-AT7, BR-AT8.

### What this spec closes elsewhere

**`adr/0005` decision 5 is implemented in half, and this spec closes the other half.**
`MasterAdminContext` already exists, must be entered on purpose, lifts the scope only
inside `run()`, restores on exit, and refuses a bypass with no stated reason. The **audit
write does not happen**, because `audit_logs` has no migration — so today
*"explicit, never ambient"* holds and *"audited"* does not.

That table was **deliberately not created by the tenant-scope work**, and the reason is
this document: `audit_logs` accepts writes from every module — auth, approvals, attendance
corrections, Director overrides, role grants — so its column shape was never a scoping
branch's decision to make. Settling it inside PR #10 would have been exactly the
code-before-spec pattern Principle #1 exists to prevent.

The seam is already in place. `run()` takes and holds the reason; when the migration lands,
the write is added there and nothing else about the class changes. `adr/0005` § Still open
stays accurate until then — the spec now exists, the migration does not.

## 11. Still open

Two questions this spec does not answer. Neither blocks approval of the rules above;
**the first blocks the `audit_logs` migration** and must be answered before it is written.

**1. What `company_id` does an audit row carry when its subject belongs to no company?**
⚠ **Blocks the migration** — it decides that column's nullability, and Principle #4 means
it cannot be softened later.

`audit_logs` is a business table with `TenantScope`, so `company_id` would ordinarily be
`NOT NULL`. But BR-AT3's polymorphic subject includes `users` rows, and **Master Admin and
Director accounts belong to no company by design** (`adr/0004` decision 4,
`auth-rbac.spec.md` §3) — a Master Admin changing another Master Admin's `system_access` is
an audited action (`auth-rbac.spec.md` §6) with no company to attribute it to.

The candidate answers are a nullable `company_id` where null means group-level and is
Master-Admin-only to read, or an actor-derived value, or `NOT NULL` with a designated
parent-company attribution. Each has a different failure mode under BR-AT9, and choosing
between them is a decision, not a detail — so it is recorded here rather than guessed at.
Related: `adr/0005` § Still open already asks whether `TenantScope` applies to `users` at
all, and the two questions should probably be answered together.

**2. Does `security_events` record the request IP address and user agent?**

Not decided, and deliberately not assumed. `auth-rbac.spec.md` BR-A3 makes a point of
throttling on the **account** rather than the IP — "an attacker changing IP must not get a
fresh allowance" — so nothing in the throttle needs it. But *"a hundred overnight failures
against the `ACCOUNT` holder's login"* (`schema.md` § `policy_configurations`) is a great
deal more actionable with an origin attached than without, and these columns cannot be
retrofitted onto events that have already passed. Personal-data retention cuts the other
way, and interacts with BR-AT11's 90-day rule for exactly the unattributed rows an IP would
matter most on.
