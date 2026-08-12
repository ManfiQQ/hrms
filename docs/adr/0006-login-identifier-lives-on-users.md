# ADR 0006 — The Login Identifier Lives on `users`

- **Status:** **Accepted** — 2026-08-12. Not yet implemented; the migration and the code
  changes follow in their own branch.
- **Date:** 2026-08-12
- **Supersedes:** the placement half of `auth-rbac.spec.md` BR-A1 — the login identifier is
  still the phone number, and everything else about BR-A1 (normalisation, the 9–12 digit
  rule, one normaliser) stands unchanged. Only **which table holds it** changes.
- **Extends:** `adr/0001` decision 4 (Master Admin has no employee record), `adr/0004`
  decision 6 (`phone_no` is a credential, edited from the account screen)
- **Closes:** a live defect — the Master Admin account cannot log in at all. See § Context.
- **Does not decide:** whether an employee's contact number is also stored somewhere for HR
  to *reach* them. That is Employee Master's question and § Consequences records it as open.
- **Affects:** `users`, `employees`, `MasterAdminSeeder`, `AuthenticationService`,
  `PhoneNumber`, `LoginRequest`, `EmployeeFactory`, `UserFactory`,
  `auth-rbac.spec.md` BR-A1 / §3 / §6 / §7, `employee-master.spec.md` §6.4,
  `adr/0004` decision 6, `schema.md`, `tests/`

---

## Context

Two accepted decisions, each sound on its own, are jointly impossible.

**BR-A1 makes the phone number the login username.** It is the right choice for this
workforce: most field staff have no email address, which is why `users.email` is nullable and
authenticates nothing.

**`adr/0001` decision 4 gives Master Admin no employee record**, and that is equally right —
it is what keeps the administrative account out of headcount, org charts, payroll runs and
leave calendars, and what makes "Master Admin holds no `employee_roles` row" structurally
true rather than merely asserted (`adr/0003` decision 1).

**The number was stored on `employees`.** Put those three together and **the most powerful
account in the system has nowhere to keep its own username.**

### This is not a theoretical gap — it was reproduced

`MasterAdminSeeder` creates the first account with `employee_id = null`, so no `employees`
row exists for it and there is no `phone_no` anywhere. `AuthenticationService` resolves a
login by looking the number up on `employees` and following it to the account. With the
**correct password**, every identifier was refused:

| Identifier tried | Result |
|---|---|
| A phone number | refused |
| The account's email address | refused |
| The account's id | refused |
| Empty | refused |

**The account created by the installer is unreachable by any input.** No password reset
fixes it, because reset changes the password and the failure is not the password. Nothing
errors on the way: the login screen returns "Invalid credentials", which is exactly what it
is designed to say and exactly what it would say if the account did not exist.

⚠ **It is the first account and the only one, so this is not one broken login — it is a
system nobody can enter.** Until an employee exists, and an employee cannot be created until
someone logs in.

### Why it survived review this long

Every document is individually correct. BR-A1 says the username is `employees.phone_no`;
`adr/0001` decision 4 says Master Admin has no employee; `schema.md` lists `phone_no` on
`employees`, NOT NULL and unique. **No single sentence is wrong.** The contradiction only
appears when the three are read together against a Master Admin, which nothing in the
documents forces a reader to do — and the code that would have failed did not exist until
`AuthenticationService` was written.

That is the failure mode `CLAUDE.md` Principle #1 mostly prevents and did not here: the
spec was consistent, the *composition* was not.

---

## Decision

### 1. `phone_no` moves to `users`, and `employees` does not keep a copy

The login identifier is a column on `users`. It is **NOT NULL and unique** there, exactly as
it was on `employees` — it is the username, so an account without one cannot authenticate and
two accounts sharing one would hand a login to the wrong person.

**`employees` does not store it at all.** Not a copy, not a cache, not a
"contact number kept in sync". Every read of an employee's login identifier goes through the
account.

**Everything else about BR-A1 stands:** the number is normalised before storing and before
comparing, `+60`/`60`/spaces/dashes reduce to one form, 9–12 digits after normalisation, and
**one normaliser** serves both the login attempt and any form that accepts a number.
`App\Support\Auth\PhoneNumber` is unaffected; only its callers move.

### 2. Why `users` and not `employees` — the line was already drawn

`auth-rbac.spec.md` §6 and §7 already decided that **`phone_no` serves two masters and the
credential wins**:

> `phone_no` is profile data (how you reach a person) *and* an account credential (the login
> username). Leaving it on the employee form treats it as only the first. Removing it makes
> the boundary clean — **the employee form is for employee data, the account screen is for
> account credentials.**

That reasoning already removed the field from the employee *form* (`employee-master.spec.md`
§6.4: read-only for everyone, `HR` and Master Admin included) and moved every edit to the
account management screen. **Storing it on `employees` kept the credential in the profile
table while the interface said it was not a profile field** — the storage contradicted the
line the spec itself had drawn, and this ADR finishes the move that decision started.

It also makes the model honest in a second way: an account **is** the thing that logs in.
The username belonging to the account rather than to the person is what lets an account with
no person still have one.

### 3. Rejected — `users.phone_no` **alongside** `employees.phone_no`

The smallest-looking fix: leave the employee column where it is, add one to `users` for the
accounts that have no employee, and read whichever is present.

**Rejected, for the reason this project has rejected the same shape five times.** Two places
holding one fact eventually disagree, and the copy is the one that goes stale:

- `is_master_admin` beside `system_access` (`auth-rbac.spec.md` §3)
- `users.role` beside `employee_roles` (`schema.md` § `users`)
- `users.company_id` beside derived read scope (`adr/0004` decision 1)
- `secondary_company_id` beside the role pivot (`adr/0003` decision 6)
- `is_enabled` beside `revoked_date` (`adr/0003` decision 1)

Here the drift has a sharper edge than usual. The two columns would be **separately unique**,
so nothing at the database level stops one person's employee row and another person's account
row holding the same number — and the login would resolve to whichever the query checked
first. A lookup that reads `employees` first and falls back to `users` is a different system
from one that reads `users` first, and **both look correct in review.**

### 4. Rejected — email login for Master Admin only

The other small fix: keep the phone number on `employees`, and let the three accounts with no
employee sign in with the email address they already have.

**Rejected because it creates a second authentication path used by three accounts** —
`adr/0004` decision 2 caps Master Admin at three, and the Director holds one of them.

A second path is not simply more code. It is more code **that almost never runs**: three
accounts across the whole group, logging in rarely, against a route the other several hundred
people never touch. It would be the least-exercised authentication path in the system, and it
would guard **the accounts that bypass tenant scope and read every salary in the group**
(`adr/0005` decision 5, `adr/0004` decision 3). The throttle tiers, the generic failure
message, the lockout, the `security_events` write and the forced password change would all
need a second correct implementation, and a mistake in any of them would sit undiscovered
precisely because nobody uses the path.

It also reintroduces email as a credential, which BR-A1 removed on purpose — `users.email` is
nullable because most of this workforce has none, so "email login" would work for exactly the
accounts that are hardest to notice being wrong.

**One authentication path, exercised by everybody, is worth more than a convenient exception
for three accounts.**

### 5. Master Admin gets a real phone number, and the seeder requires it

`MasterAdminSeeder` reads the number from the environment alongside the existing credentials,
through `config()` and never `env()` directly — the same rule §5.8 already states, for the
same reason: after `php artisan config:cache`, which production runs, `env()` returns null and
the seeder would abort pointing at a variable that is demonstrably present.

**The seeder must fail loudly when the number is absent**, exactly as it does for a missing
password. An account created without a username is the defect this ADR exists to close, and
producing one silently would reproduce it with extra steps.

### 6. Uniqueness is group-wide on `users`, and a transfer does not touch it

One unique index across all accounts. A person keeps their number when they transfer between
group entities, because the account is the same account — this follows from `adr/0003`
decision 6 (one person, one record, `company_id` means payroll employer only) without needing
a rule of its own.

---

## Consequences

**Positive**

- **The installer's account can log in.** The system becomes enterable, which it currently is
  not.
- The credential lives with the thing that authenticates. `users` is now self-sufficient for
  login: identifier, password, state, and the throttle counters added on 2026-08-12.
- `AuthenticationService::findByPhone()` loses its join through `employees` — and with it the
  `withoutGlobalScope(TenantScope::class)` release that join required. Fewer moving parts on
  the pre-authentication path, which is the path with no user to scope against.
- The storage now matches the interface. `employee-master.spec.md` §6.4 renders the field
  read-only and offers no edit path; after this it is not on the employee record at all, so
  there is nothing to render read-only and no question to answer about why.

**Costs and constraints accepted**

- **A migration that moves a NOT NULL unique column between tables.** It must create the
  column on `users`, copy each employee's number to their account, and drop it from
  `employees` — in one migration, since a gap between the two leaves accounts unable to
  authenticate. No production data exists yet, which is the only reason this is cheap; the
  same change in six months is a different piece of work.
- **Every employee must have an account before this holds.** BR-A20 already requires exactly
  that — the account is created in the same transaction as the employee — so the copy is
  total. If an employee ever existed without an account, their number would have nowhere to
  land, and the migration must fail rather than discard it.
- **Employee Master loses a column it never owned but did hold.** Whether HR needs a *contact*
  number distinct from the login number is now an open question for that spec: the two are the
  same number today, and a second column would be the duplication decision 3 rejects unless it
  is genuinely a different fact. **Recorded as open, not decided here.**
- **`employees.phone_no` appears in documents that must be corrected in the same commit as
  the migration**: `auth-rbac.spec.md` BR-A1, §3, §6 and §7; `employee-master.spec.md` §6.4;
  `adr/0004` decision 6; `schema.md` on both tables. Leaving any of them saying `employees`
  puts the next reader back into the contradiction this ADR resolves.
- **Tests and factories move with it.** `EmployeeFactory` currently generates `phone_no`;
  `UserFactory` must instead, and `forEmployee()` must not leave an account without one.

**Explicitly not changed**

- The username is still the phone number (BR-A1). Email still authenticates nothing.
- Normalisation, the 9–12 digit rule, and the single-normaliser requirement are untouched.
- Master Admin still has no employee record (`adr/0001` decision 4). This ADR is what makes
  that survivable rather than fatal.
- `phone_no` is still edited only from the account management screen, by `HR` or Master Admin
  (`adr/0004` decision 6, `auth-rbac.spec.md` §6). Moving the column does not widen who may
  change it.
- The three-account Master Admin cap and its minimum of one stand (BR-A13).

---

## Follow-up

Required before this ADR is satisfied, all in the implementing branch:

1. One migration: add `phone_no` to `users` (NOT NULL, unique), backfill from `employees`,
   drop `employees.phone_no`. It must abort rather than proceed if any employee has no
   account.
2. `AuthenticationService` resolves the account directly; the `employees` lookup and its
   scope release go.
3. `MasterAdminSeeder` requires a number from configuration and fails loudly without one.
4. A test asserting **a seeded Master Admin can log in** — the defect in § Context, pinned so
   it cannot return. Nothing currently covers it, which is how it shipped.
5. A test asserting **an employee cannot be created without an account**, since the backfill
   depends on BR-A20 holding.
6. The document corrections listed in § Consequences, in the same commit as the migration
   (`CLAUDE.md` Principle #5).

---

## References

- `auth-rbac.spec.md` BR-A1 — the username is the phone number; placement superseded here
- `auth-rbac.spec.md` §6, §7 — `phone_no` is a credential, edited from the account screen
- `auth-rbac.spec.md` §5.8 — `MasterAdminSeeder`, and `config()` rather than `env()`
- `employee-master.spec.md` §6.4 — read-only on the employee form, for everyone
- `adr/0001` decision 4 — Master Admin has no employee record
- `adr/0003` decision 1 — one fact, one place; the reasoning decision 3 reuses
- `adr/0003` decision 6 — one person, one record; `company_id` is the payroll employer
- `adr/0004` decision 2 — `system_access`; the three-account cap
- `adr/0004` decision 3 — Master Admin reads salary, which decision 4 weighs
- `adr/0004` decision 6 — who may change `phone_no`
- `docs/schema.md` — `users`, `employees`
- `CLAUDE.md` Principle #1, Principle #5
