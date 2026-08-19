# Module Spec — Audit Trail

- **Phase:** 0 — Core Engine
- **Status:** **Accepted and partially implemented** — the write path, the read path and the
  retention sweep were all built 2026-08-12; the §5.5 audit report and the §7 UI are not

  > **⚠ Status corrected 2026-08-19.** This read *"Draft — awaiting approval. **No code until
  > this is approved** (`CLAUDE.md` Principle #1)"* while `AuditLogger`, `AuditLogReader`,
  > `AuthorshipContext` and `SecurityEventLogger` were all in `app/`, exercised by a green
  > suite. §10 records the write path being built on 2026-08-12 — the same day this line was
  > written. `AuditLogReader` (`c651d0b`) and `PruneSecurityEvents` (`2705bd5`) landed that
  > day too. The line was never revisited.
  >
  > **Principle #1 was not breached; the header was.** The spec existed and was followed. What
  > went stale is the sentence claiming it had not been approved, and a document that
  > misreports its own status is one nobody can use to answer whether code is authorised.
  > `conventions.md` §9.
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
  module's own spec states which of its actions produce an audit batch, and **why those
  fields**. Same division as `auth-rbac.spec.md` BR-A8: mechanism here, catalogue there.
  There is no prose catalogue of auditable actions in this document and none may be added.

  **One qualification, added 2026-08-12 with BR-AT13.** The *machine-readable* list of
  audited fields is a class here — `App\Support\Audit\AuditedFields` — because the
  architecture test has to read it from somewhere, and a list in markdown plus a copy in
  code would be two records of one fact. The registry holds the pairs; the **reasoning
  stays in the owning module's spec**, which references the registry instead of restating
  field names. Mechanism here, catalogue there, still.
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
| Actor | `user_id`, **NOT NULL** — every audited action has one (`adr/0017`) |
| Tenancy | `company_id`, **nullable**, with `SystemTenantScope` — a **third** scope class, see below |
| Mutability | Append-only: `created_at` only — no `updated_at`, no `updated_by`, no soft deletes |

**`company_id` is nullable, `NULL` means "a system-level event", and neither existing scope
class is correct for it.** `TenantScope` would hide those rows from everyone including
Master Admin — whose own actions they mostly are. `SharedTenantScope` would show them to
everyone including a subsidiary `HR`. The table therefore gets `SystemTenantScope`:

```
company_id IN (:read_scope)
OR (company_id IS NULL AND the account has system_access = FULL)
```

Full reasoning, and the `adr/0005` decision 6 guard test this obliges, in §11.

**`user_id` is NOT NULL, and until 2026-08-19 this table said nothing about it either way.**

> **⚠ Added 2026-08-19 — `adr/0017`.** The row above read `| Actor | user_id |`. Two words.
> The same table spells out nullability for three other columns — `old_value` / `new_value`
> **TEXT, nullable**, `company_id` **nullable** — and gives `company_id` a paragraph, a fenced
> predicate, and a pointer to §11's full reasoning. The actor got none of it.
>
> **The migration then decided it.** `user_id` was created `->nullable()` under one clause —
> *"Nullable for console and system-initiated writes"* — naming a console writer that did not
> exist, since `AuditLogger` had not been written. A gap in the spec became a decision in a
> migration, and nothing contradicted it because there was nothing to contradict.

**There is no audited action without an actor.** Every row records something somebody or
something did. `NULL` here is never a fact about the action; it is always a failure to
resolve one — and it is indistinguishable from the *"system-level event"* meaning that
`company_id` carries legitimately on the same row.

**This is deliberately asymmetric with `company_id` directly above.** A `NULL` company is a
real state: a Master Admin changing another Master Admin's `system_access` has no company to
name. A `NULL` actor is not the parallel case, because a session-less write and a
company-less actor are different absences. **Never infer one column's nullability from its
neighbour's.**

BR-AT14 is how this is enforced at write time; the column constraint is what holds if
anything reaches the table another way.

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
| Origin | `ip_address` and `user_agent`, both **nullable** — see §11 |
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
> **It carries no scope class at all — not `TenantScope`, not `SharedTenantScope`, and not
> the `SystemTenantScope` `audit_logs` uses — and `company_id` cannot be `NOT NULL`.** A
> security event happens **before authentication**: there is no
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
>
> **`SystemTenantScope` does not apply here either**, and the reason is the same one: it
> reads the account's `system_access`, and at the moment a failed login is written there may
> be no account. Two nullable `company_id` columns in this module, two different answers
> (§11).

**Indexes**

- `(user_id, created_at)` — the per-account history, and the retention sweep, which is
  exactly `user_id IS NULL AND created_at < :cutoff`
- `(identifier, created_at)` — repeated attempts against one number that matches no account
- `(event_type, created_at)` — "all lockouts this month"

**Migration rules**

- Two migrations, timestamps spaced one minute apart. Verify with
  `ls database/migrations | sort` before committing (`conventions.md` §6).
- **`audit_logs.company_id` is present from the creating migration and is nullable** (§11).
  Principle #4 is about the **column**, not its nullability — the same reading that lets
  `branches.company_id` be nullable and still satisfy the rule (`schema.md` § Notes Carried
  From AHS Audit).
- **`SystemTenantScope` must exist and be recognised by the `adr/0005` decision 6 guard
  test before the `AuditLog` model lands**, or the model fails the suite. It is a new class
  in `App\Models\Scopes`, not a variant of an existing one (§11).
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

**⚠ The caller must not wrap this write in a transaction, and the logger cannot enforce
that.** It writes on the ordinary connection, so a write made inside a caller's transaction
would roll back with it — which is exactly what BR-AT8 is trying to avoid. Nothing in the
authentication path opens one today, and a second database connection was not worth its
cost to make the rule structural rather than stated. Recorded as a limitation rather than
claimed as a guarantee.

Note the asymmetry with BR-AT7 is deliberate and directional. Blocking a **data change**
on its audit row costs one rejected save. Blocking a **login** on its audit row costs the
whole system.

> **Numbering note.** BR-AT12 and BR-AT13 below were decided on 2026-08-12, after the
> reading and retention rules were written. They are numbered by date of decision rather
> than renumbered into section order — the migrations, `schema.md` and `conventions.md`
> already cite these identifiers, and renumbering would silently repoint every one of them.

**BR-AT12 — `batch_id` is bound to the database transaction.**

It is generated when the transaction opens and released when the transaction ends, on
commit or on rollback alike. **The batch boundary *is* the transaction boundary** — not a
separate span a caller opens and closes.

There is no second concept to keep aligned, and that is the whole reason. BR-AT7 already
requires the audit rows to be written inside the same transaction as the action, so **the
transaction is already the action's boundary.** A batch defined independently of it would
be a second answer to "what counts as one action", and the two would drift — not loudly,
but by degrees: a batch left open across two transactions, or closed halfway through one,
produces a display that groups the wrong rows while every row remains individually
correct. Nothing errors. This is the same objection that rejected mirroring
(BR-AT5), a stored read-scope override (`adr/0004` decision 1), and `is_enabled`
(`adr/0003` decision 1).

**Nested transactions: the outermost one is the boundary.** A savepoint commit does not
release the batch — an inner action running inside an outer one belongs to the outer
action's batch, because that is the unit that either lands or does not.

**⚠ Therefore a write outside a transaction is an ERROR, not a permitted case.**

`AuditLogger` **rejects it and throws**. It does not quietly mint a single-use UUID and
carry on, and the difference matters more than it looks: a silently-minted batch produces a
one-row batch that is **indistinguishable from a legitimate single-field change**. The one
fact worth knowing — that this write was made outside the transaction that BR-AT7 requires,
so the action and its audit row could land separately — would be erased at exactly the
moment it was created. A fallback that hides its own failure mode is worse than no
fallback.

**BR-AT13 — every Action calls `AuditLogger` explicitly.**

There is no trait, no model observer, no `saved` hook, and none may be added.

**This is BR-AT7's reasoning applied one level up.** An observer knows *what* changed. It
does not know *why*, and `reason` is a large part of why this table is worth keeping — a
Master Admin bypass, a Director decision entered as a manual override, and a transfer
performed by HR because the usual actor was unavailable are all distinguishable only by
what the actor said. An observer also cannot name the `action`, and it would audit **every**
write indiscriminately: imports, seeders, backfills, test factories.

The cost is real and accepted: an Action that forgets to call the logger produces no audit
row and no error.

**The architecture test, and exactly what it is worth**

The canonical list of audited fields is a class, not prose:

```
App\Support\Audit\AuditedFields
```

The specs — this one and each module's — **reference it rather than restating it**. A list
in markdown plus a copy in code would be two records of one fact, and the copy is the one
that goes stale; the module spec says *which* fields belong there and why, the registry
**is** the list. This is the one part of the per-module catalogue that lives here (§2).

The test asserts, for every `(model, field)` pair in the registry:

1. some Action class handles that field, and
2. that Action **declares itself an audit writer**, by an `AUDITS` constant naming the
   pairs it is responsible for.

**✅ What this catches:** a field added to the registry — because a module spec said it must
be audited — with **no Action behind it at all**. That is the realistic Phase 2 failure: the
spec grows, the code does not.

**❌ What this does NOT catch:** an Action that declares `AUDITS` correctly and then **never
calls the logger**. The declaration is a promise, and nothing here verifies the promise was
kept. A static test cannot: the call happens at runtime, inside a branch, possibly behind a
condition.

**That limitation is stated rather than papered over, because a guard test that looks
stronger than it is, is worse than a weaker one that is honestly labelled** — it stops
people looking for the check that is actually missing. What closes the gap is a per-Action
feature test asserting the rows appear, and **those belong to the module that owns the
Action**, alongside the rest of its behaviour. Each module spec must require them.

⚠ **The registry is filled, and the architecture test is doing real work.**

> **⚠ Corrected 2026-08-19.** This read that the registry was *"empty today, because no Action
> exists anywhere in the codebase yet"*, that an architecture test over an empty set *"passes
> forever while checking nothing"*, and that Employee Master *"is the first module that will
> fill it."* All three were true when written and none is now. `AuditedFields::FIELDS` lists
> seven fields on `Employee` — `staff_status`, `employee_no`, `position_id`, `department_id`,
> `level`, `company_id`, `superseded_at` — and six on `User` — `phone_no`,
> `password_changed_at`, `locked_until`, `activation_expires_at`, `system_access`,
> `superseded_at`. Dated comments record `company_id` joining on 2026-08-13 and
> `superseded_at` on 2026-08-17. Four Employee Actions declare matching `AUDITS` constants;
> six Auth Actions call the logger.
>
> The empty-set warning was correct and is kept in this note rather than deleted, because it
> is the reason the registry is asserted from both directions. The rule it carried — the test
> **fails on an empty registry** unless the registry declares itself intentionally empty and
> says until when — stands unchanged in §8 test 35 and §9. `conventions.md` §9.

**BR-AT14 — a write with no resolvable actor is refused.**

`AuditLogger` resolves the actor in three tiers and **throws** if none is found:

1. `AuthorshipContext`, if active
2. `auth()->id()`
3. `App\Exceptions\Audit\MissingAuthorshipActorException`

**Context first**, for the reason `AuthorshipObserver` already gives: a seeder running while
somebody happens to be authenticated must attribute to the actor it named, not to whoever
holds the session.

**This closes an asymmetry between two services answering one question.**
`AuthorshipObserver` throws when no actor can be resolved — `adr/0009` decision 2 refuses a
silent `NULL`, and decision 3 made `created_by` / `updated_by` NOT NULL, dropping the
development database to do it. `AuditLogger` wrote `NULL` and carried on. One absence, two
behaviours, and the fail-open one was in the table whose entire value is answering *who*.

⚠ **Six Actions could already produce an actorless row**, and none of them is new:
`CreateMasterAdmin`, `RemoveMasterAdmin`, `ResetAccountPassword`, `UnlockAccount`,
`ChangeLoginUsername`, `RegenerateActivationToken`. The dividing line is
`AuthorshipObserver::MODELS`, and it falls on **`users` versus everything else** — `users`
carries no `created_by` / `updated_by`, so no observer refuses a write to it. Every Action
touching an observed model was already held closed; the eight in `app/Actions/Auth/` were
not. Nothing in production reaches them without a session today, but that is **caller
layout, not enforcement** — `RedeemActivationToken`'s route sits outside the `auth` group by
design.

**The check is made *before* the no-op exit, and the placement is part of the rule.** §5.1
already refuses to write when `old_value === new_value`. If the actor check sat below it,
whether a caller failed would depend on **whether the data happened to change** — a caller
making only no-op calls passes in testing and fails in production the first time a value
actually moves. Above it, the failure is deterministic.

⚠ **A caller holding a named actor enters `AuthorshipContext` with it; it does not pass one.**
`RemoveMasterAdmin` requires `User $actor` and will still throw under this rule without a
session, which reads like a defect and is not. That parameter answers *"who requested this
removal"* — it exists to refuse self-removal. It does not answer *"who is this process acting
as."* Accepting it into `record()` would open the channel §5.1's *never from method
arguments* exists to close. `AuthorshipContext` is process context: set at a boundary, naming
a real `User`, refusing to run without a stated reason.

**Same shape as BR-AT7 and BR-AT12, and for the same reason.** All three are preconditions
checked before anything is written, all three throw rather than substituting a value, and all
three refuse the fallback that would hide its own failure. A `NULL` actor is the same mistake
as a silently-minted `batch_id`: a row that looks ordinary while the one fact worth knowing
about it has been erased.

> **Numbering note.** BR-AT14 was decided on 2026-08-19, after BR-AT12 and BR-AT13, and is
> placed by topic under the same convention recorded above.

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

- `record(string $action, Model $subject, string $field, $old, $new, ?string $reason = null): void`
- `recordChanges(string $action, Model $subject, array $changes, ?string $reason = null): void`
  — the usual entry point; one row per changed attribute, `['field' => [$old, $new]]`.
- `currentBatchId(): ?string` — the batch in effect, or null outside a transaction. For
  tests and for assertions; not a way to start one.

> **⚠ `$action` corrected 2026-08-12.** These signatures previously omitted it, while §3
> and `schema.md` both make `audit_logs.action` **NOT NULL** — the spec contradicted itself
> and no call could have satisfied both. It is the **first** parameter because BR-AT13 makes
> the calling Action the thing being named: `employee.transfer`,
> `master_admin.scope_bypass`, `employee_role.grant`.
>
> There is deliberately **no `startBatch()`**. Under BR-AT12 the batch is opened by the
> **transaction**, not by a caller — a method that appeared to start one would be the second
> concept that rule exists to prevent.

Rules the service enforces so no caller has to remember them:

- A call **outside a database transaction throws**
  `App\Exceptions\Audit\AuditWriteOutsideTransactionException` (BR-AT12). It is not
  wrapped in an implicit transaction and not given a throwaway batch id.
- `batch_id` comes from the transaction (BR-AT12): generated on the first write inside it,
  reused by every later write in the same transaction, released when it commits **or rolls
  back**. Nested transactions belong to the outermost batch.
- `company_id` comes from the authenticated context, **never** from method arguments and
  never from request input.
- `user_id` comes from `AuthorshipContext` if one is active, otherwise from the
  authenticated context, and **never** from method arguments or request input (BR-AT14). A
  caller with no session and no context gets
  `App\Exceptions\Audit\MissingAuthorshipActorException` — **the write is not attempted**,
  the same shape §8 item 28 already asserts for BR-AT12.

  > **⚠ Amended 2026-08-19 — `adr/0017`.** This read *"`company_id` and `user_id` come from
  > the authenticated context, **never** from method arguments and never from request
  > input."* The prohibition is unchanged and applies to both columns: no caller may name an
  > actor. What changed is that a **process boundary** may declare one, which the single
  > sentence had no room to express. Splitting the bullet is what makes the two facts about
  > `user_id` — where it comes from, and what happens when it cannot be found — both
  > statable.
- Labels are resolved **at write time** (BR-AT4). A reader never joins to produce them. A
  subject may implement `auditLabel(string $field, mixed $value): ?string` to render a
  foreign key as the text it stood for **then**; without it the label is the value's own
  string form, which BR-AT4 already accepts as redundant-but-uniform for enums and scalars.
- A no-op change writes nothing. `old_value === new_value` is not an audit row. ⚠ **The
  actor check in BR-AT14 runs before this**, so a no-op call with no resolvable actor still
  throws.
- ⚠ **The logger never opens a transaction of its own.** Doing so would satisfy its own
  precondition and defeat BR-AT7 — the action would still be able to land without its audit
  row. The caller's transaction is the guarantee; the logger only refuses to work without
  one.

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
  matches an account. Normalising matters: `012-345 6789` and `+60123456789` must
  group as repeated attempts against one number, not read as two.

  ⚠ **The logger does not normalise; the caller passes an already-normalised value.**
  BR-A1 requires **one** normaliser, called by both the login attempt and the employee
  form, and it does not exist yet — the Auth module owns it. Implementing a second one here
  would be the divergence that rule exists to prevent. Until `AuthenticationService` lands,
  this is a stated contract with nothing enforcing it.
- `user_id` is set when the identifier resolves to an account, null otherwise. **This
  column is the retention discriminator (BR-AT11)** — setting it defensively to some
  placeholder would silently convert a 90-day row into a permanent one.
- `company_id` is filled where resolvable and left null otherwise. It is never used to
  decide access.
- `ip_address` and `user_agent` are taken from the request and stored **verbatim, unparsed**
  (§11). Null outside an HTTP context. **Neither is ever read back into a decision** — not
  by the throttle, not by a policy, not by a lockout check.

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
2. **Read scope** — `SystemTenantScope` on `audit_logs` resolves it already, including the
   `NULL` rows Master Admin alone may see (§11); `security_events` carries **no** scope and
   is filtered here instead, explicitly, against the same resolver, with null-`user_id`
   rows restricted to Master Admin (BR-AT9).
3. **The salary filter** (§5.4) — for `HR` and `ASSISTANT_DIRECTOR` only.

The two tables reaching the same place by different routes is deliberate and is the reason
this service exists: one is enforced by a global scope, the other cannot be, and a caller
querying either model directly would get one of them wrong.

The order matters: the salary filter runs **last and unconditionally** for those two
roles, so no query path, export, count, or aggregate can reach a salary row by taking a
different route into the table.

> **⚠ Dependency: `RoleChecker` (`auth-rbac.spec.md` §5.5) must exist first.** Step 1 asks
> "does this account hold `ACCOUNT` / `HR` / `ASSISTANT_DIRECTOR` at this company", which is
> a read of `employee_roles` — and that spec is explicit that **no caller may query
> `employee_roles` directly**, because a raw query is the one place the
> `revoked_date IS NULL` filter gets omitted, which returns revoked authority as current.
>
> That service is specified and Accepted but was not written when the Auth module's own code
> was deferred, so **this module builds it**. It is `auth-rbac.spec.md`'s to own; it lands
> here only because this is the first reader that needs it. A revoked `ACCOUNT` role must
> not read salary rows, and nothing else would enforce that.

### 5.4 The salary filter

A model holding salary-bearing fields declares them:

```php
public const SALARY_FIELDS = ['basic_salary', 'allowance_amount'];
```

> **⚠ Changed from `protected array $salaryFields` — 2026-08-12.** A public constant, for
> two reasons. It matches the declaration pattern the guard tests already read —
> `TENANT_SCOPE_EXEMPT` on `EmployeeRole` and `SecurityEvent`, `AUDITS` on an Action — so
> there is one way to say "this model declares something the architecture checks". And a
> `protected` property is unreadable without reflection, which would put the salary filter's
> correctness behind the most fragile mechanism available to it.

`AuditLogReader` excludes, for `HR` and `ASSISTANT_DIRECTOR`, every row whose
`(auditable_type, field)` pair appears in the declared set. The `(auditable_type, field)`
index exists for this query.

**The filter is implemented once and called nowhere else.** It lives in
`App\Support\Audit\SalaryFields` and is applied by `AuditLogReader` — the same shape as
`RoleChecker::canReadSalary()` in `auth-rbac.spec.md` §5.5, and for the same reason: a rule
repeated per caller is a rule one caller will get wrong, silently. No other class may test a
field for salariness.

**An architecture test asserts the declaration exists** — every model over a table
carrying a money column either declares its salary fields or declares, explicitly, that it
has none (`SALARY_FIELDS = []`). A model that declares neither fails the suite.

⚠ **No table carries a money column today**, because Payroll is Phase 2. The test would
therefore check nothing, so it **fails unless `SalaryFields` declares that emptiness and
says which module ends it** — the same guard `AuditedFields` carries for BR-AT13, for the
same reason: an architecture test over an empty set passes forever while checking nothing.

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

**The value is read from the parent company's row**, key
`audit.security_events.unattributed_retention_days`.

> **⚠ This one setting is not per-company, and `policy_configurations.company_id` is
> NOT NULL.** The rows being pruned are precisely those with **no company** — an attempt
> against a number that is in no account has no employer to inherit a policy from — so
> "per company" has nothing to attach to. Reading the **parent** (`parent_company_id IS
> NULL`) is the group-level answer the schema can express today, and it keeps the number out
> of code as `conventions.md` §5 requires.
>
> A subsidiary row for this key is **ignored, not merged**: two answers to a group-wide
> question is the drift this project rejects everywhere else. If the group ever needs
> genuinely global settings, that is a `policy_configurations` change and an ADR — not a
> second lookup path bolted on here.

**The command refuses to run rather than guess.** If the key is missing, or is not a
positive integer, it **aborts with a non-zero exit and deletes nothing**. A default
compiled into the command would be a second source for a number `conventions.md` §5 says
must live in configuration — and the failure mode of guessing here is deleting rows that
should have been kept.

**Scheduled, never run on demand from a request.** Registered in `routes/console.php`
(Laravel 12 has no `Kernel::schedule`), and it is the only process permitted to remove a
row from either table (BR-AT6).

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
12a. **`ip_address` and `user_agent` are recorded on a failed login and stored verbatim**,
    including a hostile or absent user agent, which must persist rather than be rejected or
    normalised (§11). Assert also that **changing the IP between attempts does not reset the
    throttle** (BR-A3) — the columns are recorded and never consulted, and that is the whole
    of their contract.

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
24. **`SystemTenantScope`, all three of its behaviours, in one test** (§11): a row whose
    `company_id` is in the reader's scope is visible; a row belonging to another company is
    not; and a **`company_id IS NULL` row is visible to Master Admin and to nobody else** —
    asserted against an AHS-employed `HR`, whose read scope is the whole group and who must
    still not see it. Testing only the first two passes against plain `TenantScope`, and
    testing only the third passes against `SharedTenantScope`.
25. A Master Admin's own tenant-scope bypass (`adr/0005` decision 5) writes a `NULL`-company
    row **that the Master Admin can then read outside `MasterAdminContext`**. The point of
    the write is accountability; a row nobody can read afterwards satisfies the letter of
    "audited" and none of its purpose.
26. `SecurityEvent` **declares its scope opt-out** and passes `adr/0005` decision 6's guard
    test on that basis. Removing the declaration must fail the suite — the distinction
    between "deliberately unscoped" and "someone forgot" is the entire value of that test.
27. **`AuditLog` declaring `SystemTenantScope` passes the guard test**, and a model
    declaring no scope at all still fails it. The third class must be **recognised** by that
    test, not exempted from it (§11).

**The batch boundary (BR-AT12)**

28. **A write outside a transaction throws** `AuditWriteOutsideTransactionException`, and
    **writes no row**. Assert both halves: a logger that throws *after* inserting has still
    broken BR-AT7.
29. **Every write inside one transaction shares one `batch_id`**, across more than one
    subject and more than one model — the batch is the transaction, not the record.
30. **Two sequential transactions produce two different `batch_id` values.** This is the
    test that proves the batch is *released*; without it a logger that generates once per
    process passes test 29 forever.
31. **A rolled-back transaction releases the batch too**, and the next transaction gets a
    fresh id. Rollback is the path that leaks a stale batch id if only the commit path
    resets.
32. **Nested transactions share the outermost batch**, and the inner commit does **not**
    release it — a savepoint commit is not the action landing.
33. **A rollback takes the audit rows with it** (BR-AT7), asserted through the logger rather
    than by hand.
34. A no-op change (`old === new`) writes no row, and `currentBatchId()` is null outside a
    transaction.

**The authorship guard (BR-AT13)**

35. **The registry is not silently empty.** With `AuditedFields` empty and no
    intentionally-empty declaration, the architecture test **fails**. An architecture test
    over an empty set otherwise passes forever while checking nothing.
36. A `(model, field)` pair in the registry with **no Action declaring it** fails the test,
    with the pair named in the message.
37. An Action declaring a pair **not** in the registry fails too — the registry is the
    canonical list, and an Action auditing something nobody wrote down is the same drift in
    the other direction.

⚠ **No test asserts that a declaring Action actually calls the logger.** That is BR-AT13's
stated limitation, not an omission here — a static test cannot observe a runtime call inside
a branch. The per-Action feature tests that close it belong to the modules owning those
Actions.

**The actor precondition (BR-AT14)**

38. A write with no session and no `AuthorshipContext` **throws**
    `MissingAuthorshipActorException`. ⚠ **Verify it fails before trusting it** — the
    assertion is worthless if the harness authenticates by default
    (`conventions.md` §9).
39. The throw happens **before** the no-op exit: `$old === $new`, no actor, still throws.
    This is the assertion that proves the placement in BR-AT14, and it is the one an
    implementation is most likely to get wrong while passing test 38. ⚠ **Verify it fails
    before trusting it** (`conventions.md` §9).
40. Inside `AuthorshipContext`, the row carries the **context's** actor and not the
    authenticated user — asserted with **both** present, since only that ordering
    distinguishes tier 1 from tier 2.
41. Outside the context with a session, the row carries `auth()->id()`. Unchanged
    behaviour, asserted so the amendment cannot silently break the ordinary path.
42. Every one of the six Actions in `app/Actions/Auth/` that calls `AuditLogger` writes a
    row with a non-null `user_id`. ⚠ **Naming a concrete id, never `auth()->id()`** — an
    assertion comparing the column to its own source passes when both are `null`, which is
    the vacuous form `conventions.md` §9 records three times and which
    `ChangeEmployeeAssignmentTest:103` currently takes. **Verify it fails before trusting
    it.**

## 9. Definition of Done

The full `conventions.md` §10 checklist — `optimize:clear`, syntax check, `route:list`,
`php artisan test`, `npm run build`, migration test against an **empty** database, and the
sensitive-file check.

Plus, specific to this module:

- `schema.md` updated in the same commit as each migration; no timestamp collisions
- **`App\Models\Scopes\SystemTenantScope` exists, `AuditLog` declares it, and the
  `adr/0005` decision 6 guard test recognises it as a third valid declaration** — not as an
  exemption. `conventions.md` §2 and `adr/0005` decision 6 updated to match (§11)
- **No direct write to either table outside `AuditLogger` / `SecurityEventLogger`** — grep
  and verify, as with `RoleChecker`
- **No read of `audit_logs` outside `AuditLogReader`** — the salary filter is only as good
  as the number of ways into the table
- `SecurityEvent` declares its scope opt-out; the `adr/0005` decision 6 guard test passes
- The salary-field architecture test is in the suite and passing
- **`AuditLogger` throws outside a transaction and never opens one** (BR-AT12) — grep for
  `DB::transaction` inside the service and verify there is none
- **`App\Support\Audit\AuditedFields` is the only list of audited fields**, and no module
  spec restates its contents (BR-AT13)
- **The BR-AT13 architecture test fails on an empty registry** unless the registry declares
  itself intentionally empty and says until when
- The retention window resolves from `policy_configurations`
- ✅ **`MasterAdminContext::run()` writes its reason** — done 2026-08-12, closing the
  deferred half of `adr/0005` decision 5. That ADR's decision-5 note, its § Still open entry,
  and `auth-rbac.spec.md` §5.3's ⚠ note were all discharged in the same commit
- **The salary filter has exactly one implementation** — `SalaryFields` for *is this field
  salary*, `RoleChecker::canReadSalary` for *may this account read it*. Grep and verify no
  second copy of either question
- No `updated_at`, `updated_by`, or `deleted_at` on either table

## 10. Resolved Decisions

Nine decisions, closed. Recorded with their reasoning so it survives. Decisions 8 and 9
were taken on 2026-08-12, when the write path was built.

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

**8. `batch_id` is bound to the database transaction** — generated when it opens, released
when it commits or rolls back. **The batch boundary is the transaction boundary**, because
decision 7 already puts the audit write in the same transaction as the action, so the
transaction is *already* the action's boundary. A separately-managed batch would be a second
answer to "what counts as one action", and the two would drift silently: rows grouped
wrongly while each row stays individually correct, and nothing erroring.

**A write outside a transaction is therefore an ERROR, not a permitted case.** `AuditLogger`
throws; it does not quietly mint a single-use UUID, because a silently-minted batch is
**indistinguishable from a legitimate one-field change** — it erases the one fact worth
knowing at the moment it is created. See BR-AT12.

**9. Every Action calls the logger explicitly.** No trait, no observer, no `saved` hook.
This is decision 7's reasoning one level up: an observer knows **what** changed but not
**why**, and `reason` is much of why this table is worth keeping. It could not name the
`action` either, and it would audit every write indiscriminately — imports, seeders,
backfills, factories.

Protected by an architecture test with a **deliberately honest scope**.
`App\Support\Audit\AuditedFields` is the canonical list of audited fields — a class, not
prose, so there is no markdown copy to go stale — and the test asserts every pair in it is
claimed by an Action that declares itself an audit writer.

**It catches a field added without an Action behind it. It does not catch an Action that
declares the field and never calls the logger** — a static test cannot observe a runtime
call. That limit is written into the spec rather than left implied, because **a guard that
looks stronger than it is stops people looking for the check that is actually missing.**
The per-Action feature tests that close it belong to the modules owning those Actions. See
BR-AT13.

### What this spec closes elsewhere

**✅ `adr/0005` decision 5 is now satisfied in full — closed 2026-08-12.**
`MasterAdminContext` must be entered on purpose, lifts the scope only inside `run()`,
restores on exit, refuses a bypass with no stated reason — and **now writes the bypass to
`audit_logs`**: the actor, the reason, and a `tenant_scope: scoped → bypassed` row against
the acting account. *"Explicit, never ambient"* and *"audited"* both hold.

The write happens **before** the callback and in its own transaction, because the bypass
happened whether or not the work inside it succeeded, and **`run()` now requires an
authenticated account** — a bypass nobody can be attributed to is the ambient bypass that
decision rejects. The paragraphs below record why the gap existed, since the reasoning is
worth keeping.

That table was **deliberately not created by the tenant-scope work**, and the reason is
this document: `audit_logs` accepts writes from every module — auth, approvals, attendance
corrections, Director overrides, role grants — so its column shape was never a scoping
branch's decision to make. Settling it inside PR #10 would have been exactly the
code-before-spec pattern Principle #1 exists to prevent.

The seam is already in place. `run()` takes and holds the reason; when the migration lands,
the write is added there and nothing else about the class changes. `adr/0005` § Still open
stays accurate until then — the spec now exists, the migration does not.

## 11. Resolved After Drafting

### Closed — `audit_logs.company_id` is nullable, and it needs a **third** scope class

**Decided 2026-08-12.** This question was flagged as blocking the migration, because it
decides the column's nullability and Principle #4 will not let anyone soften it later. It
is answered.

**`audit_logs.company_id` is nullable, and `NULL` means "a system-level event".** It is a
meaningful value, not missing data — the same status `NULL` carries on
`branches.company_id` (`adr/0002` decision 1), and for the same reason: there is a real
thing it says.

BR-AT3's polymorphic subject includes `users` rows, and **Master Admin and Director
accounts belong to no company by design** (`adr/0004` decision 4, `auth-rbac.spec.md` §3).
A Master Admin changing another Master Admin's `system_access` is an audited action
(`auth-rbac.spec.md` §6) with no company to attribute it to. So is a tenant-scope bypass
entered from `MasterAdminContext` (`adr/0005` decision 5) — the very write §1 says this
spec exists to make possible. `NOT NULL` cannot represent either without inventing an
attribution that is not true.

**Both existing scope classes are wrong for this table, in opposite directions:**

| Scope | On a `NULL` row | Why that is wrong here |
|---|---|---|
| `TenantScope` | Hidden from **everyone**, Master Admin included | Master Admin's own actions are the ones that most need to be visible. The scope would hide exactly the rows that exist to hold power to account |
| `SharedTenantScope` | Visible to **everyone** in any scope | A subsidiary-employed `HR` would read every group-level administrative action. `NULL` means shared on `branches` because a department is a name and a place; here it means *nobody's company*, which is the opposite of *everybody's* |

`SharedTenantScope` failing here is worth stating plainly: **the two tables use `NULL` for
two different things.** On `branches` it means *available to all companies*. On
`audit_logs` it means *attributable to no company*. Reusing the class because the column
is nullable in both places would be reading the type and ignoring the meaning.

**The required behaviour:**

```
App\Models\Scopes\SystemTenantScope

    company_id IN (:read_scope)
    OR (company_id IS NULL AND the account has system_access = FULL)
```

- A row with a `company_id` **inside the account's read scope** behaves exactly as under
  `TenantScope` — including the salary filter, which is a separate pass and is not affected
  (BR-AT10, §5.3).
- A row with `company_id IS NULL` is visible to **Master Admin only**.

**The `FULL` check is deliberately not routed through read scope**, and this is the case
`adr/0005` decision 5 already anticipates: *"the two come apart the moment read scope
cannot express something."* A `FULL` account's read scope resolves to every **company**,
and a `NULL` row belongs to none — so no set of company ids, however complete, can include
it. The condition has to name `system_access = FULL` directly.

Inside `MasterAdminContext` the scope lifts entirely, as it does for every other model, and
every row is visible. Outside it, a Master Admin sees all companies **and** the system-level
rows, by the ordinary mechanism. That remains *scoped*, not bypassed.

**Consequences, all of which land in the same commit as the migration:**

- `adr/0005` decision 6's guard test asserts that every model over a `company_id` column
  declares `TenantScope`, `SharedTenantScope`, **or a documented opt-out**. `AuditLog`
  declares none of those, so **it fails the suite until the third class is recognised.**
  `adr/0005` decision 6 carries an amendment note for this, added in the same commit as
  this decision.
- `conventions.md` §2 lists the scope classes and their carve-outs and gains a fourth entry.
- The third class is for **this table**. `SharedTenantScope` was restricted to `branches`
  and `departments` "and to nothing else without an ADR" (`adr/0005` decision 3); the same
  restriction applies here — another table wanting `SystemTenantScope` needs an ADR, not a
  precedent.

⚠ **`security_events` does not use this class.** Its `company_id` is nullable too, and it
still carries **no scope at all** (§3): a security event may be written before there is an
authenticated account, so there is no account whose `system_access` the scope could read.
Its access control stays a read-time permission check (BR-AT9), and its model keeps the
declared opt-out. **Two nullable `company_id` columns in one module, two different
answers** — and neither of them is `SharedTenantScope`, which is exactly why the choice is
made per table, on the meaning of the value, and declared on the model.

### Closed — `security_events` records both `ip_address` and `user_agent`

**Decided 2026-08-12.** Both columns exist from the creating migration, both nullable.

**The reason is BR-AT11, which is already decided and depends on these columns.** Attempts
against an account that exists are kept **forever**, and the justification given for keeping
them is that **an attack pattern is evidence**. A pattern with no origin is barely a pattern:
*"forty-one failed attempts against this account over three nights"* answers almost nothing
on its own — one person mistyping a new password looks the same as a distributed attempt —
while the same rows with an origin attached separate those two cases immediately.

Deciding to keep the rows forever and then storing nothing worth keeping would have been the
worse of both outcomes: the retention cost paid, the forensic value not received. And these
columns **cannot be retrofitted onto events that have already passed** — an `ALTER` adds the
column, never the data, so every attempt recorded before it lands is permanently
originless.

**`user_agent` is included because it is cheap and it separates a person from a script**, in
the ordinary case with no analysis required: a browser string against a `curl` or an empty
one. That is usually the first question asked of a run of failures.

> ⚠ **`user_agent` is an attacker-controlled string. It is a hint, never evidence.**
>
> Anyone can send any value, so a *legitimate-looking* agent proves nothing whatever. Only
> the awkward direction carries information — a client that declares itself a script
> probably is one. This is recorded here because the column reads like a fact and is not
> one, and the mistake is made when someone reaches for it months from now to support a
> conclusion.
>
> Two rules follow, and they are structural rather than advisory: **`user_agent` is never an
> input to an authorization, throttling, or lockout decision**, and it is never rendered as
> confirmation of who someone was. `ip_address` is weak in the same way — trivially changed,
> shared behind NAT, and `auth-rbac.spec.md` BR-A3 deliberately throttles on the **account**
> and not the IP for exactly that reason. Neither column changes any rule in this spec;
> both are recorded so a question asked later has something to look at.

Both are **nullable**, because a console or queue context has neither and a placeholder
would be a fabricated fact — the same reasoning that keeps `email` nullable rather than
seeded with a dummy address (`auth-rbac.spec.md` §3).

They fall under BR-AT11 unchanged: on a row with a null `user_id` they expire with the row
at 90 days. That is the correct interaction and not an accident of it — the rows holding an
origin for **an account that does not exist** are exactly the ones with no subject to keep
it for.

### Still open

**Nothing.** Both questions this section opened are answered above. The `audit_logs`
migration is unblocked.
