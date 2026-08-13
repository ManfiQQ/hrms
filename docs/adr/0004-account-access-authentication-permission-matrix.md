# ADR 0004 — Account Access, Authentication, and the Permission Matrix

- **Status:** Accepted — **decision 6 amended 2026-08-12** (failed attempts are written to
  `security_events`, not `audit_logs`; see the amendment note there)
- **Date:** 2026-08-11
- **Extends:** `adr/0001` decision 5 (account provisioning) and decision 7 (Director
  authority is off-system); `adr/0003` decision 5 (salary visibility is the `ACCOUNT`
  role — **restated and widened here**, see decision 3)
- **Closes:** `CLAUDE.md` §10 — the `system_access` value-set question; the read-scope
  half of "data visibility vs approval authority"
- **Does not decide:** the employee self-service module — see § Still open
- **Affects:** `users`, `employees`, `employee_roles`, `policy_configurations`,
  Employee Master spec §6, Auth & RBAC spec (not yet written — this ADR is its primary
  input), `CLAUDE.md` §10, §11, `schema.md`

---

## Context

`adr/0003` settled *what authority a person holds* — roles in a pivot, per company.
It deliberately did not settle *what an account may read*, or *how a person proves who
they are*. Both were left to the Auth & RBAC spec, which does not exist, and which cannot
be written until the decisions below are made.

Three things forced this ADR now rather than later.

### `employee-master.spec.md` §6 is wrong for how this group operates

§6 scopes `HR` and `ASSISTANT_DIRECTOR` reads to **their own company only**. The client
has confirmed that HR, Assistant Director, and Account all work **under AHS**, the parent
company, administering every entity in the group. There is no "HR of TURSENIA."

Shipping §6 as written would block HR on the system's first day: they would open the
employee list and see AHS staff only. The table was flagged rather than changed, because
the correct scope is an Auth & RBAC decision and writing an answer into Employee Master
would create a second source of truth. This ADR is that decision.

### `system_access` was referenced but never defined

`adr/0001` decision 5 provisions Master Admin with `FULL` and Director with `VIEW_ONLY`,
then states plainly that the field does not exist in `schema.md` and that the value
regular staff accounts receive is unspecified. No provisioning code — including
`MasterAdminSeeder` — can be written while that is true.

### The permission matrix had no rows, and login had no design

`adr/0003` decision 5 answers salary. Nothing answered personal data, documents,
disciplinary records, or leave — each would otherwise be decided ad hoc by whoever built
the module that touched it first, which is the drift pattern `CLAUDE.md` §9 records from
the legacy system. And nothing at all addressed how a person logs in, which matters
disproportionately here: most of this workforce is field staff without company email.

---

## Decision

### 1. Read scope comes from the employer's position in the company hierarchy

**An account's read scope is determined by where its employer sits in
`companies.parent_company_id`, not by which role it holds.**

- Employed by **AHS** (the parent) → reads across **the whole group**.
- Employed by a **subsidiary** → reads that **subsidiary only**.

This applies uniformly. `HR`, `ASSISTANT_DIRECTOR`, and `ACCOUNT` see the whole group
because they are employed by AHS — not because of the roles they hold. A future HR hired
by a single subsidiary would see that subsidiary only, with no code change.

**Scope and data type are separate axes.** Scope answers *which companies*; role answers
*what data within them*. HR and Account both see the whole group; only Account sees salary
(decision 3). Collapsing the two axes into one is what made §6 wrong.

**There is no manual scope override, and none may be added.** Scope is derived, never
configured per account. A stored override would be a second answer to a question the
hierarchy already answers, and the two would eventually disagree — the same reasoning that
rejected `secondary_company_id` (`adr/0003` decision 6) and the `is_enabled` flag
(`adr/0003` decision 1). Where a narrower scope is genuinely wanted, the answer is to
employ the person at the subsidiary, not to add a switch.

**Consequence for growth:** a seventh entity added under AHS becomes visible to
group-level staff automatically. Nothing is provisioned, and nothing can be forgotten.

**Rejected alternatives.** *Scope from the role* (`HR` always means group-wide) works today
but hardcodes "all HR are group-level" into the permission layer, and cannot express a
subsidiary-specific HR later. *Scope from pivot rows* (one `HR` row per company) is
consistent with `adr/0003` but requires six rows per HR person and fails **silently** when
one is missed — a company's staff simply vanish from the list with no error, the same
failure mode `adr/0002` exists to prevent. *Scope from a `system_access` value* creates a
third axis beside roles and hierarchy, maintained by hand.

### 2. `system_access` — three values

```
FULL       Master Admin. Reads and writes everything, bypasses tenant scope.
VIEW_ONLY  Read-only across the group. Writes nothing, approves nothing.
STANDARD   Everyone else. Permissions come entirely from employee_roles + decision 1.
```

`system_access` is an **account dimension, not an authority role** (`adr/0001` decision 5).
It answers "what kind of account is this," which roles cannot answer for accounts that have
no employee record at all.

| Value | Employee record | Scope | Salary |
|---|---|---|---|
| `FULL` | **None** — `employee_id` is null | Whole group, tenant scope bypassed | Yes |
| `VIEW_ONLY` | **None** — `employee_id` is null | Whole group, read-only | Yes |
| `STANDARD` | Yes | From employer's hierarchy position (decision 1) | Only via `ACCOUNT` |

**`STANDARD` deliberately covers everyone from an intern to an Assistant Director.** They
differ by *role*, not by *account type*, and this field is not the place to express that.

**`VIEW_ONLY` currently has no holder.** The Director — its intended user — holds a Master
Admin account instead (decision 4). The value is retained rather than removed because
`adr/0001` decisions 5 and 7 both name it, and a genuine use is foreseeable: an external
auditor, or a second Director who should not hold write access. It must be documented as
**defined but unused**, so that nobody searches for the Director's `VIEW_ONLY` account and
concludes data is missing.

**A fourth value for ordinary employees was rejected.** An employee with no
`employee_roles` row *already* has exactly self-service access; a `SELF_SERVICE` value
would restate a state the absence of rows already expresses, and the two would eventually
disagree.

### 3. Salary visibility — restated

`adr/0003` decision 5 reads "salary visibility is the `ACCOUNT` role." That was correct for
employee accounts and is now incomplete, because Master Admin and Director accounts hold no
roles at all. The full rule:

> **Salary data may be read by:**
> 1. Holders of the `ACCOUNT` role, within their scope (decision 1);
> 2. `system_access = VIEW_ONLY` — read-only, group-wide;
> 3. `system_access = FULL` — Master Admin.
>
> **The `HR` role never grants salary access, without exception.**

The last line is the rule's actual purpose and is unchanged. What HR must not see is salary;
Master Admin and Director were never the targets of that restriction.

This does **not** reopen `hr_scope`, which stays withdrawn (`adr/0003` decision 5). There is
still no "payroll HR."

### 4. Master Admin accounts

| Rule | Value |
|---|---|
| First account | Created by `MasterAdminSeeder`, credentials from `.env` (`adr/0001` decision 5) |
| Subsequent accounts | Created by an existing Master Admin |
| **Maximum** | **3** — an attempt to create a fourth is rejected |
| **Minimum** | **1** — an attempt to delete or disable the last one is rejected |

Both limits are **enforced by the system**, not by policy. A single Master Admin is a single
point of failure: lose that credential and nobody can grant the `ACCOUNT` role, repair data,
or manage job functions. Unlimited Master Admins is unbounded full access.

**The Director holds a Master Admin account, not a `VIEW_ONLY` one.** The client requires
the Director to see everything in detail, including salary, and to be able to act. Giving
`VIEW_ONLY` the power to create accounts was considered and **rejected outright**: an
account that can create a full-access account *is* a full-access account with one extra
step, and permitting it would empty `VIEW_ONLY` of meaning. The Director therefore holds one
account, `FULL`, and `VIEW_ONLY` stays strictly read-only.

**The Director has no employee record.** `employee_id` is null, the same structural shape as
Master Admin (`adr/0001` decision 4). The Director takes no leave, files no attendance, and
their pay is handled outside this system, so an employee row would be permanently empty —
and an empty row invites someone to fill it. This is consistent with decision 7: `DIRECTOR`
is not a role, and now it is not an employee either.

### 5. Account lifecycle — freeze, then expire

**The problem this closes:** without it, a resigned employee's login keeps working. If they
held `HR`, they can still create accounts; if they held `ACCOUNT`, they can still read every
salary in the group. This is the most common way HR data leaks after someone leaves.

Setting `staff_status` to `RESIGNED` or `TERMINATED` triggers, in the same transaction:

**Stage 1 — Freeze, immediately.** The account may read **its own data only**. No writes, no
approvals, no account creation, no role grants. All `employee_roles` rows are revoked
(`revoked_date` set, `adr/0003` decision 1) — the rows remain for history.

**Stage 2 — Expire, 10 days after `effective_date`.** No access at all. All data remains in
the system.

The 10 days run from **`effective_date`** — the person's actual last working day — not from
the date HR typed the change. `employee_status_history.effective_date` already exists for
exactly this (`adr/0003` decision 8), so HR can record a departure in advance without
cutting the person off early.

**Freezing writes while allowing self-reads is the point.** The dangerous act is writing,
not reading. Cutting writes immediately closes the leak; leaving self-reads open lets the
person retrieve their own final payslip or letters during handover.

**The countdown is visible on five dashboards** — the employee's own, HR's, Account's, Master
Admin's, and the employee's manager or supervisor's. This is the correction mechanism: a
status set in error is caught because five parties see a countdown appear, not because
someone remembers to check an audit log. It replaces a "cancel" button, which would be a
permanent hole opened for a rare mistake.

**No account may be reactivated after `RESIGNED` or `TERMINATED` — by anyone, including
Master Admin.** A rejoining employee gets a **new employee record, a new `employee_no`, and
a new account** (`adr/0003` decision 9, `business-rules.md` BR-2). This is not bureaucracy:
pay and allowances on return are frequently different from before, and a new record keeps
that clean rather than overwriting history.

**Resignation and termination differ in who approves and when the clock starts:**

| | `RESIGNED` | `TERMINATED` |
|---|---|---|
| Initiated by | **The employee** — one month's notice | **HR** |
| Manager / Supervisor | Reviews and **approves** | Reviews — **non-blocking** |
| Countdown starts | On the last working day | **Immediately** |

Termination does not wait for approval because it may follow serious misconduct
(`business-rules.md` § Resignation & Termination lists immediate-dismissal grounds), and
waiting would leave full access in the hands of the person being dismissed.

The resignation request flow itself — the employee submitting it, the routing, the one-month
notice check — belongs to an Offboarding module and is **not specified here**. Only its
effect on the account is.

### 6. Authentication — username, password, throttling, session

**Username is the employee's phone number.**

Email is nullable on `employees` and much of this workforce has none — factory crew, studio
staff, live hosts. A phone number is something every employee has and remembers. It is
therefore the login identifier.

Consequences that must be implemented:

- **The login username requires a unique index.** It currently has none. Two accounts
  sharing a number — a married couple at the same workplace, or a typo — would make login
  ambiguous.

> **⚠ Amended 2026-08-12 — the username lives on `users`, not `employees`** (`adr/0006`).
> This decision is otherwise unchanged: the number is still the username, still normalised,
> still unique. Only the table changed, and it had to — this decision and `adr/0001`
> decision 4 together left Master Admin with nowhere to keep its own username, which made
> the installer's account impossible to log into. `employees` now holds no phone number at
> all, and no separate contact number either (`adr/0006` decision 7).
- **The system normalises the number** before storing and before comparing: strip spaces,
  dashes, and a leading `+60` or `60`. `012-345 6789`, `0123456789`, and `+60123456789` are
  one number and must all work.
- **Validation: 9–12 digits after normalisation.** Malaysian landlines run 9–10, mobiles 10,
  and `011` numbers 11.
- **Only HR and Master Admin may change a phone number.** Since it is the username, an
  employee changing it themselves could take over another person's identifier or lock
  themselves out.

**Password: minimum 6 characters, no composition rules.**

No forced uppercase, digits, or symbols. Complexity rules produce `Abcd1234!` and passwords
written on paper; a memorable phrase is stronger than a short complex string kept on a
sticky note.

Six was chosen by the client over the recommended eight, with the trade-off understood: the
username is not secret, so password strength is the only barrier, and six characters is weak
on its own. **This is why decision 6's throttling is deliberately aggressive** — it is
carrying the weight the password length does not.

**Password reset: HR and Master Admin only.** Not self-service by email — most employees
have none. Not `ACCOUNT`, who reads everything but administers nothing: seeing data and
controlling access are different jobs, and this separation is deliberate.

**Failed-login throttling — four tiers:**

| Cumulative failures | Result |
|---|---|
| 3 | Locked 5 minutes |
| 6 | Locked 10 minutes |
| 9 | Locked 15 minutes |
| 12 | **Locked permanently** — HR or Master Admin must unlock |

- The counter **resets on successful login**. Without this, three typos spread over months
  would eventually lock someone out.
- **Failed attempts are written to `security_events`.** A hundred failures against the
  `ACCOUNT` holder's login overnight is something the group needs to be able to see.

> **⚠ Amended 2026-08-12 — this said `audit_logs`.** The requirement is unchanged: failed
> attempts are recorded, and a hundred overnight failures must be visible. Only the
> destination moved.
>
> `docs/modules/audit-trail.spec.md` decision 1 splits the trail in two — `audit_logs` for
> changes to data, `security_events` for authentication events — because a failed login has
> **no `old_value` and never will**, and one table holding both would need a rule about
> which columns are meaningful for which event type that nothing would ever write down.
>
> **Two consequences for this decision.** The `security_events` write is **non-blocking**
> (that spec's BR-AT8): authentication must not depend on a table write, or one database
> fault locks everyone out, Master Admin included. So the **throttle counter above is not
> derived from the log** — it is the Auth module's own, keyed on the account, and the four
> tiers must hold with the table unwritable. Every document stating the old destination was
> corrected in the same commit as this amendment.

**Session: expires after 2 hours of inactivity** — inactivity, not time since login. Someone
working all day is never interrupted; what expires is a session left open. This matters most
for field staff, who may use a shared terminal at the factory or studio: a session left open
there is the next person's session.

Every number in this decision — password minimum, the four throttle tiers, the session
window, the activation validity in decision 7 — lives in `policy_configurations`, never
hardcoded (`conventions.md` §5).

### 7. Provisioning — accounts are created with the employee, activated by QR

**The account is created in the same transaction as the employee record.** Not as a separate
step HR must remember.

This is not a convenience. The client requires **every employee to verify their own
attendance data**, and payroll is blocked when attendance data is incomplete. An employee
without an account cannot verify, so an account is an operational requirement for everyone,
not an optional extra for office staff.

**Activation is a single-use QR image, not a temporary password.**

On creation the system generates an image containing a **QR code, the employee's full name,
and the validity period**. HR forwards it by WhatsApp or shows it in person. The employee
scans it, lands in the system already authenticated, and is **forced to set their own
password** before doing anything else.

| Property | Value |
|---|---|
| Single use | **Yes** — the link dies the moment it is scanned |
| Validity | **48 hours**, then HR regenerates |
| On scan | Straight in, password creation forced |
| HR notification | **Yes** — HR is told when the code is used |

**Why this beats a temporary password.** A temporary password is a secret HR knows and the
employee also knows, and it stays valid until changed — so a saved WhatsApp message stays
usable. A single-use code is dead after first scan: even if the image is kept, it opens
nothing. There is no window in which HR holds working credentials to someone else's account.

**Why 48 hours and not seven days.** A WhatsApp image can be forwarded. Anyone holding it
before the employee scans it can activate the account and set the password, locking the real
employee out. Shortening the window and notifying HR on use bounds that exposure; HR
regenerates freely if the employee misses it.

**Using the employee's IC number as the first password was proposed and rejected.** An IC
number is not a secret — it is known to HR, to anyone who has handled the person's file, and
to anyone who has seen a photocopy — and unlike a password it can never be changed. It would
have opened a window, lasting until first login, in which anyone knowing a phone number and
an IC number could enter as that person; the audit log would show the employee themselves.
For a holder of the `ACCOUNT` role that window exposes every salary in the group. The
generated QR carries no such property.

### 8. The employee record — who reads which tab

Employee Master's detail view is tabbed. Access differs per tab, not per record.

| Tab | Supervisor / Manager / HOD | HR / Asst Director / Account | Master Admin / Director | The employee |
|---|---|---|---|---|
| Employment | **Yes** | Yes | Yes | Own |
| Personal | **Yes** | Yes | Yes | Own |
| Family | No | Yes | Yes | Own |
| Education | No | Yes | Yes | Own |
| Employment History | No | Yes | Yes | Own |
| Documents | No | Yes | Yes | Own — see decision 9 |
| Roles & Functions | No | Yes | Yes | Own |
| Status History | No | Yes | Yes | Own |

> **⚠ Amended 2026-08-13 — the Roles & Functions row was added. The table shipped with seven
> rows; `employee-master.spec.md` §7 has always listed eight tabs.**
>
> **Nothing here was wrong. It was silent** — and silence in a permission matrix does not stay
> silent, it gets a default. `EmployeePolicy::viewTab()` let an unrecognised tab name fall
> through to the supervisory branch, fail the two-tab test and return `false`: **an access rule
> nobody chose, arrived at by accident.** The policy now throws on an unknown tab, so the next
> missing row announces itself instead.
>
> **Amended rather than superseded by a new ADR**, and the reason is not that the decision was
> small. It is that **the eighth row has to be read where the other seven are read.** Anyone
> consulting this table to answer *"may a supervisor open tab X"* must get all eight answers in
> one place; splitting the matrix across two documents is precisely the failure this project
> has spent its documentation budget correcting — §6.2 against §6.4 on `ACCOUNT`, §5.7 against
> BR-17, `CLAUDE.md` against `composer.json`.
>
> **⚠ The decision itself did not change, and the reasoning below is already in this table.**
> Supervisors read **No** on every history-bearing tab here — Employment History and Status
> History both — and Roles & Functions carries revoked roles, which is history. It adds job
> functions and the grant/revoke controls too, and `adr/0003` decision 3 already reserves
> those.
>
> **What supervisors keep is the part that matters for supervision.** §7 puts the BR-12
> cross-company line on the **Employment** tab, which they read: *"Also serving at: AHS — BDO,
> Account · AIM — Manager…"*. **They already see who holds what authority today.** This row
> withholds the history, the job functions and the controls — not the answer a supervisor
> needs.
>
> Full reasoning: `employee-master.spec.md` §6.2.
the existing double bound (`adr/0002` decision 4, Employee Master BR-10) is unchanged.

**Why Employment and Personal, and nothing else.** A supervisor needs to know *who reports to
me* and *how do I reach them*. They do not need a copy of someone's IC, their spouse's
identity card number, or where they went to school — none of it bears on supervision.

Restricting them to Employment alone was rejected as too tight: a supervisor who cannot find
a phone number in the system will find it on WhatsApp instead, and the organisation loses
the control entirely. A system that blocks a legitimate need gets routed around.

**Emergency contact is the deliberate exception.** Name and phone number only, surfaced on
the **Employment** tab rather than behind Family. If there is an accident at work the
supervisor is likely the first person present; they need that number without being handed
the whole family record.

### 9. Documents — employees may retrieve their own

An employee may **view and download their own documents** for six of the seven types:

```
IC · PASSPORT · EDUCATION_CERTIFICATE
OFFER_LETTER · CONFIRMATION_LETTER · RESIGNATION_LETTER
```

These are already theirs in any real sense — they submitted the identity documents, and the
letters are addressed to them. Withholding the scans protects nothing and turns every routine
request (a confirmation letter for a bank loan) into an HR errand.

**`OTHER` is not visible to the employee.** It is the deliberate escape hatch for documents
that do not fit the fixed types (`employee-master.spec.md` §10 decision 4), and that makes it
the natural place for internal notes and investigation material. Hiding it gives it a defined
purpose rather than leaving it an undifferentiated bucket.

### 10. Disciplinary records have two layers

Disciplinary records do not exist yet — the module is Phase 3. The access rule is decided now
because the **two-layer split must be in the schema from the first migration**; a flat table
cannot be separated later.

| Layer | Contents | Who reads |
|---|---|---|
| **Decision** | The warning issued, date, summary of grounds | The employee, their Supervisor/Manager, HR, Account, Master Admin, Director |
| **Investigation** | Notes, complainant identity, evidence | HR, Account, Master Admin, Director |

**The employee must see decisions issued against them** — without that there is no basis for
the appeal that `business-rules.md` § Discipline provides for. They must not see the
investigation layer, which frequently identifies a colleague who complained.

**Supervisors and Managers see the decision layer for their own staff.** A manager about to
issue a third warning needs to know two already exist. They do not need the investigation
file.

**Warnings are issued by HR; managers report.** This is the same shape already used for leave
(manager endorses, HR decides) and termination (manager reviews, HR acts) — a consistent
pattern rather than a per-module invention.

### 11. Leave and attendance

| Data | Who reads |
|---|---|
| **Calendar** — who is away, when; public holidays; birthdays | **All staff.** Name and dates only, no reason |
| **Leave balance** | The employee, their Supervisor/Manager, HR, Account, Master Admin, Director |
| **MC — existence** ("medical certificate attached: yes") | Everyone in the approval chain |
| **MC — the document itself** | **HR only** |
| **Attendance records** | The employee, their Supervisor/Manager, HR, Account, Master Admin, Director |

**The calendar is deliberately open.** Colleagues need to know who is away to plan work, and
including public holidays and birthdays makes it a calendar people actually use rather than
an absence list.

**Managers see balances because they endorse.** Endorsing a five-day request from someone
with two days remaining, without being able to see that, is endorsing blind.

**Managers see that an MC exists, but not what it says.** Malaysian medical certificates
routinely state a diagnosis. A manager needs to know the absence is certified — that is what
makes it valid — and needs nothing further. The diagnosis is medical information with no
bearing on scheduling, and exposing it to a direct supervisor invites quiet prejudice in task
assignment. **HR verifies the certificate**, which is HR's job rather than the manager's.

**Consequence for the Leave spec:** the MC attachment must be stored **separately from the
request metadata**. If the certificate lives on the request row, this split cannot be
implemented.

---

## Consequences

**Positive**

- `system_access` is defined, so provisioning code — including `MasterAdminSeeder` — is no
  longer blocked by an undefined field.
- Read scope derives from structure that already exists (`companies.parent_company_id`) and
  scales to new entities without configuration.
- Every category of employee data now has a decided rule, so no module invents its own when
  its turn comes.
- Login works for a workforce without email, which is most of this one.
- Single-use QR activation removes the window in which HR holds working credentials to
  another person's account — a window a temporary password cannot avoid.
- The account-lifecycle freeze closes the most common post-departure leak structurally, in
  the same transaction as the status change, rather than relying on HR remembering a second
  step.
- Two open items in `CLAUDE.md` §10 close or narrow.

**Costs and constraints accepted**

- **Read scope now depends on `employees.company_id`**, whose meaning `adr/0003` decision 6
  deliberately narrowed to "payroll and legal employer." The tension is real and is resolved
  by wording: `company_id` does not *grant* visibility — roles do — it **bounds** the
  visibility a role already grants.
- **Scope depends on the company hierarchy being seeded correctly.** A subsidiary
  mis-parented under AHS would grant its staff group-wide reads. The hierarchy is small and
  rarely changes, but it is now load-bearing and must be covered by a test.
- **A six-character minimum password is weak, and the username is not secret.** Throttling is
  therefore load-bearing rather than defensive depth: if the tiers in decision 6 are relaxed,
  or the counter is not enforced server-side, brute force becomes practical. This should be
  revisited once the system holds real salary and identity-document data.
- **`VIEW_ONLY` is defined with no holder.** It must be documented as unused rather than as
  the Director's value, or the next reader will look for an account that does not exist.
- **The Director holds full write access** — the most powerful account type, held by someone
  whose day-to-day need is reading. Accepted because the client requires detailed visibility
  including salary, and the two-account alternative was declined.
- **QR activation images are forwardable.** Single use plus a 48-hour window plus HR
  notification bounds the exposure; it does not eliminate it.
- **The five-dashboard countdown is a real UI requirement**, not a nice-to-have: it is the
  only correction mechanism for a status set in error, since there is no cancel path.
- **Two Phase 2/3 modules now carry constraints from this ADR** — the disciplinary schema
  must be two-layered from creation, and leave must store MC attachments separately from
  request metadata. Both are cheap now and impossible to retrofit cleanly.

**Explicitly not changed**

- `adr/0003` decision 1 — authority lives in `employee_roles`; scope does not alter it.
- `adr/0003` decision 5 — `hr_scope` stays withdrawn. There is no payroll HR.
- `adr/0002` decision 4 — HOD authority remains strictly same-company.
- `adr/0001` decision 4 — Master Admin has no employee record; the Director now shares that
  shape.
- `adr/0001` decision 7 — Director authority remains off-system; `DIRECTOR` is not a role.
- Approval authority still confers no data visibility. The two are read separately.

---

## Still open

- **Employee self-service.** Confirmed as required but not designed. Employees must be able
  to verify their own attendance data, submit corrections to their own profile **subject to
  HR approval**, and file a resignation request. This is a module of its own, not a section
  of the Auth & RBAC spec, and `employee-master.spec.md` §2 currently lists self-service as
  out of scope for Phase 1. Note that decision 7 already assumes it: accounts exist for every
  employee precisely so attendance verification is possible.

---

## Confirmed but not yet specced — carried forward

**Payroll (Phase 2) — the HR / Account split**

- **HR submits quantities; Account produces money.** HR enters or confirms hours and counts —
  overtime hours, late instances, hours banked and carried forward, Saturday hours, leave and
  half-days taken, which allowances apply. HR does not type currency values at any point.
- **Statutory formulas (EPF, SOCSO, EIS) are entered by Account** and computed by the system.
  HR neither enters nor sees them — a statutory figure exposed to HR would allow basic salary
  to be derived from it.
- **Allowance names may be created by HR, Master Admin, or Account. Only Account sets the
  rate.** The HR payroll form shows allowance names without amounts.
- **The payroll form pre-fills from attendance data.** HR selects an employee, hours populate
  from the imported attendance record, and HR ticks which allowances apply.
- **Payroll cannot proceed on incomplete attendance data.** The system blocks and flags — to
  HR *and* to the employee concerned, who is expected to verify their own record.
- Open for the Payroll spec: whether the allowance rate lives on `allowance_types` (same for
  everyone) or `employee_allowances` (per person). Access is unaffected either way.

**Approval Engine (Phase 0)** — unchanged from `adr/0003`, repeated because this session
added a third instance of the pattern: **manager endorses or reports, HR decides.** It now
holds for leave, for termination, and for disciplinary warnings.

---

## References

- `adr/0001` decision 4 — Master Admin has no employee record (extended to Director here)
- `adr/0001` decision 5 — provisioning; `system_access` defined here
- `adr/0001` decision 7 — Director authority is off-system
- `adr/0002` decision 4 — HOD same-company authority, unchanged
- `adr/0002` decision 5 — approval is not visibility, unchanged
- `adr/0003` decision 1 — `employee_roles` pivot, `revoked_date`
- `adr/0003` decision 5 — salary is the `ACCOUNT` role; restated in decision 3 here
- `adr/0003` decision 8 — `effective_date` on the status ledger
- `adr/0003` decision 9 — retired numbers, rejoining as a new record
- `docs/modules/employee-master.spec.md` §6 — the flagged permission table this ADR resolves
- `docs/conventions.md` §5 — configurable numbers belong in `policy_configurations`
- `docs/schema.md` — `users.system_access`, `users.phone_no`, `employee_roles`
- `CLAUDE.md` §10, §11
