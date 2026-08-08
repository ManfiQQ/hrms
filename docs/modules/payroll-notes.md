# Payroll — Forward Notes (pre-spec)

- **Phase:** 2 — Operational
- **Status:** **Not a spec.** Scratch notes captured early so they aren't lost.
- **Date captured:** 2026-08-07

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

---

## Open for the Payroll spec

1. Should `employee_allowances` be append-only, or edit-in-place with audit?
2. Are `allowance_types` per-company or group-wide?
3. How do salary changes interact with `employee_status_history` — does a promotion
   write to both, and if so, in one transaction?
4. The pre-existing payroll ambiguities already logged in `business-rules.md`: the
   RM1,700 EPF contribution base, the SOCSO threshold needing re-verification, and the
   lateness penalty formula (`CLAUDE.md` §10).
5. **Who may read salary — `hr_scope`, decided upstream in the Auth & RBAC spec.** Not
   every `core_role = HR` account should see salary data: the group runs a **Payroll HR**
   (salary, documents, payslip configuration) and an **Operations HR** (leave, attendance,
   OT entry), and both hold `core_role = HR` because they are interchangeable for
   *approval routing*. For *visibility* they are not. A separate `hr_scope`
   (`PAYROLL | OPERATIONS`) distinction is a required input to the Auth & RBAC permission
   matrix (`CLAUDE.md` §11, `adr/0002` decision 5) and is **not modeled yet**. Payroll
   must not invent its own answer — read the one Auth & RBAC settles.
