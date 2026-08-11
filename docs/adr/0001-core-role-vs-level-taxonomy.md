# ADR 0001 — Core Role vs Level Taxonomy

- **Status:** Accepted — **decision 2 superseded 2026-08-10**, follow-up item 10
  withdrawn (see the bullet below)
- **Date:** 2026-08-07
- **Supersedes:** the overlapping `core_role` / `level` design inherited from the legacy
  AHS system
- **Superseded in part by:** `adr/0003`. **Decision 2 is dead** — `core_role` is no longer
  a column on `employees`, and the column was never created. Authority moved to the
  `employee_roles` pivot, because a person holds several roles and the roles differ per
  company, which a single enum column could not express in any form. `STAFF` ceased to be
  a value (an ordinary staff member is someone with **no** authority row) and `ACCOUNT`
  was added, so the six values are **not** the six named in decision 2.
  **Follow-up item 10 (`hr_scope`) is withdrawn**, not deferred — the Payroll HR /
  Operations HR distinction it modeled does not exist; salary visibility is the `ACCOUNT`
  role (`adr/0003` decision 5).
  **Decisions 1, 3, 4, 5, 6 and 7 stand**, re-expressed in pivot terms where they named
  `core_role` — including decision 4's "Master Admin has no employee record", which is now
  enforced by the absence of any `employee_roles` row rather than by the absence of an
  enum value, and is therefore *more* structural, not less
- **Extended by:** `adr/0002` — decision 3 (HOD per department) is extended there: a
  department may span companies, but an **HOD's approval authority is strictly
  same-company** and does not follow the shared department (`adr/0002` decision 4, as
  amended 2026-08-08). `HR` and `ASSISTANT_DIRECTOR` are the only roles that approve
  across companies, and that authority grants no data visibility (`adr/0002` decision 5)
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

Six values:

```
STAFF, SUPERVISOR, MANAGER, HOD, HR, ASSISTANT_DIRECTOR
```

`core_role` answers "what may this employee approve, and where do their own requests go."
It is the **only** field consulted by the approval engine and by RBAC checks.

`HOD` is newly added here — it was absent from the legacy `AGENTS.md` authority list
despite existing in the display list. Its absence is why the legacy system could not
route to a Head of Department.

**`MASTER_ADMIN` is deliberately not a value.** `core_role` is a column on `employees`,
and by decision 4 a Master Admin has **no employee record** — so a `MASTER_ADMIN`
`core_role` could never legitimately be set on any row. Including it would define a value
whose only possible use is a bug, and the "Master Admin has no Employee record" rule
would then depend on a test remembering to assert that the value never appears.

Excluding it makes the rule **structurally impossible to violate rather than
test-enforced**: there is no value to set, so no row can claim Master Admin authority.
This is the same reasoning as decision 4 itself — remove the possibility at the data
layer instead of guarding against it in code.

Master Admin is therefore identified **entirely at the `users` level**, by
`is_master_admin` with a null `employee_id` — never by an authority value on an employee
row. The two taxonomies do not need to merge: `core_role` describes an *employee's*
authority within the approval chain, and Master Admin is by design not in that chain
(decision 4).

> **⚠ `is_master_admin` superseded — 2026-08-11.** The identification rule above stands;
> **only the column it names is withdrawn.** Master Admin is identified by
> **`system_access = FULL` with a null `employee_id`** (`adr/0004` decision 2), still
> entirely at the `users` level and still never by an authority value on an employee row.
>
> `is_master_admin` was written here before `system_access` existed as a defined field, so
> at the time it was the only mechanism available. Once `adr/0004` defined `system_access`
> with `FULL` meaning Master Admin, the two said the same thing — and **two ways to express
> one fact eventually disagree**, the reasoning that already withdrew `secondary_company_id`
> (`adr/0003` decision 6), `is_enabled` and `primary_role` (`adr/0003` decision 1), and
> `hr_scope` (`adr/0003` decision 5). `adr/0004` should have withdrawn it and did not; this
> note is that correction. Do not reintroduce the column.

`DIRECTOR` is likewise absent, for a different reason — see decision 7.

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
  department — but **only for requesters who share the HOD's own `company_id`**
  (`adr/0002` decision 4). An HOD never approves for another company's employee, even one
  sitting in the same shared department.
- **HOD as requester:** an HOD's own requests route **directly to HR**, skipping
  Manager and Supervisor stages, since an HOD outranks both.
- When a department has **no** assigned HOD **for the requester's company** — whether
  because it has none at all, or because the one it has is employed by a different
  company — routing falls back to the standard Manager/Supervisor chain unchanged.
- Resolution is therefore per **(department, company)** pair, not per department alone. A
  shared department may hold more than one HOD, one per company represented in it.

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
> and its full value set (beyond `FULL` for Master Admin and `VIEW_ONLY` for Director) is
> undecided — in particular, the value regular staff accounts receive is unspecified.
> Defining it belongs to the Auth & RBAC spec below, and must happen before any
> provisioning code is written.
>
> Note that `system_access` is an **account access dimension, not an authority role** —
> it is orthogonal to `core_role`. Director is the clearest illustration: `DIRECTOR` is
> deliberately **not** a `core_role` value (decision 7 — Director authority is exercised
> off-system), yet a Director legitimately holds an account with
> `system_access = VIEW_ONLY`. Read access and approval authority are separate questions.

### 6. HR ↔ Assistant Director peer approval

Decision 4 removed Master Admin from the normal approval chain, which left the legacy
rule "HR / Assistant Director requests → require Master Admin approval" with no valid
approver. That gap is closed as follows:

**HR and Assistant Director approve each other, as peers.**

- An `HR` request is approved by an `ASSISTANT_DIRECTOR`.
- An `ASSISTANT_DIRECTOR` request is approved by `HR`.
- This is a **single stage**. Nothing sits above it.
- **This is the top of the chain.** No stage exists beyond it, and no request escalates
  past it.

**Why peers rather than escalation.** The alternatives were to route these requests to
Master Admin (rejected — it contradicts decision 4 and would reintroduce Master Admin as
a routine actor, defeating the structural separation), or to invent a Director approval
stage (rejected — see decision 7). Peer approval keeps the chain closed without adding
an actor, and both roles already hold the broadest approval authority in the system — the
only authority that reaches across companies (`adr/0002` decision 5) — so neither is
under-qualified to approve the other.

**No-self-approval still binds.** Where two people hold the same `core_role`, one may not
approve their own request; a *peer* holding the same role may. The check is per-user, not
per-role.

**These two roles are also the only cross-company approvers.** `HR` and
`ASSISTANT_DIRECTOR` are the only `core_role` values whose approval authority is not
confined to their own `employees.company_id` — every other role, `HOD` included, approves
strictly within its own company. That is what makes peer approval workable across a group
where one company may hold an HR but no Assistant Director. **It confers no data
visibility**: approving a cross-company request does not grant read access to that
employee's salary, documents, or other sensitive data, which stays behind a separate
permission check. See `adr/0002` decision 5.

> **⚠ `hr_scope` withdrawn — 2026-08-10.** This paragraph originally pointed at an
> unmodeled `hr_scope` (`PAYROLL | OPERATIONS`) distinction the visibility check was said
> to need. There is no Payroll HR / Operations HR split. **Salary visibility is the
> `ACCOUNT` role, and no `HR` holds it** — `adr/0003` decision 5. The remainder of the
> visibility check is still undefined and still belongs to the Auth & RBAC spec.

**Failure mode made explicit.** If no counterpart account exists, the request **cannot be
routed**. It must be held as **blocked, with a clear reason surfaced to the requester**.
It must **not** be silently auto-approved (a request nobody approved must never read as
approved), and must **not** fall through to Master Admin. This is a foreseeable
configuration state, not an exceptional error, and the engine must handle it deliberately.

**The counterpart search is group-wide, not company-wide.** Because these two roles are
precisely the ones that approve across companies (see below), a company with an HR but no
Assistant Director is **not** a blocked state — its HR's requests route to an Assistant
Director at any group company. The blocked state is the narrower one: **no counterpart
exists anywhere in the group**. Scoping this search to the requester's own company would
block requests that have a valid approver, which is the more likely bug of the two.

### 7. Director authority is off-system; the chain has no Director stage

The source handbook refers repeatedly to **Pengarah Syarikat** / Director authority:
Haji/Umrah leave beyond entitlement, the final decision on a disciplinary appeal, bonus
declaration and withholding, and policy exceptions generally.

**All of these are policy text describing real-world authority within the company. None
is a digital in-system approval step.** The Director holds no approval stage, appears in
no routing chain, and is never assigned a request for action in the system. This is also
why `DIRECTOR` is absent from the six `core_role` values in decision 2 — its absence is
correct, not an oversight, and the apparent contradiction with `business-rules.md` is
resolved by recognizing that those clauses describe company governance, not software
workflow.

**How Director decisions are honored instead.** When a Director exercises discretion —
rare, and off-system — **HR or a Master Admin account records the outcome as a manual
override**, reusing the audited-correction pattern already designed for attendance
corrections: `old_value`, `new_value`, `reason`, `corrected_by`, written to `audit_logs`.

**Why this rather than a Director approval stage:**

- A Director stage would sit **empty almost always**, since these clauses fire rarely.
  Modeling a rare real-world escalation as a permanent workflow stage adds a state every
  request must pass through, or be specially exempted from, for the sake of an exception.
- It would require Director user accounts to exist and be actively monitored for a
  queue that is nearly always empty — an approval bottleneck staffed by someone whose
  job is not processing HR queues.
- The override record is **more auditable, not less**: it captures the before value, the
  after value, the stated reason, and the identity of the person who entered it. An
  approval click captures far less.
- It keeps the software honest about what actually happened: the decision was made by a
  human outside the system, and the system records who entered it and why — rather than
  presenting a Director login as having taken an action they did not take in the software.

**A Director may still hold a system account.** Decision 5 provisions Director accounts
with `system_access = VIEW_ONLY`. That is now consistent and deliberate: a Director can
*see* the data for oversight, but takes no in-system approval action, because there is no
stage for them to act on.

**Trade-off accepted.** Enforcement of "the Director actually decided this" lives outside
the system — the override is trusted to HR or Master Admin, and the audit trail records
the entering user, not the Director. This is accepted because the alternative does not
actually verify it either; an approval stage would only prove that *someone with that
login* clicked approve. The override at least forces a written reason.

---

## Follow-up — `docs/modules/auth-rbac.spec.md` has not been written

Phase 0's Auth + RBAC module **has no spec yet**. Under `CLAUDE.md` Principle #1 that
means **no Auth code may be written** — including `MasterAdminSeeder`, the provisioning
actions, and the forced-password-change middleware described in decision 5 above. This
ADR records the provisioning *decisions*; it is not a substitute for the spec.

The Auth & RBAC spec must cover, at minimum:

1. The provisioning flow in decision 5, end to end.
2. `system_access` — full value set and semantics, including what regular staff accounts
   receive, kept orthogonal to `core_role` (see the note above).
3. How a Director's `VIEW_ONLY` account is scoped in practice — what it can read across
   companies, given the Director holds no `core_role` and no approval stage
   (decision 7).
4. Login, logout, session handling, session lifetime, and failed-attempt throttling.
5. The forced first-login password-change gate and its middleware.
6. Password policy — minimum strength, expiry (if any), reuse rules.
7. The full RBAC permission matrix across all six `core_role` values plus the Master
   Admin account type, including how the optional per-department HOD (decision 3)
   resolves in permission checks — resolved per **(department, company)** pair, since HOD
   authority is same-company only (`adr/0002` decision 4).
8. How the dual-account arrangement from decision 4 behaves at login — two logins, two
   sessions, no switching between them within one session.
9. **The data-visibility permission check, separate from approval authority.** `HR` and
   `ASSISTANT_DIRECTOR` approve across companies (decision 6, `adr/0002` decision 5); that
   must not imply read access to the approved employee's salary, personal documents,
   family records, disciplinary history, or full leave history. The spec must define this
   check explicitly and state that holding an approval stage is never an input to it.
10. **`hr_scope` — WITHDRAWN 2026-08-10, superseded by `adr/0003` decision 5.** This item
    required an `hr_scope` (`PAYROLL | OPERATIONS`) field distinguishing a **Payroll HR**
    (salary, documents, payslip configuration) from an **Operations HR** (leave,
    attendance, OT entry) for data visibility. **That distinction does not exist.** The
    client confirmed only the `ACCOUNT` role may see salary, and no `HR` may, however many
    HR staff there are. The field is **withdrawn, not deferred** — do not model it, and do
    not carry it into the permission matrix. Salary visibility is answered by `adr/0003`
    decision 5; item 9's general visibility check remains open and no longer covers salary.

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
- The authority taxonomy is split across two places rather than one: `employees.core_role`
  for the six in-chain roles, and `users.is_master_admin` for Master Admin. Accepted
  deliberately — a single enum containing an unusable value was the worse option, and the
  split mirrors a real distinction (Master Admin is not in the approval chain at all).
  Code must therefore not assume `core_role` alone answers "what is this account."
  **Both column names here are superseded** — authority moved to the `employee_roles` pivot
  (`adr/0003` decision 1) and Master Admin is now `users.system_access = FULL` (`adr/0004`
  decision 2, and the note in decision 2 above). **The split itself still holds**, and so
  does the warning: no single field answers "what is this account."

**Open, not resolved by this ADR**

- `system_access` is referenced by decision 5 (`FULL` for Master Admin, `VIEW_ONLY` for
  Director) but is not yet a defined field in `schema.md`, and the value regular staff
  accounts receive is unspecified. Belongs to the Auth & RBAC spec. The HR/Assistant
  Director approver gap (decision 6) and the Director-authority contradiction
  (decision 7) are both closed.
- **The data-visibility permission check** that must sit beside — and independent of —
  the cross-company approval authority in decision 6. Undefined; Auth & RBAC spec,
  follow-up item 9.
- **`hr_scope` (`PAYROLL | OPERATIONS`) — WITHDRAWN**, superseded by `adr/0003`
  decision 5. The Payroll HR / Operations HR distinction it existed to model does not
  exist; salary visibility is the `ACCOUNT` role and no `HR` holds it. Not to be modeled.
  See follow-up item 10.

The first two remain Auth & RBAC concerns and neither blocks Employee Master. The third is
closed.

---

## References

- `CLAUDE.md` §10 — Open Decisions Pending
- `docs/schema.md` — `employees`, `users`, `approval_requests`
- `docs/business-rules.md` § Approval Hierarchy
