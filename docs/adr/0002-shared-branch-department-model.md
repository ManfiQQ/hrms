# ADR 0002 — Shared Branch & Department Model

- **Status:** Accepted — **decision 4 amended 2026-08-08** (see the amendment note there);
  **decision 5 partly withdrawn 2026-08-10** (see the bullet below)
- **Date:** 2026-08-07
- **Extends:** `adr/0001` decision 3 (HOD optional per department) and decision 6
  (HR ↔ Assistant Director peer approval — scoped across companies here, in decision 5)
- **Superseded in part by:** `adr/0003`. **Only the `hr_scope` portion of decision 5 is
  withdrawn** — the `PAYROLL | OPERATIONS` split does not exist, and salary visibility is
  the `ACCOUNT` role instead (`adr/0003` decision 5). **The rest of decision 5 stands
  unchanged**, including its central rule that cross-company approval authority confers
  **no** data visibility, and that the general visibility check belongs to the Auth & RBAC
  spec. Decisions 1–4 are untouched; where they name `core_role`, read
  `employee_roles.role` (`adr/0003` decision 1)
- **Affects:** `branches`, `departments`, `employees`, `approval_requests`,
  `conventions.md` §2–3, Employee Master spec, Org Structure spec, Auth & RBAC spec
  (not yet written — decision 5 is a required input to it)

---

## Context

The initial schema draft gave `branches.company_id` and `departments.company_id` as
mandatory, on the assumption that every branch and department belongs to exactly one
company. That assumption is wrong for this group.

**Confirmed by the client:** branches and departments routinely span companies, and this
is a **common operating pattern, not an edge case**:

- **HQ Marketing** is a shared department staffed by people employed by different group
  companies.
- **Logistics** is a shared branch where AIM, TURSENIA and ES SOFEEYA staff work side by
  side.

Meanwhile some branches and departments genuinely *are* company-dedicated — AIM's
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
| Set | **Company-dedicated.** Belongs to that one company — e.g. AIM's factory |

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

### 4. HOD approval authority is strictly same-company

This closes the dynamic-routing question left open by `adr/0001` decision 3.

**An HOD approves only for employees who share their own `employees.company_id`** — and
this holds **even inside a shared department or a shared branch**. Sharing a department
with someone does not place that person under the HOD's approval authority; being
employed by the same company does. **No cross-company HOD authority exists anywhere in
the system.**

Concretely: shared Logistics contains AIM, TURSENIA and ES SOFEEYA staff. An AIM HOD of
that branch approves for the AIM staff in it, and **not** for the TURSENIA or ES SOFEEYA
staff, who are not within any authority of theirs.

> **⚠ Amendment — 2026-08-08.** This decision originally read the opposite way: "an HOD's
> approval authority follows their department … regardless of which company employs
> them," and it granted the approval engine a narrow cross-tenant path for HOD approvals.
> **That is now reversed and must not be reinstated.** It was inferred from decision 1's
> shared-structure model rather than confirmed as a business rule, and the client has
> since confirmed HOD authority is same-company only. Decision 1's carve-out is about
> *where org structure is shared*; it never implied *whose staff an HOD may approve for*.
> Every document describing the old rule was corrected in the same commit as this
> amendment.

**Consequences for routing:**

- **HOD assignment resolves per (department, company) pair, not per department alone.** A
  shared department may legitimately have **more than one HOD** — up to one per company
  represented in it — and that is a correct configuration, not a duplicate to clean up.
- **Fallback when the requester's company has no HOD in their department.** This now
  includes the case where the department *does* have an HOD, but one employed by a
  different company. In both cases the HOD stage simply does not apply to that requester
  and routing falls back to the standard Manager/Supervisor → HR chain, unchanged. The
  request is **not** blocked. This is the same fallback `adr/0001` decision 3 already
  defines for a department with no HOD; what changes is only that the check is per
  (department, company), not per department.
- **The approval engine needs no cross-tenant path for HOD.**
  `approver.company_id === requester.company_id` holds for **every** HOD approval and can
  be asserted as an invariant rather than guarded as an exception.

### 5. HR and Assistant Director are the only cross-company approvers — and approval is not visibility

**`HR` and `ASSISTANT_DIRECTOR` are the only two `core_role` values whose approval
authority is not restricted to their own `employees.company_id`.** `STAFF`, `SUPERVISOR`,
`MANAGER` and `HOD` all approve strictly within their own company (decision 4). The
approval engine's only cross-tenant path therefore runs through HR and Assistant
Director, and it is audited: every cross-company approval is written to `audit_logs`.

**Approval authority is explicitly separate from data visibility.** An HR or Assistant
Director approving a cross-company request gains **the request under approval and the
context needed to decide it — nothing more**. It does **not** automatically confer read
access to that employee's sensitive data: salary, payroll, personal documents, family
records, disciplinary history, or full leave history.

**Visibility is governed by a separate permission check**, evaluated independently of
whether the user holds an approval stage on the request. That check is **not yet
defined** — it belongs to `docs/modules/auth-rbac.spec.md` and must be written there
before any permission code exists. Until then, no code may treat "is an approver on this
request" as an answer to "may read this employee's data."

Getting this wrong in either direction is a real failure: too strict and cross-company
requests cannot be approved at all, too loose and the tenant boundary on sensitive data
is gone.

> **⚠ `hr_scope` withdrawn — 2026-08-10.** This decision originally carried a subsection
> titled "Required input for the Auth & RBAC spec — HR is not one functional scope,"
> requiring an `hr_scope` field with values `PAYROLL | OPERATIONS` to separate a **Payroll
> HR** (salary, documents, payslip configuration) from an **Operations HR** (leave,
> attendance, OT entry) for data visibility. **That subsection is withdrawn and must not
> be reinstated.** The client confirmed there is no such split: **only the `ACCOUNT` role
> sees salary, and no `HR` does**, however many HR staff exist. Salary visibility is a
> role, not an HR sub-scope — `adr/0003` decision 5. The rest of this decision stands: the
> general visibility check is still undefined and still belongs to the Auth & RBAC spec.

---

## Consequences

**Positive**

- The schema now matches how the group actually operates, rather than forcing the group
  to reshape itself around the schema.
- No duplicate department rows per company, so no naming drift — directly addresses the
  `CLAUDE.md` §9 lesson about the same entity spelled differently across files.
- Employees' employing company stays truthful, which keeps payroll and statutory data
  correct.
- HOD routing has a clear, decidable rule — and after the decision 4 amendment it is the
  *simpler* of the two candidate rules: same company, no exception, no cross-tenant path.

**Costs and constraints accepted**

- **`NULL` is now load-bearing.** Anyone reading `branches.company_id IS NULL` must know
  it means "shared," not "unknown" or "not yet set." This is documented in `schema.md`
  and must not be inferred.
- **Every query against `branches` / `departments` needs the two-branch scope.** A
  forgotten `OR company_id IS NULL` produces a silent wrong answer — missing rows, not an
  error. This is the single most likely bug this decision introduces, and the global
  scope exists to make the correct behaviour the default.
- **A shared department may need one HOD per company represented in it** (decision 4).
  That is more configuration for HR to maintain than a single department-wide HOD would
  be, and a company with no HOD in a shared department silently gets the standard
  Manager/Supervisor chain instead. Accepted: the alternative gives an HOD authority over
  staff of a company that does not employ them.
- **The approval engine still has one cross-tenant path — but only through HR and
  Assistant Director** (decision 5), never through HOD. It must be tested in both
  directions: an HR/Assistant Director *can* approve a request from another company's
  employee, and that *does not* let them read that employee's salary, documents, or other
  sensitive data. Testing only the permission turns a narrow exception into an open door.
- **Approval-vs-visibility is now an explicit, unresolved dependency.** The separate
  visibility permission check (decision 5) does not exist yet and is a blocking input to
  `docs/modules/auth-rbac.spec.md`. Its **salary portion is since answered** — the
  `ACCOUNT` role, `adr/0003` decision 5 — and the `hr_scope` distinction it was said to
  need is **withdrawn**.
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
- `adr/0001` decision 6 — HR ↔ Assistant Director peer approval (scoped here by
  decision 5)
- `docs/schema.md` — `branches`, `departments`, `employees`, `approval_requests`
- `docs/conventions.md` §2–3 — multi-tenancy carve-out for org-structure tables
- `docs/modules/employee-master.spec.md` — BR-8, BR-10
- `CLAUDE.md` Principle #4, §9, §11
- `adr/0003` decision 5 — withdraws the `hr_scope` subsection of decision 5 here
