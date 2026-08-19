# ADR 0017 — An Audit Row Without an Actor Is Refused

- **Status:** Accepted — 2026-08-19
- **Amends:** `audit_logs.user_id` — nullable becomes NOT NULL, by forward migration;
  `AuditLogger::record()` gains an actor precondition;
  `audit-trail.spec.md` §3, §5.1 and BR-AT14; `AuditLogger`'s *"never from arguments"* comment
- **Extends:** `adr/0009` decision 2 (no silent NULL) and decision 3 (`created_by` /
  `updated_by` made NOT NULL) — this applies the same rule to the table those decisions did
  not reach
- **Depends on:** `adr/0016` decision 1 — **accepted, not yet implemented.** No system user
  row, seeder or migration exists. Decision 5 below cannot ship before it does.
- **Related:** `adr/0005` decisions 5–6, `audit-trail.spec.md` BR-AT3, BR-AT6, BR-AT7,
  BR-AT12, `employee-master.spec.md` §5.3.7 assumption 16, `conventions.md` §3 §9 §11
- **Raised by:** `ApplyPendingStatusChange` (`employee-master.spec.md` §5.3.1) would be the
  first scheduled writer of an audit row — and the survey that question forced found six
  existing Actions with the same defect

---

## Context

**Two services answer the same question and disagree about the answer.**

| Service | Actor absent | Result |
|---|---|---|
| `AuthorshipObserver` | context empty **and** no session | throws — **fails closed** |
| `AuditLogger` | no session | writes `NULL`, no error — **fails open** |

`adr/0009` decision 2 refuses a silent `NULL` actor. Decision 3 made `created_by` and
`updated_by` NOT NULL and **dropped the development database** to do it. That enforcement
covers every model `AuthorshipObserver` watches. It does not reach `audit_logs`, which carries
no `created_by` at all — `user_id` **is** the actor (`conventions.md` §3's recorded exception),
and it is nullable.

### Six Actions can already write an actorless audit row

This was found while checking whether a scheduled task would be the first. It would not.

**The dividing line is `AuthorshipObserver::MODELS`, and it falls exactly on `users` versus
everything else.** `users` carries no `created_by` / `updated_by` — it is absent from the
authorship migration's list of eight tables — so no observer touches a write to it.

| | Actions | Behaviour without a session |
|---|---|---|
| Write an observed model | `CreateEmployee`, `ChangeEmployeeStatus`, `ChangeEmployeeAssignment`, `TransferCompany`, `GrantRole`, `RevokeRole`, `AssignJobFunction` | **throw** — the observer refuses |
| Write only `users` / `sessions` / `audit_logs` / `security_events` | the eight in `app/Actions/Auth/` | **run to completion** |

Six of those eight write an audit row: `CreateMasterAdmin`, `RemoveMasterAdmin`,
`ResetAccountPassword`, `UnlockAccount`, `ChangeLoginUsername`, `RegenerateActivationToken`.
Each lands `user_id = NULL` today if called without a session.

⚠ **Note which mechanism holds the first group.** Their `auth()->id()` calls write to
**nullable** columns — `employee_status_history.changed_by` and `revoked_by` both are. What
refuses is the model write through the observer, not the column. A rule in force, carried by
something other than what a reader would think (`conventions.md` §9).

**Nothing in production reaches those six today.** All are called from Livewire components
under the `auth` middleware group, several re-checking `Gate::authorize` per action. **That is
caller layout, not enforcement** — and `RedeemActivationToken` proves the layout is not a law:
its route sits outside the `auth` group by design, because activation happens before the
account has a session.

### `RemoveMasterAdmin` holds a named actor the audit row cannot see

Its signature requires `User $actor`, no default. The caller has already established who is
acting. The audit row ignores it entirely and resolves `auth()->id()`.

⚠ **Under decision 3 that Action throws even while holding a valid named actor, and that is
correct rather than a defect.** `$actor` there answers *"who requested this removal"* — a
business parameter, used for one thing: refusing self-removal. It does not answer *"who is
this process acting as."* Accepting it into `record()` would create the channel the *NEVER
from arguments* prohibition exists to close. The remedy is decision 4, not a parameter.

### The column's own justification names a writer that did not exist

The creating migration gives `company_id` twenty-five lines: what `NULL` means there, why NOT
NULL could not hold it, which scope class follows. `user_id` gets one clause:

> Nullable for console and system-initiated writes.

No ADR cited, no meaning assigned to `NULL`, no console writer named — **because none
existed.** `AuditLogger` had not been written; `AuditLog`'s docblock still says so. The clause
reserved room for a future caller and left the room unguarded. **Two nullable columns on one
table: one decided, one assumed.**

### `NULL` already means something else here

`currentCompanyId()` resolves `auth()->user()?->employee?->company_id`. That chain returns
`NULL` for two unrelated reasons — a Master Admin, who belongs to no company, and no
authenticated user at all. `user_id` collapses the same way.

**Nothing distinguishes *"a system-level event"* from *"we could not resolve it."*** Both write
`NULL`; under `SystemTenantScope` both then appear to Master Admin alone. `adr/0005` decision 6
refuses exactly this at model level — *"deliberately unscoped"* and *"someone forgot"* must stay
distinguishable. The principle was never applied to this column's **values**.

### No existing test would notice

Five tests assert a non-null `user_id`; all five obtain it through `actingAs()`. The nearest —
*"writes a system-level row with a null company when the actor has no employee"* — acts as a
Master Admin: what is null there is the **employee relation**, not the user.

⚠ **And one of the five is vacuous.** `ChangeEmployeeAssignmentTest:103` asserts
`->and($audit->user_id)->toBe(auth()->id())` — the column compared against its own source.
Remove the `actingAs()` and it becomes `null === null`: green, while `AuditLogger` writes a row
with no actor. The empty-guard shape `conventions.md` §9 records three times.

---

## Decision

### 1. `user_id` becomes NOT NULL

**There is no audited action without an actor.** Every row records something somebody or
something did; `NULL` there is always a failure, never a fact.

**This is not symmetric with `company_id`, and the asymmetry is the point.** A `NULL` company
is a real state the migration defends correctly: a Master Admin changing another Master
Admin's `system_access` has no company to name, and `SystemTenantScope` exists to show those
rows to Master Admin alone. **`company_id` is not touched by this ADR** — its `NULL` keeps its
meaning, its scope class, and its justification intact.

Whether `company_id` should instead name the **subject's** employer — the company that loses an
employee, rather than the company of whoever typed it — is a real question and **not this one**.
It reaches all fourteen call sites, and `TransferCompany:171` shows how far the current meaning
goes: the row recording a company transfer does not itself name a company. No production reader
filters, groups or reports on the column today, so nothing is served by deciding it before
there is a reader that cares.

### 2. A forward migration, not an in-place edit

`conventions.md` §11's window is open — no deployment of this repo, no real data, one
developer — so editing the creating migration **is permitted**. It is not taken.

**§11 governs whether an edit is allowed; it does not govern whether an edit is right**, and
that second test lives only in `adr/0010`'s migration docblock: *"genuinely changes the column
rather than correcting it in place."*

`adr/0006` edited in place because `phone_no` on the wrong table was a mistake that had **never
held a row**, and a backfill would have asserted a history that never happened. This is the
opposite case. The nullable column was a **dated decision with written reasoning**, rows exist
under it, and closing it is a **new** decision with its own date — the same shape as
`adr/0010`'s fifth enum value. Editing the creating migration would erase the evidence that the
gap existed, in the one file set whose entire job is the true history of the schema.

**The migration must fail loudly if any `NULL` row exists.** None should: no factory, no
seeder, and no production path writes one. If it cannot alter the column, that is a row nobody
accounted for, and it must stop the migration rather than be quietly backfilled.

### 3. `AuditLogger` gains an actor precondition, above the no-op exit

`record()` resolves the actor in three tiers, **identical to `AuthorshipObserver::actorId()`**:

1. `AuthorshipContext` if active
2. `auth()->id()`
3. throw `MissingAuthorshipActorException`

**Context first**, for the reason the observer already states: a seeder running while somebody
happens to be authenticated must attribute to the actor it named, not to whoever holds the
session.

**The check sits above the `$oldValue === $newValue` early return**, and this placement is a
decision rather than a style choice. Below it, whether a caller dies depends on **whether the
data happened to change** — a task making only no-op calls passes in testing and fails in
production the first time somebody actually leaves. Above it, the failure is deterministic.

It joins the transaction precondition already at the top of the method (BR-AT12): same shape,
same reason, checked before anything is written.

**`recordChanges()` needs nothing** — it loops into `record()` and never touches the actor.

**One extraction:** the actor becomes a private method beside `currentCompanyId()`. Today it is
inline in the `create()` array, the only resolution in the class with nowhere for fallback
logic to live.

**`record()` gains no actor parameter, and there is no channel through which one could be
passed.** The *"NEVER from arguments and never from request input"* prohibition stands; only
its wording changes, because as written it forbids decision 4.

> **⚠ Attribution added 2026-08-19.** Decision 3 quotes the prohibition as *"NEVER from
> arguments and never from request input"* and names no source. That wording is
> `AuditLogger`'s **comment**, verbatim. The **rule** lives in `audit-trail.spec.md` §5.1,
> which reads *"never from method arguments"* — same prohibition, different sentence,
> different document. An unattributed quotation cannot be checked against anything, which is
> the failure `conventions.md` §9 records against `adr/0011` — a blockquote assembled from
> memory that never existed at the source it named. Both the comment and §5.1 are amended by
> this ADR and are now named in **Amends** above.

### 4. A caller holding a named actor enters `AuthorshipContext` with it

This is the answer for `RemoveMasterAdmin` and for anything shaped like it.

**`AuthorshipContext` is process context, not an argument.** It is set at a boundary, names a
real `User`, and `run()` refuses to proceed without a stated reason. The distinction it draws
is between *"I am telling you whose name to write"* — which stays forbidden — and *"I am acting
as this account"*, which is what a boundary declares.

Six Actions can reach `AuditLogger` without a session today. Each is called from a Livewire
component under `auth`, so each has a session in practice. **None is changed by this ADR**:
under decision 3 they resolve `auth()->id()` exactly as now. What changes is that a future
caller without one fails immediately instead of writing an actorless row.

### 5. Scheduled tasks enter `AuthorshipContext` as the system user

⚠ **This decision cannot ship until `adr/0016` decision 1 is implemented.** That ADR is
accepted; the system user does not exist. `AuthorshipContext::run()` takes a `User`, not a
flag — there is nothing to name.

`AuthorshipContext`'s docblock already names this case: the shortcut is for *"no authenticated
session"*, and the examples it gives are a seeder, a console command, and the legacy importer.
**A scheduled task is a console command.** The class was designed for this before the case
arose.

**Nothing in `app/` enters it today** — three call sites: two seeders and one factory concern.
The scheduled task will be the first production entry.

**Authenticating the system user into the guard was rejected.** `adr/0016` decision 1 states
that account cannot sign in. ⚠ **That is a requirement of `adr/0016`, not the current state:**
`LoginRequest` validates `phone_no` as `['required','string','max:32']` and refuses nothing, and
`PhoneNumber::normalise('SYSTEM')` returning an empty string is a measured consequence rather
than a guard. Both must be built when `adr/0016` lands. The rejection stands regardless — one
account with a locked front door and a nightly back door is two rules disagreeing about one
thing.

### 6. Five test files gain a shared user

**Four helper files.** Thirty-two call sites insert a `NULL` `user_id` across
`AuditLogReaderTest`, `SystemTenantScopeTest`, `AppendOnlyTest` and `PruneSecurityEventsTest`.
They are **four edits, not thirty-two**: three are free functions at file scope, one is a
single inline `AuditLog::create()`.

**One user per file, created once and reused** — not one per call. The helpers are free
functions and cannot read `$this`, so a user created inside would mean twenty-eight extra
`users` rows across the suite, in tests that never look at the column.

⚠ **None of these four tests the actor.** They test `SystemTenantScope`, readability,
append-only behaviour, and the prune sweep; `user_id` is the column they ignored. **Each edit
carries a one-line comment pointing here**, or the setup reads as noise and the next person
removes it.

**And a fifth file, of a different shape.** `MasterAdminScreensTest` has no `actingAs()` in its
`beforeEach` — the first is at line 228. Seven tests before it run `CreateMasterAdmin` or
`RemoveMasterAdmin` to completion, writing nine actorless audit rows. All seven turn red under
decision 1 or decision 3.

**One line fixes all seven:** `$this->actingAs($this->admin)` in `beforeEach`. The Master Admin
is already created there and already passed as `$actor` to `RemoveMasterAdmin` in several
tests — named throughout, just never in the session guard.

⚠ **This does not weaken what that file protects.** Its comment reads *"Through the Action
directly — the cap must hold with no UI in the picture"* — **no UI**, not no user.
Authenticating while calling the Action directly still bypasses Blade, Livewire,
`Gate::authorize`, and the form. The 3/1 ceiling is still tested without the screen.

`AppendOnlyTest` does not import `User` and gains the `use` line with it.

---

## Consequences

**Accepted**

- **A scheduled task that forgets `AuthorshipContext` dies on its first run.** Loud and
  immediate, and preferable to a row recording nobody — but still a production failure if
  untested. Every scheduled writer needs a test that runs it with no authenticated user.
- **`MissingAuthorshipActorException` now has two throwers.** Its message must read correctly
  from both.
- **Four unrelated test files carry setup they do not need**, mitigated by the pointer comments
  and by nothing else.
- **Anyone with a local database must run the new migration**, or `AuditLog::create()` keeps
  accepting `NULL` locally while the suite says otherwise.

**Not changed**

- **`company_id`.** Nullable, meaningful, `SystemTenantScope` untouched.
- **The six `Auth/` Actions.** They resolve `auth()->id()` exactly as now.
- **`AuditLogger` takes no actor argument.**
- **BR-AT6, BR-AT7, BR-AT12.** Append-only stands, the audit write stays inside the caller's
  transaction, and the service still opens none of its own.
- **`adr/0016` decision 1.** The system user still cannot sign in. This ADR gives it a second
  path — authorship *and* audit — without giving it a session.
- ⚠ **The installation path writes zero audit rows, and must keep writing zero.**
  `MasterAdminSeeder` builds a `User` by hand and never calls `CreateMasterAdmin`; no seeder in
  the system writes an audit row; `DatabaseSeeder` runs `MasterAdminSeeder` first, so by the
  time `JobFunctionSeeder` or `NationalitySeeder` needs an actor one exists — and `installer()`
  throws rather than inventing one if it does not. **Decision 1 is only implementable because of
  that ordering**, and nothing in the ordering itself says so.

  **The asymmetry this leaves is real: the first Master Admin's creation is not audited, the
  second and third are.** `audit_logs` cannot answer *"who created the first account"* — not
  because the column is null, but because there is no row. Whether that is a gap or the correct
  answer (nobody created it; the installation did) is a separate decision. ⚠ **Anyone
  "fixing" it by adding an audit write to `MasterAdminSeeder` must attribute the row to the
  account being created** — `$user->save()` precedes it, so the id exists and the foreign key is
  satisfied. Adding one with no actor would reintroduce exactly what this ADR closes, at the one
  moment no other actor can exist.

---

## Must be asserted

1. `AuditLogger::record()` throws when no session and no `AuthorshipContext` are present.
2. It throws **before** the no-op exit — a call where `$old === $new`, with no actor, still
   throws.
3. Inside `AuthorshipContext`, it writes the context's actor, **not** the authenticated user,
   when both are present.
4. Outside the context with a session, it writes `auth()->id()` — unchanged behaviour.
5. The migration refuses to run if a `NULL` `user_id` row exists.
6. `DatabaseSeeder` completes on an empty database. ⚠ This is the installation path, and
   decision 1 depends on its ordering.
7. ⚠ `ChangeEmployeeAssignmentTest:103` names a concrete id instead of `auth()->id()`. Verify
   the assertion fails without the `actingAs()` before trusting it (`conventions.md` §9).
8. Assertions 1, 2, 5 and 7 must be **seen to fail** before they are trusted, and the failing
   test named in the commit message.

---

## Follow-up

Not part of this ADR, recorded so they are not lost:

1. **`conventions.md` §11 does not state when an in-place edit is *right***, only when it is
   *allowed*. The distinction lives in `adr/0010`'s migration docblock.
2. **§11's usage log records neither precedent** — `adr/0006` predates it, `adr/0010` never
   invoked it. Read alone, the log implies no creating migration has been edited in this repo.
3. **`audit_logs.company_id` — actor's company or subject's?** Deferred until a production
   reader exists. See decision 1.
4. **`employee_status_history.changed_by` is nullable and filled from `auth()->id()` the same
   way.** This ADR closes one column in that family and leaves the other open. It is held
   closed today only by the observer refusing the model write — the mechanism noted in Context,
   not the column.
5. **`AuditLog`'s docblock still says `AuditLogger` "does not exist yet."**
6. **`employee-master.spec.md` §5.3.7 assumption 16 is unblocked by this ADR** and must be
   rewritten from *withheld* to asserted, in the branch that builds
   `ApplyPendingStatusChange`.
