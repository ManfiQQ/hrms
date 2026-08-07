# ADR 0002 — Shared Branch & Department Model

- **Status:** Accepted
- **Date:** 2026-08-07
- **Extends:** `adr/0001` decision 3 (HOD optional per department)
- **Affects:** `branches`, `departments`, `employees`, `approval_requests`,
  `conventions.md` §2–3, Employee Master spec, Org Structure spec

---

## Context

The initial schema draft gave `branches.company_id` and `departments.company_id` as
mandatory, on the assumption that every branch and department belongs to exactly one
company. That assumption is wrong for this group.

**Confirmed by the client:** branches and departments routinely span companies, and this
is a **common operating pattern, not an edge case**:

- **HQ Marketing** is a shared department staffed by people employed by different group
  companies.
- **Logistics** is a shared branch where THALHAH and TURSENIA staff work side by side.

Meanwhile some branches and departments genuinely *are* company-dedicated — THALHAH's
factory, for instance.

So the model must express both, and a mandatory `company_id` cannot. Forcing one would
push the group into one of three bad outcomes: duplicating the same real-world department
once per company (drifting names, split reporting, the exact fragmentation `CLAUDE.md` §9
warns about), assigning shared departments to an arbitrary "owner" company (a lie that
every query then has to work around), or misrecording employees' employing company to
match their department (corrupting payroll and statutory data to satisfy a schema
constraint).

The tension this ADR must resolve carefully: `CLAUDE.md` Principle #4 and
`conventions.md` §2 require tenant scoping on business tables, and a nullable
`company_id` looks, at a glance, exactly like the multi-tenancy violation those rules
exist to prevent.

---

## Decision

### 1. `branches.company_id` and `departments.company_id` become nullable

| Value | Meaning |
|---|---|
| `NULL` | **Shared / group-level.** Available across all companies — e.g. HQ, Marketing, Logistics |
| Set | **Company-dedicated.** Belongs to that one company — e.g. THALHAH's factory |

The column still exists on both tables from the migration that creates them — this is
**not** a retrofit, and Principle #4 is not being relaxed. What changes is that
`NULL` becomes a meaningful, documented value rather than missing data.

### 2. An employee's `company_id` stays mandatory and independent

`employees.company_id` is **NOT NULL** and remains the employee's **payroll and legal
employer**. It determines which company's leave entitlement, policy configuration,
payroll rules, and statutory treatment apply to them.

`employees.branch_id` and `employees.department_id` are **independent of it and are not
required to match**. A TURSENIA employee working in the shared Logistics branch has
`company_id = TURSENIA` and a `branch_id` pointing at a branch whose own `company_id` is
`NULL`. That is a correct record, not an inconsistency, and **no validation may reject
it**.

### 3. The tenant boundary moves to where the sensitive data actually is

This is the core of the decision, and the reason it is not a multi-tenancy violation:

**Sensitive employee data stays strictly scoped to `employees.company_id`** — leave
balances and requests, payroll, salary, statutory contributions, documents, family
records, disciplinary records. None of that becomes cross-visible because two people
share a department. The tenant scope on those tables is unchanged and unrelaxed.

**Only the org-structure reference tables — `branches` and `departments` — are shared.**
They hold no personal or financial data. A department row is a name and a place in a
hierarchy; there is nothing in it to leak.

The rule to carry forward: **shared structure, scoped data.** Where a person works is
organizational; who employs them is tenancy.

**Query scope for the shared tables** must therefore be
`company_id IS NULL OR company_id = :current_company` — the global scope on `branches`
and `departments` must **include** the shared rows, not filter them out. A naïve
`where company_id = :current` silently hides every shared branch and department, which
would present as "the Logistics branch has disappeared" rather than as an error.

### 4. HOD authority is department-scoped, not company-scoped

This closes the dynamic-routing question left open by `adr/0001` decision 3.

**An HOD's approval authority follows their department, and covers every employee in
that department regardless of which company employs them.** An HOD of shared Logistics
approves for both THALHAH and TURSENIA staff in that department. Authority is not
limited to the HOD's own payroll company.

**Consequence — the approval engine must cross the tenant boundary, deliberately and
narrowly.** An HOD acting on a request from an employee with a different `company_id`
than their own is **correct behaviour and must be permitted**, not treated as a scope
violation. This is a specific, audited exception:

- It applies **only** to approval actions within the approver's own department.
- It does **not** grant read access to that employee's leave history, payroll, salary,
  documents, or any other company-scoped data — only to the request under approval and
  the context needed to decide it.
- Every cross-company approval is written to `audit_logs`.

Getting this wrong in either direction is a real failure: too strict and shared
departments cannot approve anything, too loose and the tenant boundary is gone.

---

## Consequences

**Positive**

- The schema now matches how the group actually operates, rather than forcing the group
  to reshape itself around the schema.
- No duplicate department rows per company, so no naming drift — directly addresses the
  `CLAUDE.md` §9 lesson about the same entity spelled differently across files.
- Employees' employing company stays truthful, which keeps payroll and statutory data
  correct.
- HOD routing has a clear, decidable rule.

**Costs and constraints accepted**

- **`NULL` is now load-bearing.** Anyone reading `branches.company_id IS NULL` must know
  it means "shared," not "unknown" or "not yet set." This is documented in `schema.md`
  and must not be inferred.
- **Every query against `branches` / `departments` needs the two-branch scope.** A
  forgotten `OR company_id IS NULL` produces a silent wrong answer — missing rows, not an
  error. This is the single most likely bug this decision introduces, and the global
  scope exists to make the correct behaviour the default.
- **The approval engine gains a cross-tenant path.** Narrow and audited (decision 4), but
  it exists, and it must be tested explicitly in both directions: an HOD *can* approve
  for another company's employee in their department, and *cannot* read that employee's
  payroll or leave history.
- **`conventions.md` §2–3 needed a documented carve-out.** Without it, the conventions
  file would read as forbidding exactly what this ADR requires. Amended in the same
  commit rather than left to drift.

**Explicitly not changed**

- `employees.company_id` remains mandatory. Principle #4 stands.
- Tenant scope on leave, payroll, documents, and all other employee-data tables is
  unchanged.
- `company_id` is still present from table creation on every table that has it — nothing
  here is retrofitted.

---

## References

- `adr/0001` decision 3 — HOD optional per department (extended here)
- `docs/schema.md` — `branches`, `departments`, `employees`, `approval_requests`
- `docs/conventions.md` §2–3 — multi-tenancy carve-out for org-structure tables
- `docs/modules/employee-master.spec.md` — BR-8, BR-10
- `CLAUDE.md` Principle #4, §9
