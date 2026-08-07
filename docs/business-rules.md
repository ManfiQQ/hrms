# Business Rules

> Sourced from the ESSOFEEYA ENTERPRISE Employee Handbook (effective 01 November 2024)
> and the legacy AHS system's `AGENTS.md`. All five current group entities share these
> values today, but every number below **must** be implemented as a per-company
> `policy_configurations` entry, not hardcoded — see `conventions.md` §5.

---

## Company Group Reference

| Entity | Code | Role |
|---|---|---|
| AL HADDAD SUCCESS SDN BHD | AHS | Parent |
| AL HADDAD INTEGRATED MARKETING HQ | AIM HQ | Subsidiary |
| ZISH GLOBAL | ZISH | Subsidiary |
| THALHAH | THALHAH | Subsidiary |
| TURSENIA | TURSENIA | Subsidiary |
| ESSOFEEYA ENTERPRISE | ESSOFEEYA | Subsidiary |

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
  action.
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
| Haji/Umrah | Max 14 consecutive days paid, 2+ years service, Director approval; excess = unpaid; deducted from annual leave |
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
  tied to KPI/performance evaluation.

---

## Approval Hierarchy

Sourced from the legacy system's `AGENTS.md` — well-specified, carried forward directly,
then extended by `adr/0001` to add the HOD tier and to separate Master Admin structurally.

**Authority is read from `core_role`, never from `level`.** `level` is display-only.

### Standard routing

- Staff / Supervisor requests → require Manager **and** HR approval.
- Manager requests → require HR approval.
- No user may approve their own request.
- A higher approval stage may override a lower pending stage only when an explicit
  business rule allows it, and the override must be audited.

### HOD tier

- **An HOD is optional per department.** Some departments have an assigned HOD, some
  don't, and it varies **between departments within the same company**. Routing must
  therefore resolve the HOD chain **dynamically per department** at request time — check
  whether that department has an assigned HOD before deciding stage order. The chain
  cannot be precomputed from `core_role` alone.
- **HOD as approver — skip-stage rule:** where a department has an assigned HOD, that
  HOD may approve **directly, skipping the Manager/Supervisor stage**, for requests
  originating in that department.
- **HOD's own requests route directly to HR**, skipping Manager and Supervisor, since an
  HOD outranks both.
- Where a department has **no** assigned HOD, the standard chain above applies unchanged.

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

> ⚠ **OPEN — who approves HR / Assistant Director requests?** The legacy rule routed
> these to Master Admin, which `adr/0001` removes from the normal chain. This is
> unresolved and logged in `CLAUDE.md` §10. It blocks the Approval Workflow Engine spec;
> it does **not** block Employee Master.

---

## Discipline

- Warning letters: up to 3 issued for a given disciplinary matter, at company
  discretion.
- Employee has the right to appeal to the Director before a final decision.
- Possible actions (Director's discretion, per severity): verbal/written warning
  (up to 3x) → withhold salary increment → stop declared bonus → revoke benefits
  (e.g. 2-week leave eligibility for 2+ year staff) → immediate termination.
- No Gift Policy: any gift beyond RM100 (excluding from friends/family) must be
  declared to management.
