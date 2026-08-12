# ADR 0005 — Tenant Scope Implementation

- **Status:** **Accepted** — 2026-08-11. **Fully implemented 2026-08-12**: decision 5's
  audit write, knowingly deferred in PR #10, now lands — see the note there.
  **Decision 6 amended 2026-08-12** — a third scope class, `SystemTenantScope`, which the
  guard test must recognise; see the amendment note there.
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

**Nor does `FULL` bypass merely by being `FULL`.** A Master Admin account outside the
context is scoped by the ordinary mechanism like anyone else. It happens to see every
company, because its read scope resolves to every company — but it is *scoped*, not
bypassed. The two come apart the moment read scope cannot express something: rows belonging
to a soft-deleted company fall outside the resolved set and disappear for a `FULL` account,
and reappear only inside the context. **That difference is what "explicit, not ambient"
means in practice**, and it is testable today.

> **✅ Complete since 2026-08-12. This note previously recorded a deliberate half.**
>
> For the record of how it went: `audit_logs` was **deliberately not created by the
> tenant-scope work**. It had no spec of its own, and it accepts writes from **every** module
> — auth, approvals, attendance corrections, Director overrides, role grants — so its column
> shape was not that branch's decision to make. Settling it inside a tenant-scope PR would
> have been exactly the code-before-spec pattern Principle #1 exists to prevent. The seam was
> left in place instead: `run()` took and held the reason, and went nowhere with it.
>
> `docs/modules/audit-trail.spec.md` settled the shape, the migration landed, and
> `MasterAdminContext` now **writes the bypass to `audit_logs`** — actor, reason, and a
> `tenant_scope: scoped → bypassed` row against the acting account. **Both halves of this
> decision now hold:** "explicit, never ambient" *and* "audited".
>
> Two consequences worth knowing, both decided by the audit requirement rather than added to
> it. The write happens **before** the callback and in its own transaction, because the
> bypass happened whether or not the work inside it succeeded — and the failed one is the
> more interesting to review. And **an authenticated account is now required** to enter the
> context: a bypass nobody can be attributed to is the ambient bypass this decision rejects,
> and `audit_logs` has no nullable subject to record one against. Console contexts lose
> nothing, since the scopes already run unscoped with no user.

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

> **⚠ Amended 2026-08-12 — a third scope class exists, and this test must recognise it.**
>
> The decision above stands as written. What changes is only the **set of valid
> declarations**: `TenantScope`, `SharedTenantScope`, **`SystemTenantScope`**, or a
> documented opt-out. A model declaring none still fails the suite, which is the rule this
> decision exists to enforce and is unchanged.
>
> **`App\Models\Scopes\SystemTenantScope`** is required by `audit_logs`
> (`docs/modules/audit-trail.spec.md` §11), whose `company_id` is **nullable**, where `NULL`
> means *a system-level event* — an audited action whose subject belongs to no company, such
> as a Master Admin changing another Master Admin's `system_access`, or a bypass entered
> through `MasterAdminContext` under decision 5 above.
>
> ```
> company_id IN (:read_scope)
> OR (company_id IS NULL AND the account has system_access = FULL)
> ```
>
> **Both existing classes are wrong for that table, in opposite directions.** `TenantScope`
> hides the `NULL` rows from everyone including Master Admin — whose own actions they mostly
> are, so the scope would conceal precisely the rows that exist to hold the most powerful
> account to account. `SharedTenantScope` exposes them to everyone in any scope, so a
> subsidiary-employed `HR` would read every group-level administrative action.
>
> Note that `NULL` does **not** mean the same thing on the two tables. On `branches` and
> `departments` it means *available to all companies* (decision 3); on `audit_logs` it means
> *attributable to no company*. Reusing `SharedTenantScope` because both columns are nullable
> would be matching the column type and ignoring the value's meaning.
>
> **The `FULL` condition is named directly and does not route through read scope.** This is
> the case decision 5 above already anticipates — *"the two come apart the moment read scope
> cannot express something."* A `FULL` account's read scope resolves to every **company**,
> and a `NULL` row belongs to none, so no set of company ids can contain it. Inside
> `MasterAdminContext` the scope lifts entirely, as it does for every model.
>
> **The class applies to `audit_logs` and to nothing else without an ADR** — the same
> restriction decision 3 places on `SharedTenantScope`. `security_events` is **not** a user
> of it: that table carries **no** scope at all, because a security event may be written
> before there is an authenticated account whose `system_access` the scope could read, and
> its model keeps the documented opt-out this decision requires.
>
> Decision 1's reasoning is untouched and is what forced a third class rather than a flag on
> an existing one: a single scope reading a model property would have let `audit_logs`
> default to the narrowing behaviour, which here means **silently hiding Master Admin's own
> actions from Master Admin** — a fresh instance of the same failure mode that decision
> rejects.

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

- **Two classes to keep in step** — **three since the decision 6 amendment.** A change to
  read-scope resolution touches all of them. Accepted: the duplication is a few lines, and
  merging them reintroduces the defaulting problem decision 1 rejects.
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

- ~~**The audit half of decision 5.**~~ **CLOSED 2026-08-12.** The spec landed, the migration
  landed, and `MasterAdminContext` writes the bypass. See the note on decision 5 — recorded
  as closed rather than deleted, so a reader who remembers the gap does not go looking for a
  decision that has already been made.
- **Unauthenticated and console contexts run unscoped.** Seeders, migrations, queue workers
  and artisan commands have no user to resolve a scope from, and throwing there would break
  every command. HTTP is protected by route middleware, not by this scope. Recorded because
  it is a real hole if a route is ever left unauthenticated — the scope will not save it.
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
- `docs/modules/audit-trail.spec.md` §11 — `SystemTenantScope` and the nullable
  `audit_logs.company_id`; §3, BR-AT9 — `security_events` and its declared opt-out
- `docs/conventions.md` §2 — multi-tenancy rule and both carve-outs
- `docs/schema.md` — `branches`, `departments`, `employees`, `employee_roles`
- `CLAUDE.md` Principle #4, §9
