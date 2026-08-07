# ADR 0001 — Core Role vs Level Taxonomy

- **Status:** Accepted
- **Date:** 2026-08-07
- **Supersedes:** the overlapping `core_role` / `level` design inherited from the legacy
  AHS system
- **Affects:** `employees`, `users`, `approval_requests`, RBAC spec, Employee Master spec

---

## Context

The legacy AHS system carried two overlapping classification systems on the employee
record, with no documented boundary between them:

- `core_role`: `ASSISTANT_DIRECTOR`, `HR`, `MANAGER`, `SUPERVISOR`, `STAFF`,
  `MASTER_ADMIN` — used for approval routing.
- `level`: `STAFF`, `SUPERVISOR`, `MANAGER`, `HOD`, `ADMIN` — used for org display.

The two lists shared three values (`STAFF`, `SUPERVISOR`, `MANAGER`), each list had
values the other lacked (`HOD` only in `level`; `HR`, `ASSISTANT_DIRECTOR` only in
`core_role`), and `ADMIN` / `MASTER_ADMIN` appeared in both lists in different forms
meaning different things. Nothing recorded which field was authoritative for which
decision, so approval routing and org display could disagree about the same person.

`HOD` existing in the display taxonomy but not the authority taxonomy was the sharpest
symptom: the legacy system could *show* someone as a Head of Department but had no way
to *route* an approval to them.

This ADR resolves both lists into two fields with a single, non-overlapping
responsibility each.

---

## Decision

### 1. `level` — org display and seniority tier

Four values only:

```
STAFF, SUPERVISOR, MANAGER, HOD
```

`level` answers "where does this person sit in the org chart, and how senior are they."
It drives org-structure rendering, directory grouping, and seniority display. **It never
drives an authorization or routing decision.**

`ADMIN` was considered as a fifth value and **rejected**. It conflated a *system
permission* with an *org-seniority tier* — "admin" describes what an account may do in
the software, not where a human sits in the company hierarchy. Including it would have
put a permission concept inside a display field and reintroduced exactly the overlap
this ADR exists to remove. System-administration access is modeled separately; see
decision 4 below.

### 2. `core_role` — approval and RBAC authority

Seven values:

```
STAFF, SUPERVISOR, MANAGER, HOD, HR, ASSISTANT_DIRECTOR, MASTER_ADMIN
```

`core_role` answers "what may this account approve, and where do its own requests go."
It is the **only** field consulted by the approval engine and by RBAC checks.

`HOD` is newly added here — it was absent from the legacy `AGENTS.md` authority list
despite existing in the display list. Its absence is why the legacy system could not
route to a Head of Department.

### 3. HOD is optional per department

An HOD is **not** guaranteed to exist. Assignment is per department:

- Some departments have an assigned HOD; others do not.
- Whether a department has one may vary **between departments inside the same company** —
  it is not a per-company setting.
- Therefore the approval chain is **not statically knowable** from an employee's
  `core_role` alone. Routing must resolve the HOD chain **dynamically per department**
  at request time, by checking whether that department currently has an HOD assigned
  before deciding the stage order.

**Consequences for routing:**

- **HOD as approver:** when a department has an assigned HOD, that HOD may approve
  directly, **skipping the Manager/Supervisor stage** for requests originating in that
  department.
- **HOD as requester:** an HOD's own requests route **directly to HR**, skipping
  Manager and Supervisor stages, since an HOD outranks both.
- When a department has **no** assigned HOD, routing falls back to the standard
  Manager/Supervisor chain unchanged.

### 4. Master Admin is a structurally separate account

Master Admin is **not a flag on an employee's normal login**. It is a dedicated user
account with **no linked Employee record** (`employee_id` is null and stays null).

- It **submits nothing** — no leave, OT, hours, or claims. Having no Employee profile,
  it has no entitlements to draw on and no requests to file.
- It **approves nothing in the normal chain** — it is not a routing stage.
- It exists **solely for oversight and data-repair access**.

**Why structural rather than a permission flag.** The rule "no user may approve their own
request" is a critical correctness rule. Enforcing it with an application-logic check
(`if ($request->requested_by === $approver->id) reject`) means the rule holds only as
long as every future code path remembers to call that check — one missed path is a
silent breach. Giving the Master Admin account no Employee record removes the
possibility at the data layer: **the account has nothing of its own to approve**, so
there is no self-approval path to forget to guard. This follows the project's general
preference for structural enforcement over logic-only enforcement of rules that matter.

**Practical consequence — two accounts for one person.** A real person who needs both
normal employee HR access *and* master admin access (for example a company director who
is also an employee) will hold **two separate user accounts with two separate logins**:
one normal employee account with an Employee record, and one Master Admin account
without. This is **intentional and by design, not a bug**. The alternative — one account
holding both capabilities — is precisely the conflation this decision rejects.

### 5. Account provisioning

**No account in this system is created by self-signup.** Every account is provisioned
either by a seeder (exactly once, for the first account) or by an already-authenticated
account with authority to do so. There is no public registration route.

**Provisioning chain — who creates whom:**

| Account type | Created by | How |
|---|---|---|
| Master Admin (first account) | `MasterAdminSeeder` | Seeder, run once at install |
| Director (`system_access = VIEW_ONLY`) | Master Admin (`FULL` access) | Authenticated action in-app |
| Regular staff | HR (`core_role = HR`) | Authenticated action, temporary password |

**The Master Admin account is the first account in the system.** It is created by a
dedicated `MasterAdminSeeder`, which reads its credentials from environment variables —
`MASTER_ADMIN_EMAIL` and `MASTER_ADMIN_PASSWORD` — and **never** from literals in the
seeder file. The reason is concrete: `.env` is gitignored, seeder files are not. A
password written into the seeder would be committed to the repository and would remain
in git history permanently even after being edited out. The seeder must fail loudly if
either variable is missing, rather than falling back to a default credential.

Both variables must be added to `.env.example` with empty values when the Auth module is
built, so the requirement is discoverable without leaking the real values. (Not yet done
— `.env.example` is untouched, since no Auth code exists yet.)

**Director accounts** are created by a Master Admin through an authenticated in-app
action — not self-signup, and not a seeder. Directors are provisioned with
`system_access = VIEW_ONLY` against the Master Admin's own `FULL` access.

**Regular staff accounts** are created by whoever holds `core_role = HR`, with a
temporary password issued at creation.

**First-login password change is mandatory and universal.** Every account provisioned by
someone other than its own user — Master Admin creating a Director, HR creating staff,
**and the self-seeded Master Admin account itself** — is flagged
`must_change_password = true`. On the first successful login, the user is forced through
a password-change screen **before any other access is granted**: no dashboard, no data
read, no API call other than the password change itself. The flag clears and
`password_changed_at` is stamped only on successful change.

Including the seeded Master Admin account in this rule is deliberate. It is the account
with the most authority in the system and the one whose initial credential has been
handled outside the application (typed into a `.env`, possibly shared, possibly copied
from a deployment note). It is the account that most needs the initial credential
retired, not the one that should be exempt.

**Enforcement note.** The forced password change must be enforced by global middleware,
not by a check in individual controllers — the same structural-over-logic reasoning as
decision 4. A per-controller check protects only the routes someone remembered to
annotate.

> ⚠ **`system_access` is not yet a defined field.** It does not exist in `schema.md`,
> and its value set (beyond `FULL` and `VIEW_ONLY`), its relationship to `core_role`, and
> whether `DIRECTOR` is a `core_role` value at all are undecided — note that `DIRECTOR`
> is **not** among the seven `core_role` values in decision 2, though
> `ASSISTANT_DIRECTOR` is, and `business-rules.md` refers to a "Director" role
> repeatedly (Haji/Umrah approval, disciplinary appeal, bonus discretion). Defining
> `system_access` and reconciling Director against `core_role` belongs to the Auth &
> RBAC spec below, and must happen before any provisioning code is written.

---

## Follow-up — `docs/modules/auth-rbac.spec.md` has not been written

Phase 0's Auth + RBAC module **has no spec yet**. Under `CLAUDE.md` Principle #1 that
means **no Auth code may be written** — including `MasterAdminSeeder`, the provisioning
actions, and the forced-password-change middleware described in decision 5 above. This
ADR records the provisioning *decisions*; it is not a substitute for the spec.

The Auth & RBAC spec must cover, at minimum:

1. The provisioning flow in decision 5, end to end.
2. `system_access` — full value set, semantics, and how it interacts with `core_role`
   (see the warning above).
3. Whether `DIRECTOR` is a `core_role` value, and how it relates to
   `ASSISTANT_DIRECTOR`.
4. Login, logout, session handling, session lifetime, and failed-attempt throttling.
5. The forced first-login password-change gate and its middleware.
6. Password policy — minimum strength, expiry (if any), reuse rules.
7. The full RBAC permission matrix across all seven `core_role` values, including how
   the optional per-department HOD (decision 3) resolves in permission checks.
8. How the dual-account arrangement from decision 4 behaves at login — two logins, two
   sessions, no switching between them within one session.

**This is the next spec needed**, after Employee Master's open questions (§10 of
`docs/modules/employee-master.spec.md`) are resolved.

---

## Consequences

**Positive**

- Each field has exactly one job. Display changes cannot alter authorization, and
  authorization changes cannot distort the org chart.
- `HOD` becomes routable, closing a real gap in the legacy design.
- "No self-approval" for Master Admin is enforced by data structure, not by a check that
  can be bypassed or forgotten.
- The `employees` migration can now be written — this taxonomy was the blocker.

**Costs and constraints accepted**

- The approval engine can no longer precompute a fixed stage list from `core_role`. It
  must query department HOD assignment per request. This is a deliberate trade:
  correctness over a simpler static routing table.
- Dual accounts for dual-capacity people means two logins to manage, and audit trails for
  such a person are split across two user IDs. Accepted — the audit split is arguably
  *desirable*, since it records which capacity an action was taken in.
- `employees.core_role` carries a `MASTER_ADMIN` value that, by decision 4, can never
  legitimately appear on any employee row — Master Admin accounts have no employee
  record. The value is retained so that one authority taxonomy is expressed in one
  enum; the invariant is documented in `schema.md` and must be asserted in tests.

**Open, not resolved by this ADR**

- The legacy rule "HR / Assistant Director requests → require Master Admin approval"
  cannot stand alongside decision 4, which removes Master Admin from the normal chain.
  Who approves HR and Assistant Director requests is now an open decision — logged in
  `CLAUDE.md` §10. It blocks the Approval Workflow Engine spec, **not** Employee Master.

---

## References

- `CLAUDE.md` §10 — Open Decisions Pending
- `docs/schema.md` — `employees`, `users`, `approval_requests`
- `docs/business-rules.md` § Approval Hierarchy
