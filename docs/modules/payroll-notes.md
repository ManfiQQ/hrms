# Payroll — Forward Notes (pre-spec)

- **Phase:** 2 — Operational
- **Status:** **Not a spec.** Scratch notes captured early so they aren't lost.
- **Date captured:** 2026-08-07 — extended 2026-08-11 with the HR / Account split and the
  salary-gate correction from `adr/0004`

> These are structural facts learned while speccing Employee Master (Phase 1) that will
> shape the Payroll schema. Recorded now because they were confirmed now — waiting until
> Payroll is speced would mean rediscovering them, or worse, not rediscovering them and
> shipping the wrong shape.
>
> **This file does not authorize any code** (`CLAUDE.md` Principle #1). Payroll needs a
> real spec in `/docs/modules/payroll.spec.md` before a migration is written. These notes
> are input to that spec, not a substitute for it.

---

## Why salary is not in Employee Master

Salary fields were **deliberately excluded** from the Phase 1 `employees` table and
deferred to Phase 2 Payroll. Confirmed with the client. The two notes below are the
reason a single `basic_salary` column on `employees` would have been the wrong call.

---

## 1. Basic salary is not static — it needs a history, not a field

**Fact:** basic salary increases over an employee's tenure. HR updates it manually when a
raise happens.

**Implication:** a single overwritable `basic_salary` column **loses the raise history**.
The moment it is overwritten, the system can no longer answer "what was this person paid
in March," which is needed for back-pay, payroll re-runs, disputes, and statutory audit.

**Expected shape:** a salary history / ledger table — **a new row per change**, never an
update in place. Same pattern as `employee_status_history` in `schema.md`:

```
employee_salary_history
  id, company_id, employee_id, amount, effective_date, reason, changed_by, created_at
```

Append-only, like `employee_status_history` — a correction is a new row, not an edit.
"Current salary" is a query (latest row by `effective_date`), not a stored field.

This is the same lesson `schema.md` already records for employment status: the legacy
system's flat-field design could not answer "when did this change," and that was a real
defect, not a nice-to-have.

---

## 2. Allowances are not a fixed set — they need tables, not columns

**Fact:** allowances (*elaun*) are **not** a fixed list. HR creates new allowance types
manually, with custom values per type.

**Implication:** fixed columns per allowance type (`allowance_transport`,
`allowance_phone`, …) are wrong. Every new allowance HR invents would require a
migration, which is precisely the "repair migration" anti-pattern `CLAUDE.md` §9 records
from the legacy system.

**Expected shape:** two tables — HR-managed types, plus per-employee values:

```
allowance_types
  id, company_id, name, description, is_active, timestamps, soft deletes
  -- HR-managed; new types created through the UI, no migration required

employee_allowances
  id, company_id, employee_id, allowance_type_id, amount, effective_date,
  created_by, updated_by, timestamps, soft deletes
```

Whether `employee_allowances` should also be append-only (like salary) or
edit-in-place is **an open question for the Payroll spec** — it depends on whether
allowance history matters for back-pay the way salary history does. Likely yes, but not
confirmed, so it is not decided here.

Note `allowance_types` carries `company_id`: allowance types are likely per-company
rather than group-wide, since `conventions.md` §5 already treats HR policy numbers as
per-company config. Worth confirming with HR when Payroll is speced — if types turn out
to be group-wide, this becomes a shared reference table like `branches` (`adr/0002`).

⚠ **The shape above places `amount` on `employee_allowances`, and that is not yet
decided** — see §3 and open question 6. It is written that way here because per-employee
values were what §2's original fact established, not because the location is settled.
**Who may set the rate is settled regardless: only `ACCOUNT`.**

---

## 3. HR submits quantities; Account produces money

**Fact (`adr/0004`, § Confirmed but not yet specced):** the payroll split between HR and
`ACCOUNT` is not a matter of screen layout — it is the mechanism that keeps salary out of
HR's reach while still letting HR run payroll operations. Recorded here because a Payroll
schema that ignores it cannot enforce it afterwards.

**HR enters or confirms quantities and counts, never currency:**

```
overtime hours · late instances · hours banked and carried forward
Saturday hours · leave and half-days taken · which allowances apply
```

**HR does not type a currency value at any point in payroll.** `ACCOUNT` turns those
quantities into money.

**Consequences the Payroll schema and forms must carry:**

- **Statutory formulas (EPF, SOCSO, EIS) are entered by `ACCOUNT`** and computed by the
  system. **HR neither enters nor sees them** — a statutory figure exposed to HR would
  allow basic salary to be **derived** from it, which defeats the salary restriction
  without ever showing a salary field. This is the subtle one: the leak is arithmetic, not
  access.
- **Allowance *names* may be created by `HR`, Master Admin, or `ACCOUNT`. Only `ACCOUNT`
  sets the *rate*.** The HR payroll form shows allowance names **without amounts**. Note
  this widens §2's "HR-managed types" — creation is shared, pricing is not.
- **The payroll form pre-fills from attendance data.** HR selects an employee, hours
  populate from the imported attendance record, and HR ticks which allowances apply.
- **Payroll cannot proceed on incomplete attendance data.** The system **blocks and flags**
  — to HR *and* to the employee concerned, who is expected to verify their own record.
  This is why `adr/0004` decision 7 provisions an account for **every** employee: without
  one they cannot verify, and payroll stalls. Employee self-service is a module of its own
  and is not yet designed (`CLAUDE.md` §10).

**Approval Engine note, repeated because payroll is its third instance:** *manager endorses
or reports, HR decides.* It already holds for leave, for termination, and for disciplinary
warnings (`adr/0003`, `adr/0004` decision 10).

---

## Open for the Payroll spec

1. Should `employee_allowances` be append-only, or edit-in-place with audit?
2. Are `allowance_types` per-company or group-wide?
3. How do salary changes interact with `employee_status_history` — does a promotion
   write to both, and if so, in one transaction?
4. The pre-existing payroll ambiguities already logged in `business-rules.md`: the
   RM1,700 EPF contribution base, the SOCSO threshold needing re-verification, and the
   lateness penalty formula (`CLAUDE.md` §10).
5. **Who may read salary — RESOLVED: the `ACCOUNT` role, and nobody else.** Not every
   account holding the `HR` role sees salary data — **none of them does**. Only an employee
   holding the `ACCOUNT` role may read salary, at the company where they hold that role,
   however many HR staff exist (`adr/0003` decision 5). Enforcement is structural:
   `ACCOUNT` is a hardcoded restricted role that only Master Admin may grant (`adr/0003`
   decision 3), so it cannot be granted from inside HR.

   This item previously deferred to an `hr_scope` (`PAYROLL | OPERATIONS`) split of
   **Payroll HR** from **Operations HR**, to be settled by the Auth & RBAC spec. That
   split does not exist and the field is **withdrawn** — do not model it, and do not wait
   on Auth & RBAC for this question. Payroll still must not invent its own answer: gate
   salary reads on the `ACCOUNT` role.

   ⚠ **The gate has two more inputs than "the `ACCOUNT` role", and a check written from
   the paragraph above alone will lock out the Director.** `system_access = FULL` (Master
   Admin) and `VIEW_ONLY` also read salary — not as an exception, but because they hold
   **no `employee_roles` rows at all**, so a role-only check can never pass for them
   (`adr/0004` decision 3). The full rule: `ACCOUNT` within its scope, `VIEW_ONLY`
   group-wide read-only, `FULL` unrestricted. **`HR` never, at any scope** — that is the
   line the rule exists to draw, and it is unchanged.

6. **Where does an allowance rate live — `allowance_types` (one rate for everyone) or
   `employee_allowances` (per person)?** Undecided (`adr/0004`). **Access is unaffected
   either way** — only `ACCOUNT` sets it, wherever it sits (§3) — so this is a data-shape
   question, not a permission one. §2's draft shape assumes per-person; do not read that as
   the answer.

7. **How does the form block on incomplete attendance (§3)?** Which conditions count as
   incomplete, and what the employee sees when they are flagged, depends on the Attendance
   import's own statuses (`attendance_import_rows.status` in `schema.md`) and on the
   unbuilt self-service module. Payroll must not invent its own definition of "complete."
