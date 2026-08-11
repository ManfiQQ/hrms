# Business Rules

> Sourced from the ES SOFEEYA ENTERPRISE Employee Handbook (effective 01 November 2024)
> and the legacy AHS system's `AGENTS.md`. All six current group entities share these
> values today, but every number below **must** be implemented as a per-company
> `policy_configurations` entry, not hardcoded — see `conventions.md` §5.

---

## Company Group Reference

| Entity | Code | Role |
|---|---|---|
| AL HADDAD SUCCESS SDN BHD | AHS | **Parent — also an operating tenant** |
| AL HADDAD INTEGRATED MARKETING | AIM | Subsidiary |
| ES SOFEEYA ENTERPRISE | ES SOFEEYA | Subsidiary |
| ZISH GLOBAL PLT | ZISH GLOBAL | Subsidiary |
| TURSENIA TRADING | TURSENIA TRADING | Subsidiary |
| SLEGHO ALYA KITCHEN | SLEGHO | Subsidiary |

**Six entities: one parent and five subsidiaries.** `ES SOFEEYA` is two words — that is
the registered spelling, not a typo. **THALHAH is a brand under AIM, not an entity**, and
does not exist as a company in this system.

**AHS is a parent *and* an operating tenant** with its own staff — not an empty holding
row. It is seeded and appears in the company picker like any other company.

**Master Admin may add further companies later without a migration.** See `adr/0003`
decision 9. Canonical spelling for every entity above is `CLAUDE.md` §5.

---

## Employment Categories

- **Sepenuh Masa (Full-time)**
- **Freelance** — probation-equivalent, 3 months, extendable/shortenable at company
  discretion. No offer letter issued until confirmed permanent.
- **Separuh Masa (Part-time)**

## Probation & Confirmation

- Freelance probation: 3 months to determine suitability.
- Confirmation letter issued 6–12 months after probation ends.
- Specialization/promotion: performance-based, written offer, effective immediately.
  If unsuitable within trial, employee reverts to previous position and pay.

## Resignation & Termination

- **During probation/freelance:** terminated immediately or within 24 hours if not
  confirmed.
- **After confirmation:** 1 month notice, or company discretion.
- **Immediate termination without notice/pay** for: khalwat, misconduct, negligence,
  serious contract breach, bribery, intentionally injuring another employee, criminal
  activity (drugs, theft, prostitution, robbery), inciting workplace disharmony.
- Resignation letter to HR with supervisor endorsement. Company property must be
  returned; lost/damaged items chargeable to employee.
- **Absence 3+ consecutive days** without reasonable notice → grounds for up to 3
  warning letters or termination, at company discretion. Can also forfeit Bonus.

## Retirement Age

75 years (male and female).

---

## Working Hours

| Day | Hours |
|---|---|
| Mon–Fri | 9:00 AM – 5:00 PM |
| Sat | 9:00 AM – 5:00 PM |
| Sun | Off |

- **Friday:** 2-hour break for Muslim male staff for Friday prayers — exact timing
  variable, set by Director. Non-attendance without reason is subject to disciplinary
  action. (The Director *sets* this timing as a configuration value, entered into
  `policy_configurations`; it is not an approval action — see § Director Discretion.)
- **Muslim female staff:** standard lunch break 1:00 PM – 2:00 PM.
- Thumbprint in/out mandatory each time + WhatsApp group update. Failure to do either
  = considered absent for the day.

---

## Attendance & Overtime

Sourced primarily from the legacy system's `AGENTS.md`, which had this correctly
specified — carried forward as-is:

- **Weekday OT is never calculated automatically from fingerprint OUT time.** All
  weekday OT requires an approved OT Request form.
- **Saturday compulsory OT is the only attendance-derived OT rule** — the only case
  where OT is computed automatically from punch data.
- OT duration counted in 30-minute increments.
- OT weekly closing: **Sunday 00:00**. Late submissions are system-rejected and logged
  as a special case.
- OT rate: **RM8.00/hour**. OT packing: small product RM0.15, large product
  RM0.30–0.50 (rates variable, subject to company change).
- Saturday's extra ~3 hours accumulate into a monthly "Hours" bank — paid out if the
  bank exceeds 8 hours in a month, otherwise carried forward to the next month.
- Hours-bank usage must be requested by 7:00 AM on the day of use.

### Lateness Penalty

- Lateness exceeding 1 hour consecutive → attendance allowance automatically forfeited
  for that occurrence.
- Escalating penalty: 1st occurrence RM8, 2nd RM10, 3rd RM12 (per month, resets
  monthly — assumed, not explicitly stated).
- ⚠ **Ambiguous in source document.** Clause 9.1.3's worked example contains numbers
  that don't reconcile cleanly. **Confirm the exact formula with the company before
  implementing this in payroll logic.**

---

## Leave

| Type | Entitlement |
|---|---|
| Annual | 14 days/year (1 year+ service), pro-rata monthly accrual |
| Annual — request limit | <2 years service: max 3 days/month · 2+ years: max 2 weeks/month |
| Sick (MC) | 5 days/year paid (1 year+ service) |
| Hospitalization | Max 60 working days (inclusive of non-hospitalized sick leave) |
| Maternity | 60 days full pay (basic only), max 6 confinements |
| Paternity (Cuti Bersalin Bapa) | 7 days |
| Marriage (Cuti Kahwin) | 3 days, confirmed staff only, apply 14 days in advance |
| Haji/Umrah | Max 14 consecutive days paid, 2+ years service; excess = unpaid; deducted from annual leave. Within entitlement, routes through the standard chain. Grants **beyond** entitlement are a Director decision made off-system and entered as a manual override — see § Director Discretion, **not** a Director approval stage |
| Compassionate (Cuti Ehsan) | Deducted from sick leave first, then annual leave; immediate family only |
| Unpaid | Not default policy; confirmed staff only, company discretion |

### Notes & Open Items

- Annual leave: Saturday counted as a normal working day for accrual purposes.
  Resigned employees are entitled to pro-rata payout of unused annual leave (subject
  to approval).
- Sick leave 3+ consecutive days must be certified by a government clinic/hospital
  only — private clinic MCs not accepted for this duration. MCs from a clinic more
  than 20km from residence/workplace not accepted.
- ⚠ **PENDING CONFIRMATION — miscarriage/keguguran week threshold.** Source
  handbook contradicts itself: clause 7.6.3 states 20 weeks, clause 7.6.5 states 28
  weeks, for the same rule (miscarriage before this threshold treated as sick leave,
  not maternity leave). **Do not implement until the company confirms the correct
  figure.**
- ⚠ **AMBIGUOUS — prolonged illness pay table (clause 7.5).** Source table's month
  structure is internally inconsistent. Best-guess interpretation: months 1–2 full
  pay, months 3–4 unpaid — **confirm before implementing.**

---

## Payroll & Statutory

- **EPF (KWSP):** Contribution base is **RM1,700 only** (basic salary), not full wage
  including allowances. Employer 13%, Employee 11%.
  ⚠ This is narrower than the typical statutory definition of "wages" under the EPF
  Act. Not a system bug — a company policy choice. Recommend the company verify this
  with their accountant. Regardless, this value must remain a configurable field
  (`policy_configurations`), not hardcoded, so it can be corrected without a code
  change if needed.
- **SOCSO/PERKESO:** Threshold RM2,000/month per the handbook — **verify current
  statutory threshold before go-live**, as SOCSO/EIS rules have changed nationally
  since this handbook's effective date and may no longer match.
- Salary paid via bank transfer, 1st–7th of each month.
- Bonus: once a year, subject to company profit/performance and Director's discretion,
  tied to KPI/performance evaluation. The Director's discretion here is exercised
  off-system; declaring, adjusting, or withholding a bonus is entered by HR or Master
  Admin as an audited manual override — there is no Director approval step. See
  § Director Discretion.

> **Two structural facts for the Payroll spec**, confirmed while speccing Employee Master
> and captured in `docs/modules/payroll-notes.md` so they are not lost: **basic salary is
> not static** (HR raises it over an employee's tenure, so it needs a history ledger
> rather than an overwritable field), and **allowances (*elaun*) are not a fixed set**
> (HR creates types manually with custom values, so they need dynamic `allowance_types` +
> `employee_allowances` tables rather than fixed columns). Neither is built in Phase 1.

---

## Approval Hierarchy

Sourced from the legacy system's `AGENTS.md` — well-specified, carried forward directly,
then extended by `adr/0001` to add the HOD tier and to separate Master Admin structurally.

**Authority is read from `employee_roles`, never from `level`.** `level` is display-only.
Authority is **per company** — a person may hold a role at one group company and none at
another — and every read filters `WHERE revoked_date IS NULL` (`adr/0003` decision 1).

### Standard routing

- Staff / Supervisor requests → require Manager **and** HR approval.
- Manager requests → require HR approval.
- No user may approve their own request.
- A higher approval stage may override a lower pending stage only when an explicit
  business rule allows it, and the override must be audited.

### HOD tier

- **An HOD is optional per department.** Some departments have an assigned HOD, some
  don't, and it varies **between departments within the same company**. Routing must
  therefore resolve the HOD chain **dynamically at request time** — check whether the
  requester's department has an assigned HOD **employed by the requester's own company**
  before deciding stage order. The chain cannot be precomputed from the requester's roles
  alone.
- **HOD as approver — skip-stage rule:** where such an HOD exists, they may approve
  **directly, skipping the Manager/Supervisor stage**, for requests originating in that
  department **from their own company's staff**.
- **HOD's own requests route directly to HR**, skipping Manager and Supervisor, since an
  HOD outranks both.
- Where no such HOD exists, the standard chain above applies unchanged.
- **An HOD's authority is strictly same-company.** An HOD approves only for employees
  who share their own `employees.company_id`. Branches and departments may be shared
  across group companies (`adr/0002`) — the Logistics branch mixes AIM, TURSENIA and
  ES SOFEEYA staff, HQ Marketing draws from several companies — but **sharing a
  department with someone does not put them under that HOD's authority**. An AIM HOD of
  shared Logistics approves for the AIM staff there and **not** for the TURSENIA or
  ES SOFEEYA staff.
  **No cross-company HOD authority exists.**
- **Therefore HOD resolution is per (department, company), not per department.** A shared
  department may correctly hold more than one HOD — up to one per company represented in
  it. Where the requester's company has no HOD in their department — including when the
  department has an HOD employed by a *different* company — the HOD stage does not apply
  and the standard Manager/Supervisor → HR chain runs unchanged. The request is not
  blocked. See `adr/0002` decision 4.

### Master Admin

- **Master Admin is a structurally separate account, not a flag on an employee login.**
  It has no linked Employee record at all.
- It **submits nothing** (Leave, Hours, OT, claims) — with no Employee profile it has no
  entitlements and nothing to file.
- It **approves nothing in the normal chain** — it is not a routing stage.
- It exists **solely for oversight and data-repair access**.
- This makes "no self-approval" structural rather than a logic check: the account has
  nothing of its own to approve, so there is no path to forget to guard.
- A person needing both normal employee access and master admin access holds **two
  separate accounts with two separate logins**. Intentional — see `adr/0001` and
  `schema.md` § `users`.

### HR ↔ Assistant Director peer approval

**HR and Assistant Director approve each other.** This is the top of the system's formal
approval chain.

- An **HR** request is approved by an **ASSISTANT_DIRECTOR**.
- An **ASSISTANT_DIRECTOR** request is approved by **HR**.
- Single stage — no second approver above it.
- The no-self-approval rule still binds: where two people hold the same role, one
  may not approve their own request, but a peer holding the same role may approve it.
- **The counterpart search is group-wide.** These two roles approve across companies (see
  below), so a company with an HR but no Assistant Director of its own is **not** blocked
  — that HR's requests route to an Assistant Director at any group company.
- If no counterpart account exists **anywhere in the group**, the request cannot be routed
  and must be **held as blocked with a clear reason**, not silently auto-approved and not
  escalated to Master Admin. Master Admin approves nothing in the normal chain.

This replaces the legacy rule that routed HR and Assistant Director requests to Master
Admin, which is incompatible with Master Admin's structural separation. See `adr/0001`
decision 6.

### Cross-company approval — HR and Assistant Director only

**`HR` and `ASSISTANT_DIRECTOR` are the only `employee_roles.role` tiers that may approve
across companies.** Their approval authority is not restricted to their own
`employees.company_id`. Every other tier — `SUPERVISOR`, `MANAGER`, and `HOD` — approves
strictly within its own company, and an employee with **no `employee_roles` row holds no
approval authority at all** (there is no `STAFF` role value; `adr/0003` decision 1).
Every cross-company approval is written to
`audit_logs`.

**Approving is not seeing.** An HR or Assistant Director who approves a cross-company
request gets **the request and the context needed to decide it, and nothing else**. It
does **not** automatically grant read access to that employee's salary, payroll, personal
documents, family records, disciplinary history, or full leave history. Data visibility is
governed by a **separate permission check**, evaluated independently of whether the user
holds an approval stage on the request.

**That visibility check is now defined — `adr/0004` decision 1.** Read scope comes from
where the account's employer sits in `companies.parent_company_id`: employed by **AHS**
(the parent) reads the **whole group**; employed by a **subsidiary** reads **that
subsidiary only**. It is derived, never configured, and there is no manual override.

**The two rules are independent, and they disagree by design.** A subsidiary-employed `HR`
approves across the whole group (this rule) while reading **one company only**. No code may
treat "is an approver on this request" as an answer to "may read this employee's data" —
that remains true, and it is now testable rather than merely asserted. See `adr/0002`
decision 5 and `adr/0004` decision 1.

⚠ **The Auth & RBAC spec is still unwritten** (`docs/modules/auth-rbac.spec.md`). The
decisions exist; the spec does not, and under `CLAUDE.md` Principle #1 **no Auth code may
be written until it does**.

> **Salary access is the `ACCOUNT` role, not an HR sub-scope.** Only an employee holding
> the `ACCOUNT` role may read salary data, at the company where they hold that role.
> **No `HR` account may, however many HR staff there are, and group-level employment does
> not change it.** `ACCOUNT` is a hardcoded restricted role that only Master Admin may
> grant, so it cannot be granted from inside HR — the rule is structural, not merely
> declared. See `adr/0003` decisions 3 and 5.
>
> **Two account types read salary without holding any role**, because they hold no roles at
> all: `system_access = FULL` (Master Admin) and `VIEW_ONLY` (`adr/0004` decision 3).
> Neither was ever a target of this restriction — **what HR must not see is salary**, and
> that line is unchanged.
>
> An earlier `hr_scope` (`PAYROLL | OPERATIONS`) split of HR into a Payroll scope and an
> Operations scope is **withdrawn** — that distinction does not exist.

### The chain tops out here

**The system's formal approval chain ends at HR ↔ Assistant Director peer approval. No
request in the system routes to a Director for approval.** See § Director Discretion.

---

## Director Discretion (off-system)

The source handbook refers throughout to **Pengarah Syarikat** / Director authority —
Haji/Umrah leave beyond entitlement, the final decision on a disciplinary appeal, bonus
declaration, and policy exceptions generally.

**All of these are policy text describing real-world authority. None of them is a digital
in-system approval step.** The Director does not hold an approval stage, does not appear
in any routing chain, and no request is ever assigned to a Director for action in the
system. Treating handbook prose about who decides something in the company as a
specification for a software workflow stage is the mistake this section exists to
prevent.

**How Director decisions are honored instead.** When a Director exercises discretion in
real life — which is rare and happens off-system — **HR or a Master Admin account records
it as a manual override**, reusing the same audited-correction pattern already designed
for attendance corrections:

| Field | Meaning |
|---|---|
| `old_value` | the value the system held before the override |
| `new_value` | the value the Director's decision requires |
| `reason` | the justification, including reference to the Director's decision |
| `corrected_by` | the HR or Master Admin **user** who entered it |

Every such override is written to `audit_logs`. The result is that a Director's decision
is **traceable to the person who entered it and the reason given**, rather than being an
untracked exception or an approval stage that would sit empty almost all the time.

**Clauses honored by manual override, not by a Director approval step:**

- Haji/Umrah leave granted beyond the standard entitlement
- Final decision on a disciplinary appeal
- Bonus declaration and bonus withholding
- Any other policy exception granted at Director discretion

See `adr/0001` decision 7.

---

## Discipline

- Warning letters: up to 3 issued for a given disciplinary matter, at company
  discretion.
- Employee has the right to appeal to the Director before a final decision. **The appeal
  and the Director's final decision happen off-system** — the system records the
  *outcome* as an audited manual override entered by HR or Master Admin, and does not
  route the appeal to a Director for in-system approval. See § Director Discretion.
- Possible actions (Director's discretion, per severity): verbal/written warning
  (up to 3x) → withhold salary increment → stop declared bonus → revoke benefits
  (e.g. 2-week leave eligibility for 2+ year staff) → immediate termination. Same
  treatment: the decision is the Director's, the record is a manual override.
- No Gift Policy: any gift beyond RM100 (excluding from friends/family) must be
  declared to management.
