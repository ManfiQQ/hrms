# ADR 0005 — Tenant Scope Implementation

- **Status:** Draft — awaiting approval
- **Date:** 2026-08-11
- **Implements:** `adr/0002` decision 3 (shared org structure query scope), `adr/0003`
  decision 7 (event tables release the scope), `adr/0004` decision 1 (read scope derives
  from the employer's hierarchy position), `adr/0004` decision 2 (`system_access = FULL`)
- **Closes:** the gap recorded in PR #9 — the rule
  `company_id IS NULL OR company_id = :current_company` existed in `schema.md`, in
  `conventions.md` §2, and in two model docblocks, and in **no code**
- **Does not decide:** `ReadScopeResolver`'s internals — `auth-rbac.spec.md` §5.4 owns
  those; this ADR decides only that the scopes consume it
- **Affects:** `App\Models\Scopes` (new classes), `Branch`, `Department`, `Employee`,
  `EmployeeRole`, every Phase 2 leave / payroll / attendance table,
  `auth-rbac.spec.md` §5.3, `conventions.md` §2, `schema.md`, `tests/`

---

## Context

Three documents state the tenant rule. Nothing enforces it.

`conventions.md` §2 requires every business table to carry `company_id` and to be
tenant-scoped by default. `adr/0002` decision 3 requires the two shared org-structure tables
to scope as `company_id IS NULL OR company_id = :current_company`. `adr/0003` decision 7
requires event tables to release the scope when read through an employee. `adr/0004`
decision 1 replaced "the current company" with a **derived read scope** that may span the
whole group.

After PR #9 the models exist and carry those rules **as docblock comments**. An ordinary
query on `Branch` today returns every row in the table, for every account.

### The carve-outs fail silently, which is why the shape of the implementation matters

Two of the three behaviours fail by returning **fewer rows, with no error**:

- A shared branch or department filtered out by a plain equality check presents as
  "the Logistics branch disappeared", not as a fault (`adr/0002`).
- An employee's pre-transfer history filtered out by the ordinary scope presents as a
  history tab that **begins on the transfer date** (`adr/0003` decision 7).

Neither raises an exception, neither fails a happy-path test, and both look like data
problems rather than code problems. `adr/0002` exists in large part because this failure
mode is the one the legacy system kept producing.

### Phase 2 is the real audience

The tables that will get this wrong have not been written yet. Leave, payroll and attendance
will add many event tables, each needing the right scope chosen at creation. The decision
below is therefore judged less on how it reads today — with five models — than on what it
does to a developer adding the twentieth table eighteen months from now.

---

## Decision

### 1. Two scope classes, never one class with an internal condition

**`TenantScope` and `SharedTenantScope` are separate classes.** A model declares which one it
uses by applying it. There is no flag, property, or configuration array that one scope reads
to decide how to behave.

```
App\Models\Scopes\TenantScope         → company_id IN (:read_scope)
App\Models\Scopes\SharedTenantScope   → company_id IS NULL OR company_id IN (:read_scope)
```

**Rejected alternative — one class reading a model property.** A single `TenantScope` that
checks something like `$model->sharedAcrossCompanies` was rejected, and the reason is not
style. **The shared case would default to wrong.** A model that omits the property gets the
narrowing behaviour, and a model author who has not yet learned that shared rows exist is
exactly the author who omits it.

That default matters because of *how* it fails. The narrowing behaviour applied to a shared
table does not error — it returns fewer rows. The developer sees a working page with a
missing branch, and nothing anywhere says why. This is the precise failure mode `adr/0002`
was written to prevent, and a design whose default produces it has inverted the safety.

With two classes the same mistake is a **missing scope**, which decision 6 catches at test
time rather than in production six months later.

### 2. `TenantScope` — narrows to the account's read scope

The default for every business table. It restricts the query to the set of `company_id`
values the account may read, obtained from `ReadScopeResolver` (`auth-rbac.spec.md` §5.4).

**It resolves against read scope, not against a single "current company".** That set is
derived from where the account's employer sits in `companies.parent_company_id` — employed
by AHS reads the whole group, employed by a subsidiary reads that subsidiary only
(`adr/0004` decision 1). An implementation comparing against one company id would be correct
only for subsidiary staff and would hide the entire group from HR.

**Scope narrows; it does not grant.** `company_id` bounds the visibility a role has already
granted, and never confers any (`adr/0004` decision 1). A row surviving this scope is not
thereby readable — the module's own permission check still applies.

### 3. `SharedTenantScope` — includes `company_id IS NULL`

Applied to `branches` and `departments`, and to nothing else without an ADR.

`NULL` on these two tables is a **meaningful value meaning shared across all companies**, not
missing data (`adr/0002` decision 1). The scope must therefore resolve to
`company_id IS NULL OR company_id IN (:read_scope)`.

**This is not a relaxation of Principle #4.** These two tables are org-structure references
holding no personal or financial data — a department row is a name and a place in a
hierarchy. Sensitive employee data stays scoped to `employees.company_id`, which is NOT NULL.
The rule remains **shared structure, scoped data**.

### 4. Event tables release the scope through an employee relationship

An event table read **through an employee relationship** releases the tenant scope entirely.
Queried **directly**, for reporting, it applies in full (`adr/0003` decision 7).

`company_id` on an event table is a **frozen historical fact** — the employer at the moment
the event happened, never cascaded on transfer. After a transfer, every pre-transfer row
still carries the old `company_id`, so the ordinary scope excludes it and the employee's
history appears to begin on the transfer date.

**Permission has already been decided one level up: if the user may read this employee, they
may read this employee's history.** Re-filtering row by row adds no security whatsoever and
breaks the record.

**Both directions must be tested**, as `conventions.md` §2 requires: history stays visible
after a transfer, *and* a direct reporting query stays scoped, so "how many promotions did
TURSENIA make this year" remains TURSENIA's.

### 5. `system_access = FULL` bypasses the scope, explicitly and audited

A Master Admin account bypasses tenant scope entirely (`adr/0004` decision 2).

**The bypass is explicit and never ambient.** A request runs in Master Admin context because
something said so, and the bypass is written to `audit_logs` (`auth-rbac.spec.md` BR-A14).
An implementation where the scope simply returns early for `FULL` accounts — with no record
that it did — is not this decision, because it makes the most powerful read in the system
the one that leaves no trace.

**`VIEW_ONLY` does not bypass.** It reads group-wide because its read scope *is* the whole
group, through the ordinary path in decision 2. Only `FULL` skips the mechanism.

### 6. An architecture test guards the choice

A test asserts that **every Eloquent model backed by a table carrying a `company_id` column
declares its scope explicitly** — `TenantScope`, `SharedTenantScope`, or a documented opt-out.
A model that declares none fails the suite.

**Rejected alternative — two classes, no guard test.** Two classes alone catch the wrong
*choice*: a shared table given `TenantScope` loses its shared rows, and a reviewer comparing
the class name against `schema.md` will see it. They do **not** catch the omission — a new
model with no scope at all, which reads every company's rows and looks entirely normal doing
it.

Omission is the more likely error, and it gets likelier over time. The tables most at risk
have not been written: Phase 2's leave, payroll and attendance tables will be created by
someone who has this ADR available but no reason to open it, because nothing in the act of
writing `Schema::create` prompts them to. A review catches what a reviewer thinks to look
for; the test catches what nobody thought about at all.

**Company-reference tables must opt out explicitly, not by silence.** On `employee_roles` and
`employee_job_functions`, `company_id` is a real reference to *which company the row is
about* — not a tenant marker — so neither scope belongs there (`adr/0003` decision 7). The
guard test must require that exemption to be **declared on the model**, so that "this table
is deliberately unscoped" and "someone forgot" are distinguishable. That distinction is the
entire value of the test.

---

## Consequences

**Positive**

- The rule moves from three documents into one enforced mechanism, and the most likely way
  to get it wrong — omitting it — becomes a failing test rather than a silent data leak.
- Choosing the wrong scope is visible in the model as a class name, checkable against
  `schema.md` without reading any query.
- Phase 2 inherits the decision. A table created eighteen months from now either declares a
  scope or fails the suite on the first run.
- `NULL`-means-shared stops depending on every query author remembering it.

**Costs and constraints accepted**

- **Two classes to keep in step.** A change to read-scope resolution touches both. Accepted:
  the duplication is a few lines, and merging them reintroduces the defaulting problem
  decision 1 rejects.
- **The guard test needs maintenance.** It must know which models are deliberately unscoped,
  so adding a company-reference table means updating a declaration. That cost is the point —
  it forces the category to be decided rather than defaulted.
- **The event-table release is a genuine hole if misapplied.** It is correct only because
  permission was decided at the employee level; applied to a table where it was not, it
  bypasses tenancy outright. Which tables are event tables is decided in the module spec,
  before the migration (`conventions.md` §2).
- **`ReadScopeResolver` becomes load-bearing for every read in the system.** A bug there is a
  bug everywhere at once. It is cached per request, never per session, so a transfer or a
  hierarchy correction takes effect on the next request.
- **Scope depends on the company hierarchy being seeded correctly.** A mis-parented
  subsidiary grants its staff group-wide reads, and no scope can detect that — the hierarchy
  is input, not logic. It must be covered by its own test (`adr/0004` decision 1).

**Explicitly not changed**

- `conventions.md` §2's carve-outs stand as written; this ADR implements them rather than
  amending them.
- `employees.company_id` remains NOT NULL. Principle #4 stands.
- Approval authority still confers no data visibility, and scope still grants nothing —
  it bounds (`adr/0002` decision 5, `adr/0004` decision 1).
- `NotRevokedScope` on `employee_roles` is unaffected and unrelated: it filters revoked
  authority, not tenancy.

---

## Still open

- **Whether `TenantScope` applies to `users`.** An account belongs to no company —
  `users` has no `company_id` at all, and Master Admin and Director belong to no company by
  design. Nothing to scope today, but account listing screens will need *some* rule, and it
  is not this ADR's.
- **What the guard test does about tables with no model.** Pivot-only tables are not a
  problem yet; `sessions` and `cache` are not business tables. Worth deciding before a
  model-less business table exists.

---

## References

- `adr/0002` decision 1 — nullable `company_id` on `branches` / `departments`
- `adr/0002` decision 3 — the shared query scope this implements
- `adr/0002` decision 5 — approval is not visibility, unchanged
- `adr/0003` decision 1 — `employee_roles` is a pivot; `NotRevokedScope` is separate
- `adr/0003` decision 7 — three cascade categories; event tables release the scope
- `adr/0004` decision 1 — read scope derives from the employer's hierarchy position
- `adr/0004` decision 2 — `system_access`; `FULL` bypasses tenant scope
- `docs/modules/auth-rbac.spec.md` §5.3 — `TenantScope`, §5.4 — `ReadScopeResolver`,
  BR-A14 — the bypass is explicit and audited
- `docs/conventions.md` §2 — multi-tenancy rule and both carve-outs
- `docs/schema.md` — `branches`, `departments`, `employees`, `employee_roles`
- `CLAUDE.md` Principle #4, §9
