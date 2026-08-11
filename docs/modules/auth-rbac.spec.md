# Module Spec — Auth & RBAC

- **Phase:** 0 — Core Engine
- **Status:** Draft — awaiting approval. **No Auth code until this is approved**
  (`CLAUDE.md` Principle #1). This includes `MasterAdminSeeder`.
- **Branch:** `feat/auth-rbac`
- **Depends on:** `companies`, `employees`, `employee_roles`, `users`,
  `policy_configurations`, `audit_logs`; `adr/0001` (provisioning, Director off-system),
  `adr/0002` (shared org structure — the `branches` / `departments` scope carve-out §5.3
  must honour, and same-company approval), `adr/0003` (roles are a pivot), `adr/0004`
  (account access, authentication, permission matrix — this spec implements it)
- **Blocks:** every module. Nothing that reads employee data can be built until the scope
  and policy layer below exists.
- **Date:** 2026-08-11

---

## 1. Purpose

Two jobs, deliberately separated throughout this document:

**Authentication** — proving who is making a request. Login, session, password,
throttling, first-login activation.

**Authorization** — deciding what that person may do. Tenant scope, read scope, role
checks, policies.

Everything downstream depends on the second. Employee Master's §6 table, Leave's approval
routing, Payroll's salary gate — all of them are *statements* of permission that this
module provides the *machinery* to enforce. If the machinery is wrong, every module is
wrong at once, and silently: a scope that returns too few rows raises no error, and one
that returns too many raises no error either.

## 2. Scope

**In scope**

- Login, logout, session handling
- Password storage, minimum length, forced first-login change
- Failed-attempt throttling and account locking
- QR activation: token generation, single-use redemption, expiry, download tracking, HR
  notification
- `system_access` enforcement (`FULL` / `VIEW_ONLY` / `STANDARD`)
- **Tenant scope** — the global scope every business model carries
- **Read scope** — resolving which companies an account may read, from the employer's
  position in the company hierarchy
- Policy and gate structure: where authorization decisions live and how modules call them
- Account lifecycle enforcement: freeze on terminal status, session termination, expiry
  after the configured window, no reactivation
- Master Admin provisioning and the 3/1 limits
- `MasterAdminSeeder`

**Out of scope — explicitly**

- **Per-module permission tables.** Employee Master §6 owns its own table; Leave will own
  its own; Payroll will own its own. This module provides the mechanism they call, not the
  catalogue of what they allow. See BR-A8.
- **Employee self-service** — a separate module, not yet designed (`adr/0004` § Still open)
- Role assignment UI — Employee Master owns granting and revoking `employee_roles`
- Approval routing — the Approval Engine reads roles; it does not live here. This module
  only *notifies* it of a freeze (BR-A16)
- **Remember-me** — deliberately not built (BR-A4)
- Two-factor authentication, SSO, password expiry — not required, not built

## 3. Data Model

Tables are specified in `docs/schema.md` and are **not duplicated here**. This section
records only what a migration author needs beyond the column list.

**`users` — columns this module owns**

| Column | Note |
|---|---|
| `employee_id` | FK, **nullable**. Null for Master Admin and Director (`adr/0001` decision 4, `adr/0004` decision 4) |
| `system_access` | enum, **NOT NULL**, default `STANDARD` (`adr/0004` decision 2) |
| `is_master_admin` | boolean |
| `must_change_password` | boolean, **default true** |
| `password_changed_at` | timestamp, nullable |
| `activation_token` | string, unique, nullable |
| `activation_expires_at` | timestamp, nullable |
| `activation_downloaded_at` | timestamp, nullable — **null means HR has not fetched the image** |
| `activation_used_at` | timestamp, nullable — **null means not yet redeemed** |

**`employees.phone_no`** — string, **NOT NULL**, **unique**. This is the login username
(BR-A1). HR cannot register an employee without one, and there is no placeholder path — a
dummy number occupies the unique index and hands one employee's username to another.

**`sessions`** — the standard Laravel session table, with `user_id` indexed. Required by
the database session driver (BR-A5), which is what makes BR-A15 possible.

**Migration rules**

- `users` carries no `company_id` global scope. An account's scope is *derived* (§5.4), not
  stored, and Master Admin and Director accounts belong to no company at all.
- `must_change_password` defaults to **true**, so an account created by a code path that
  forgets to set it lands in the safe state.
- `system_access` defaults to `STANDARD`, the narrowest of the three, for the same reason.
  Neither `FULL` nor `VIEW_ONLY` may ever be reached by omission.
- `schema.md` updated in the **same commit** as each migration (`CLAUDE.md` Principle #5).
- Verify no migration timestamp collisions before committing (`conventions.md` §6).

## 4. Business Rules

All sourced from `adr/0004` unless noted. Every number here is a `policy_configurations`
lookup, never a literal (`conventions.md` §5).

### Authentication

**BR-A1 — Username is `employees.phone_no`.** Normalised before storing and before
comparing: strip spaces, dashes, and a leading `+60` or `60`. `012-345 6789`,
`0123456789`, and `+60123456789` are one number and must all authenticate. Validation:
**9–12 digits after normalisation**.

Normalisation lives in one place and is called by both the login attempt and the employee
form. Two implementations will diverge.

**BR-A2 — Password minimum 6 characters, no composition rules.** No forced uppercase,
digits, or symbols.

> The username is not secret — a phone number is known to colleagues. Password strength is
> therefore the only barrier, and six characters is weak on its own. **BR-A3 and BR-A4
> carry the weight this does not.** If the throttle tiers are relaxed, enforced
> client-side only, or a long-lived login cookie is reintroduced, brute force becomes
> practical against a system holding salary and identity documents.

**BR-A3 — Failed-login throttling, four tiers:**

| Cumulative failures | Result |
|---|---|
| 3 | Locked 5 minutes |
| 6 | Locked 10 minutes |
| 9 | Locked 15 minutes |
| 12 | **Locked permanently** — HR or Master Admin must unlock |

- The counter **resets on successful login**.
- Throttling is keyed on the **account**, not the IP. An attacker changing IP must not get
  a fresh allowance.
- Every failed attempt writes to `audit_logs`.
- The response must not reveal whether the username exists. "Invalid credentials" for both
  an unknown number and a wrong password.

**BR-A4 — No remember-me. The checkbox is removed from the login form and the driver is
disabled.**

A persistent login cookie would re-authenticate a user whose session has expired, which
makes BR-A6's two-hour window meaningless for anyone who ticks it. It matters more here
than in most systems because much of this workforce logs in from **shared terminals** at
the factory, studio, and galleria — a remembered login on a shared machine means the
account is never really logged out. It is also a second credential that must be
invalidated on password change and on freeze; not having it removes a thing that can be
forgotten.

**BR-A5 — Sessions are stored in the database**, not in files.

This is what makes BR-A15 possible: `DELETE FROM sessions WHERE user_id = ?` terminates
someone's access immediately. File sessions cannot be located by user without reading
every file, so "immediately" would in practice mean "on their next request." Redis was
rejected — the VPS is RAM-constrained, which is the same reason Coolify was ruled out
(`CLAUDE.md` §3 § Deployment constraints). That constraint is a **cost** one: if a resident
cache is ever genuinely needed, it is weighed against a larger VPS rather than refused.

Expired session rows are pruned on a schedule; without it the table grows without bound.

**BR-A6 — Session expires after 2 hours of inactivity** — inactivity, not time since
login. Someone working through the day is never interrupted; what expires is a session
left open on a shared terminal.

**BR-A7 — Password reset and unlock: `HR` and Master Admin only.** Not self-service by
email — most of this workforce has none. Not `ACCOUNT`, who reads everything but
administers nothing.

### Authorization

**BR-A8 — This module provides mechanism; modules provide policy.**

A module spec states *who may do what*. This module provides the functions that answer it
and guarantees they are called consistently. No permission catalogue lives here.

The contract every module relies on:

- Reads are scoped before the module sees them (§5.3, §5.4).
- Authorization decisions are made in a **Policy**, never inline in a controller.
- A permission function without a `company_id` argument is a bug.
- Every read of `employee_roles` filters `revoked_date IS NULL`.

**BR-A9 — Three scopes exist and must never be collapsed** (`conventions.md` §2):

| Scope | Answers | Comes from |
|---|---|---|
| **Structure** | Where does this person work | `branches` / `departments` |
| **Approval** | Whose requests may they act on | `employee_roles.role` + `employees.company_id` |
| **Read** | Whose employees may they see | The employer's position in `companies.parent_company_id` |

They disagree by design: a subsidiary-employed `HR` approves across the whole group while
reading one company only. An implementation in which any two of these always agree has
merged them, and the merge is a **silent widening of access**.

**BR-A10 — Read scope derives from the employer's hierarchy position** (`adr/0004`
decision 1). Employed by **AHS** → reads the whole group. Employed by a **subsidiary** →
reads that subsidiary only.

**There is no manual override, and none may be added.** Scope is derived, never stored per
account.

**Scope depends on the hierarchy being seeded correctly.** A mis-parented subsidiary grants
its staff group-wide reads. This is load-bearing and covered by §8 test 12.

**BR-A11 — `system_access` values** (`adr/0004` decision 2):

| Value | Employee record | Read scope | Writes |
|---|---|---|---|
| `FULL` | None | Whole group, tenant scope **bypassed** | Yes |
| `VIEW_ONLY` | None | Whole group | **None** |
| `STANDARD` | Yes | From BR-A10 | Per role |

`VIEW_ONLY` is **defined but currently unused** — the Director holds a Master Admin
account. Retained for an external auditor or a second write-less Director. Do not remove
it, and do not document it as the Director's value.

**BR-A12 — Salary is read by `ACCOUNT`, `FULL`, or `VIEW_ONLY`** (`adr/0004` decision 3).
A role-only check can never pass for Master Admin or Director, who hold no
`employee_roles` rows at all. **The `HR` role never grants salary access, at any scope.**

Employee Master holds no salary data, so this gate is implemented but not yet exercised.
It is specified here so Payroll does not invent its own answer.

**BR-A13 — Master Admin: maximum 3, minimum 1.** Both **enforced by the system**, not by
policy. Creating a fourth is rejected; deleting or disabling the last one is rejected.

**BR-A14 — Master Admin bypasses tenant scope; `VIEW_ONLY` does not bypass write
restrictions.** The bypass is explicit and audited, never ambient — a request runs in
Master Admin context because something said so, not because the check was skipped.

### Account lifecycle

**BR-A15 — Terminal status freezes the account immediately.** Setting `staff_status` to
`RESIGNED` or `TERMINATED` triggers, **in the same transaction**:

- The account may read **its own data only**. No writes, no approvals, no account creation,
  no role grants.
- All `employee_roles` rows are revoked (`revoked_date` set). Rows remain for history.
- **For `TERMINATED` only: all of that user's sessions are deleted immediately**, and the
  deletion is written to `audit_logs`.

The session-kill applies to termination and not resignation for the same reason the
countdown starts immediately for one and on the last working day for the other
(`adr/0004` decision 5): termination may follow serious misconduct, and waiting for the
person's next request — which may never come while a screen sits open — leaves access in
their hands. A resigning employee is typically still working, and cutting their session
mid-task achieves nothing, since they may log back in as a frozen account regardless.

**BR-A16 — A freeze notifies the Approval Engine.** Anything awaiting the frozen person's
endorsement or approval **escalates to `HR`**, marked as having skipped that stage because
the approver is frozen.

This is not a new mechanism — it is the existing `APPROVED_BY_HR` path (`adr/0003` §
Confirmed but not yet specced) with a different trigger: not "HR chose to step in" but
"there is no one else." Automatic reassignment to a substitute manager was rejected — a
three-person department may have no substitute, and a system that picks an approver
creates a question of responsibility nobody asked it to answer.

The routing itself belongs to the Approval Engine. What belongs **here** is the trigger:
freezing an account must emit the event.

**BR-A17 — Expiry 10 days after `effective_date`** — the last working day, not the date HR
typed the change. All data remains in the system.

**BR-A18 — No reactivation after a terminal status, by anyone, including Master Admin.**
A rejoining employee gets a new employee record, a new `employee_no`, and a new account.

**BR-A19 — The countdown is visible on five dashboards** — the employee's own, HR's,
Account's, Master Admin's, and the employee's manager or supervisor's. This is the
correction mechanism for a status set in error; there is no cancel path.

### Provisioning

**BR-A20 — The account is created in the same transaction as the employee record.** Not a
separate step. Every employee needs an account to verify their own attendance, and payroll
blocks on incomplete attendance (`payroll-notes.md` §3).

**BR-A21 — Activation is a single-use QR, not a temporary password** (`adr/0004`
decision 7):

| Property | Value |
|---|---|
| Single use | Dies the moment it is redeemed |
| Validity | **48 hours**, then HR regenerates |
| On redemption | Authenticated, then **forced** password creation |
| HR notification | Yes, on redemption |

The generated image carries a QR code, the employee's full name, and the validity period.
HR forwards it by WhatsApp or shows it in person.

> **The IC number was proposed as a first password and rejected.** It is not a secret, and
> unlike a password it can never be changed. It would open a window — lasting until first
> login — in which anyone knowing a phone number and an IC number could enter as that
> person, with the audit log showing the employee themselves.

**BR-A22 — The system records the download, not the send.** `activation_downloaded_at` is
set automatically when HR fetches the image. Delivery happens over WhatsApp, outside the
system, and the system does not pretend to observe it.

A "mark as sent" button was rejected: it records an *assertion*, not a fact, and a
timestamp reading "HR sent this at 2:15pm" looks authoritative while meaning only that
someone clicked. The download timestamp is observable and settles half the question with
certainty — **if it was never downloaded, it was certainly never sent.**

Three states are therefore visible on the HR dashboard:

| State | Meaning |
|---|---|
| Generated, not downloaded | HR has not acted |
| Downloaded, not redeemed | In flight — or the employee is ignoring it |
| Redeemed | Done |

**BR-A23 — `must_change_password` gates everything.** While true, every route except the
password-change screen and logout redirects there. This applies to Master Admin equally.

## 5. Behaviour

### 5.1 Login

`App\Http\Controllers\Auth\LoginController` — thin. Logic in
`App\Services\Auth\AuthenticationService`.

1. Normalise the submitted phone number (BR-A1).
2. Check the throttle state **before** verifying the password (BR-A3). A locked account
   fails without a password check.
3. Verify credentials. On failure: increment counter, write `audit_logs`, return a generic
   message.
4. On success: reset the counter, check account state (§5.2), regenerate the session ID.
5. If `must_change_password`, redirect to the change screen (BR-A23).

The login form has **no remember-me checkbox**, and the session guard is configured with
the driver disabled (BR-A4) — removing the checkbox alone is not enough, since the field
can be posted directly.

### 5.2 Account state gate

Runs on every authenticated request, as middleware, not per-controller:

```
App\Http\Middleware\EnsureAccountIsActive
```

- **Expired** (past the BR-A17 window) → session invalidated, logged out.
- **Frozen** (terminal status, within the window) → reads of own data permitted, all
  writes rejected.
- **Permanently locked** (BR-A3) → logged out.

Freeze is enforced **here**, not in each policy. A policy-by-policy freeze check is one
that gets forgotten in the twentieth policy.

Note this middleware still matters for `TERMINATED` even though sessions are killed
(BR-A15): the person may log in again during the 10-day window, and the gate is what makes
that session read-only.

### 5.3 Tenant scope

```
App\Scopes\TenantScope
```

Applied to every business model. Resolves against the **read scope** (§5.4), not against a
single `company_id`.

Three carve-outs already exist and must be honoured (`conventions.md` §2):

1. **`branches` / `departments`** — nullable `company_id`, where `NULL` means shared. The
   scope must resolve to `company_id IS NULL OR company_id IN (:scope)`. A plain equality
   check silently returns fewer rows.
2. **Event tables accessed through an employee relationship** — scope released, because
   permission was already decided at the employee level. Queried directly for reporting,
   scope applies in full.
3. **Master Admin context** — bypassed explicitly and audited (BR-A14).

### 5.4 Read scope resolution

```
App\Services\Auth\ReadScopeResolver
```

Returns the set of `company_id` values an account may read.

- `system_access = FULL` or `VIEW_ONLY` → all companies.
- `STANDARD`, employer is the parent (AHS) → all companies.
- `STANDARD`, employer is a subsidiary → that company only.

**Cached per request, never per session.** A hierarchy change or a transfer must take
effect on the next request, not on the next login.

### 5.5 Authorization

Laravel Policies, one per model, in `app/Policies/`. Modules call `authorize()`; they do
not read roles directly.

A support service backs them:

```
App\Services\Auth\RoleChecker
```

- `hasRole(User $user, string $role, int $companyId): bool`
- `hasAnyRole(User $user, array $roles, int $companyId): bool`
- `canReadSalary(User $user, int $companyId): bool`   ← BR-A12 lives here, in one place

Every method filters `revoked_date IS NULL` (BR-A8). No caller may query `employee_roles`
directly; a raw query is a review failure, because it is the one place the revocation
filter gets omitted.

### 5.6 Activation

```
App\Actions\Auth\GenerateActivationToken
App\Actions\Auth\RedeemActivationToken
```

- **Generate** — random token, `activation_expires_at` set 48 hours out,
  `activation_downloaded_at` and `activation_used_at` null. Called inside the
  employee-creation transaction (BR-A20). Regeneration invalidates the previous token and
  clears both timestamps.
- **Download** — serving the QR image sets `activation_downloaded_at` if not already set
  (BR-A22). No user action, no button.
- **Redeem** — rejects if used, expired, or unknown. On success: sets `activation_used_at`,
  authenticates, forces password creation, notifies HR.

Redemption is **atomic**. Two simultaneous scans of one token must not both succeed —
take the row `lockForUpdate()`.

### 5.7 Freeze

```
App\Actions\Auth\FreezeAccount
```

Called from the employee status change, **inside the same transaction** (BR-A15):

1. Revoke all `employee_roles` rows.
2. If `TERMINATED`: delete every session row for that user, write to `audit_logs`.
3. Emit the freeze event for the Approval Engine (BR-A16).

Not a queued job, not an observer side effect. If the status change rolls back, so does all
of this.

### 5.8 Master Admin provisioning

`MasterAdminSeeder` — runs once at install, credentials from `.env`, creates the first
account with `employee_id` null, `system_access = FULL`, `must_change_password = true`.

The seeder is **idempotent**: re-running it must not create a second Master Admin.

Subsequent Master Admins are created by an existing Master Admin, subject to BR-A13. Both
limits are enforced in `App\Actions\Auth\CreateMasterAdmin` and
`App\Actions\Auth\RemoveMasterAdmin`, not in a controller and not in the UI.

## 6. Permissions

This module's own surface only. Per-module tables live in their own specs (BR-A8).

| Action | Who |
|---|---|
| Log in | Any active account |
| Change own password | Any active account |
| Reset another's password | `HR`, Master Admin |
| Unlock a locked account | `HR`, Master Admin |
| Regenerate an activation QR | `HR`, Master Admin |
| Change an employee's `phone_no` | `HR`, Master Admin |
| Create / remove a Master Admin | Master Admin |
| Change `system_access` on an account | Master Admin |

`ACCOUNT` appears nowhere in this table. It reads the most data in the system and
administers none of it — that separation is deliberate.

## 7. UI

Blade + Livewire 3. Screens: login, forced password change, activation landing (reached by
QR), account management for HR (reset, unlock, regenerate QR), Master Admin management.

The login form carries **no remember-me checkbox** (BR-A4).

The activation QR image is generated and downloadable from the employee record; the
download sets `activation_downloaded_at` (BR-A22). HR's view shows the three activation
states.

**The lifecycle countdown (BR-A19) is a real requirement, not decoration.** It is the only
correction mechanism for a status set in error, and it must appear on all five dashboards.

## 8. Testing

Pest. **Mandatory** for this module — not because money or statutory entitlement is
involved, but because every other module's data protection is only as good as this one, and
its failures are silent.

**Authentication**

1. Phone normalisation — all three formats authenticate to the same account.
2. Rejects fewer than 9 or more than 12 digits.
3. Throttle tiers fire at 3, 6, 9, 12; the counter resets on success.
4. Throttle is keyed on the account — changing IP does not reset it.
5. Login failure reveals nothing about whether the username exists.
6. Session expires after inactivity, not after elapsed time since login.
7. `must_change_password` blocks every route except the change screen and logout —
   **including for Master Admin**.
8. **Posting `remember=1` directly does not create a persistent login** — removing the
   checkbox is not the same as disabling the feature.

**Read scope** — the highest-risk area

9. AHS-employed `HR` reads employees of every company.
10. Subsidiary-employed `HR` reads that subsidiary only — **and still approves across the
    group** (BR-A9). Both halves asserted; testing only the first turns the rule inside out.
11. Shared `branches` / `departments` (`company_id IS NULL`) are visible to every company.
    The inverse of the usual tenant test, and the bug most likely to ship: a plain equality
    check returns fewer rows rather than erroring.
12. A mis-parented subsidiary grants its staff group-wide reads — asserting the dependency
    in BR-A10 is real and observed.
13. `company_id` cannot be overridden via request input (mass-assignment probe).

**Authorization**

14. Revoked roles grant nothing — a role with `revoked_date` set fails every check.
15. `canReadSalary` passes for `ACCOUNT`, `FULL`, and `VIEW_ONLY`; **fails for `HR` at
    every scope**.
16. `VIEW_ONLY` reads everything and writes nothing — a write attempt is rejected on every
    route.
17. Master Admin bypass is explicit — an ordinary request from a Master Admin account
    without the bypass context is still scoped.

**Lifecycle**

18. `RESIGNED` freezes the account in the same transaction — no window in which writes
    still succeed.
19. A frozen account reads its own data and nothing else.
20. All `employee_roles` rows are revoked on freeze.
21. **`TERMINATED` deletes that user's session rows; `RESIGNED` does not.** Both asserted —
    this is the one place the two statuses diverge in this module.
22. A terminated user whose session was killed may still log back in during the window, and
    that session is read-only.
23. Freezing emits the Approval Engine event (BR-A16).
24. The account is inaccessible after the window, counted from `effective_date` — **not**
    from the date the status was set.
25. No reactivation path exists, including for Master Admin.

**Provisioning**

26. The account is created in the same transaction as the employee — a failure leaves
    neither.
27. An activation token redeems once; a second attempt fails.
28. Two simultaneous redemptions — exactly one succeeds.
29. Expired tokens are rejected; regeneration invalidates the previous token and clears
    both timestamps.
30. Downloading the QR sets `activation_downloaded_at`; downloading again does not move it.
31. `MasterAdminSeeder` is idempotent.
32. Creating a fourth Master Admin is rejected; removing the last one is rejected.
33. An employee cannot be created without a `phone_no`, and two employees cannot share one.

## 9. Definition of Done

The full `conventions.md` §10 checklist — `optimize:clear`, syntax check, `route:list`,
`php artisan test`, `npm run build`, migration test against an **empty** database, and the
sensitive-file check.

Plus, specific to this module:

- `schema.md` updated in the same commit as each migration
- No migration timestamp collisions
- **No raw `employee_roles` query outside `RoleChecker`** — grep and verify
- **No permission logic in a controller** — every decision in a Policy
- Every configurable number resolved from `policy_configurations`, none hardcoded
- Session driver set to `database`, remember-me disabled in config, session pruning
  scheduled

## 10. Resolved Decisions

The six questions that blocked this spec are closed. Recorded with their answers so the
reasoning survives.

**1. Remember-me — REMOVED.** A persistent cookie would re-authenticate a user past the
two-hour window, and much of this workforce logs in from shared terminals. It is also a
second credential to invalidate on password change and on freeze; not having it removes a
thing that can be forgotten. See BR-A4.

**2. Session driver — DATABASE.** Chosen so sessions can be terminated by user id, which
is what BR-A15 requires. File sessions cannot be located by user; Redis was rejected on
the VPS's RAM constraints, the same reason Coolify was ruled out. See BR-A5.

**3. Does freeze kill an open session — YES, for `TERMINATED` only.** Resignation is a
planned transition and the person is usually still working; termination may follow
misconduct, and waiting for their next request leaves access in their hands while a screen
sits open. This mirrors the countdown rule in `adr/0004` decision 5. See BR-A15.

**4. The Director is invisible outside this module — CONFIRMED.** Holding a Master Admin
account with no employee record means the Director appears in no employee list, org chart,
headcount, staff directory, leave calendar, or payroll run. This is understood and
accepted: those are display concerns, and solving them with a half-empty employee row
would spread exclusion checks into every module. If an org chart later needs a top, that is
an Org Structure decision — likely a line of text, not a row in `employees`.

**5. Activation tracking — DOWNLOAD, not send.** The system records what it can observe.
A "mark as sent" button records an assertion and reads as a fact. If the image was never
downloaded, it was certainly never sent — which settles half the question with certainty
and does not fabricate the other half. See BR-A22.

**6. Approvals pending with a frozen approver — ESCALATE TO HR.** Not automatic
reassignment to a substitute: a small department may have none, and a system that picks an
approver creates a responsibility question nobody asked it to answer. Not a flag left in
place either: an item that still reads "awaiting Rahman" gets ignored once everyone knows
Rahman has left. Escalation reuses the existing `APPROVED_BY_HR` path with a new trigger.
The routing is the Approval Engine's; the **trigger** is this module's. See BR-A16.
