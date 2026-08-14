# ADR 0011 — Supervisory Read Scope Follows the Reporting Line

- **Status:** Accepted — 2026-08-14
- **Amends:** `employee-master.spec.md` §6, §6.2 — both bound a supervisor by
  department as well as by company, in different words; the department half is
  what this ADR replaces. Each section's own wording is left standing under its
  own pointer
- **Related:** `adr/0002` decisions 4–5, `adr/0004` decisions 8 and 11,
  BR-8, BR-10, `conventions.md` §9
- **Raised by:** specifying the employee list's supervisory narrowing for §7 —
  the list has no narrowing at all, and defining one exposed that the per-record
  rule was answering a different question from the one it was written for

---

## Context

`EmployeePolicy::viewTab()` bounds a supervisor twice: same `company_id`, and
same `department_id`. Both are read from the actor's own `employees` row.

There is **no list-level equivalent.** `TenantScope` narrows `company_id` only,
and no model scope or repository exists. The employee list does not exist yet,
so this is not a live defect — but it becomes one the moment the list is
written.

Specifying the list narrowing exposed a larger problem.

**Department equality is symmetric, and supervision is not.** The rule means
*"sits in the same department"*, not *"reports to me"*. It is wrong in both
directions: a supervisor sees departmental colleagues who are not their
subordinates, including other supervisors, and does **not** see their own
subordinates who sit in a different department.

**`adr/0004` decision 8 gives exactly one reason supervisors read anything:**

> *A supervisor needs to know who reports to me and how do I reach them.*

Department equality does not answer that question.

**Worse, it is a borrowed rule.** Department equality comes from BR-10 and
`adr/0002` decision 4 — rules about **approval authority**. `adr/0002`
decision 5 draws that line already, in its own words:

> **Approval authority is explicitly separate from data visibility.**

and, in the same decision:

> **Visibility is governed by a separate permission check**, evaluated
> independently of whether the user holds an approval stage on the request.

`viewTab()` crossed that line in the other direction — not by treating an
approval stage as visibility, but by importing an **approval** rule to decide a
**visibility** question. The provenance is exact: decision 4 was amended on
2026-08-08 to make HOD approval authority strictly same-company, and BR-10
carries that amendment into this module. Both are statements about whose
requests a person may act on. Neither was ever a statement about whose record a
person may open.

Meanwhile `BR-8`'s
`direct_supervisor_id` and `manager_id` — the two columns that exist precisely
to record who reports to whom — are read by nothing.

**And the disagreement is already spreading.** Three consumers want three
different narrowings, and no document reconciles them:

| Consumer | Asks for |
|---|---|
| Employee Master (`viewTab()` today) | Department equality |
| Leave, Attendance (`adr/0004` decision 11) | *"their Supervisor/Manager"* — the reporting line |
| Approval Engine (BR-10) | `(department, company)` from the role row |

The third is genuinely different and stays different: **approval routing is not
read scope**, which is `adr/0002` decision 5 restated. The first two are the
same question answered twice, and this ADR settles it.

---

## Decision

### 1. The supervisory read bound is the reporting line, not the department

A `SUPERVISOR`, `MANAGER` or `HOD` may read an employee when that employee's
**`direct_supervisor_id` or `manager_id` points at the actor's employee record**
— replacing the `department_id` equality check in `EmployeePolicy::viewTab()`.

The company bound is **unchanged**: both must share `employees.company_id`
(`adr/0002` decision 4). Reporting line **and** same company, not either.

This aligns Employee Master with `adr/0004` decision 11's wording — *"their
Supervisor/Manager"* — so Leave and Attendance inherit one rule rather than
reconciling two.

**Approval routing is untouched.** BR-10 and `adr/0002` decision 4 continue to
resolve `(department, company) → HOD` from the role row. Read scope and approval
authority are separate axes and now say visibly different things, which is
`adr/0002` decision 5 working rather than failing.

### 2. One level, not a traversal

The check is a direct comparison — the two foreign keys pointing at the actor —
with **no walking up or down the chain.**

**A traversal was rejected.** A security boundary that depends on walking data
is a boundary that changes when somebody edits one foreign key, and a cycle in
imported data hangs the query. `WHERE direct_supervisor_id = ? OR manager_id = ?`
can be read, tested, and cannot be bent by bad data.

**Two levels are reachable without traversal**, and that is the ordinary case: a
staff row naming a supervisor in `direct_supervisor_id` and a manager in
`manager_id` is visible to both.

> **⚠ Known limit, recorded rather than discovered later.** In a genuine
> three-level department — HOD over Manager over Supervisor over staff — the
> **HOD does not automatically see the staff**, because there is no third
> column pointing at them. An HOD sees whoever names them in one of the two
> columns and nobody else.
>
> This is accepted because `adr/0002` decision 4 already establishes that a
> department may have **no HOD at all, and that is a correct configuration**.
> The role is rare by design. If a department genuinely grows three deep and the
> HOD needs full downward visibility, the answer is a traversal or a third
> column, opened by a new ADR — not by loosening this one.

### 3. `HOD` stays in the system, unassigned

`HOD` is not removed from `employee_roles.role` despite having no holder today.

Removing it would mean an enum migration, amendments to every accepted ADR that
names it, edits to the guards that assert the role set, and a new ADR to justify
it — all to delete something that costs nothing by existing. Routing already checks whether a department has
an HOD from the requester's own company and falls back to the standard chain
when it does not, without error or blockage.

An unassigned role is a path never taken, not a broken one. When the group grows
one, Master Admin grants it and the routing begins using it immediately — no
migration, no new code.

### 4. An employee with both columns empty is read by nobody below HR

No supervisor, no manager, no reader at the supervisory tier.

**This is accepted, not worked around.** The alternative — making
`direct_supervisor_id` mandatory — forces an invented answer for people who
genuinely have no supervisor, and every chain must end somewhere.

It also makes the data gap **visible**: an employee absent from every
supervisor's list is a signal that the foreign key was never filled, and a
silent default would hide exactly that.

---

## Consequences

**Accepted**

- `EmployeePolicy::viewTab()` changes behaviour, and existing tests asserting
  department equality change with it. **They are not wrong tests — they encode
  the rule this ADR replaces**, and the diff is where that replacement is
  visible.
- A supervisor loses sight of departmental colleagues who are not their
  subordinates. That is the intended narrowing.
- A supervisor gains sight of their own subordinates in other departments. That
  is the intended widening, and it is the half department equality got wrong.
- Filling `direct_supervisor_id` and `manager_id` becomes an **operational
  requirement**, not a nicety. An unfilled column is now an invisible employee.
  This belongs in the legacy import blockers (`CLAUDE.md` §10).
- The list narrowing and the per-record check must express the **same rule in
  two forms** — a query scope and a boolean. They can drift, and a guard must
  assert they agree.

> **⚠ Amended 2026-08-14, in the PR that implemented decision 1. The bullet above is
> DEFERRED, not withdrawn — and the deferral has three reasons, none of them effort.**
>
> **1. The two forms do not have the same shape, so the guard cannot compare them
> directly.** `viewTab()` answers *(actor, subject, tab)*; a list scope answers
> *(actor) → set*. A guard comparing them must pick one tab as a proxy for the whole
> method — and that proxy is an assumption nothing checks, which is the family of six
> findings `conventions.md` §9 now records. A guard that lies in a new shape is worse
> than no guard, because it is believed.
>
> **2. A scope that branches on the actor's tier has no precedent in this codebase.**
> Every existing scope — `TenantScope`, `SharedTenantScope`, `SystemTenantScope`,
> `NotRevokedScope` — applies uniformly to every account. This one must apply to the
> supervisory tier and **not** to `HR`, the administrative tier, `FULL` or `VIEW_ONLY`.
> A wrong scope filter **returns fewer rows rather than erroring**, which is the whole
> argument of `adr/0002`, and getting it wrong here hides employees from HR with nothing
> to notice.
>
> **3. A query scope with no list to call it is code with no caller.** That is exactly
> how `EmployeePolicy::transfer()` came to be missing for days with a green suite: the
> Action was never reached through an authorised path, so no test asked
> (`conventions.md` §9). Writing the scope first would repeat it deliberately.
>
> **The rule itself is unchanged and is enforced today**, per record, by
> `EmployeePolicy::viewTab()`. What is deferred is the second form and the guard over
> both — and they must be **designed with the list**, not before it. `employee-master.spec.md`
> §5.4 carries a pointer recording that it is currently silent on the narrowing.

**Not changed**

- Approval routing, in any form. BR-10, `adr/0002` decision 4, and the
  `(department, company)` resolution are untouched.
- The company bound, which still applies before the reporting-line check.
- `adr/0004` decision 8's tab matrix — which tabs, unchanged; this ADR changes
  which employees.
