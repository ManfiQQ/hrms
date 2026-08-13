# Conventions

> Referenced by `CLAUDE.md`. These rules are binding for every module, every session.

---

## 1. Architecture Layers

| Layer | Responsibility | Forbidden |
|---|---|---|
| Controller | HTTP in, HTTP out. Thin. | Business logic. Inline validation. Raw queries. |
| FormRequest | All validation | `$request->validate()` inline in a controller |
| Service / Action (`App\Services`, `App\Actions`) | All business logic | Being skipped — no logic lives in controllers or models |
| Model | Relationships, scopes, casts | Fat business logic methods |
| Repository / Model scope | Complex or reused queries | Scattered raw `DB::table()` calls across controllers |

---

## 2. Multi-Tenancy Rule

Every **business** table (not pure reference/lookup tables like `leave_types`) must:

- Have a `company_id` foreign key, added in the migration that **creates** the table —
  never added later.
- Have a global scope applied automatically so queries are tenant-scoped by default.
- Only bypass tenant scope explicitly, from a Master Admin context, and never silently.

### Carve-out — shared org-structure tables

`branches` and `departments` have a **nullable** `company_id`, where `NULL` means
"shared across all companies" (HQ, Marketing, Logistics) and a set value means
company-dedicated. Their global scope must resolve to
`company_id IS NULL OR company_id = :current_company` — it **includes** shared rows
rather than filtering them out.

This is a deliberate carve-out, not a relaxation of Principle #4. These two tables are
org-structure references holding no personal or financial data. **Sensitive employee data
— leave, payroll, salary, documents, family, disciplinary — stays strictly scoped to
`employees.company_id`, which remains NOT NULL.** The rule is **shared structure, scoped
data**: where a person works is organizational, who employs them is tenancy.

An employee's `branch_id` / `department_id` need **not** match their `company_id`, and
validation must not require it. Full reasoning: `adr/0002`.

**Shared structure does not mean shared authority.** A shared department is a shared
*place*, not a shared approval pool. An **HOD approves only for employees sharing their
own `employees.company_id`**, inside a shared department as much as anywhere else
(`adr/0002` decision 4). The only `employee_roles.role` values that approve across companies are
`HR` and `ASSISTANT_DIRECTOR` — and even they gain **no data visibility** by doing so;
that runs through a **separate permission check, decided in `adr/0004` decision 1**
(`adr/0002` decision 5). Do not infer authority scope from structure scope; that
inference is exactly the error corrected on 2026-08-08.

**Read scope is a third thing again, and it is derived — not from the role, and not from
the org structure.** It comes from where the account's employer sits in
`companies.parent_company_id`: employed by **AHS** (the parent) reads the **whole group**;
employed by a **subsidiary** reads **that subsidiary only**. It is never stored per account
and there is no manual override — a stored override would be a second answer to a question
the hierarchy already answers (`adr/0004` decision 1).

So three scopes coexist and must not be collapsed into each other:

| Scope | Answers | Comes from |
|---|---|---|
| **Structure** | Where does this person work | `branches` / `departments`, shared or dedicated |
| **Approval** | Whose requests may they act on | `employee_roles.role` + `employees.company_id` |
| **Read** | Which companies' employees may they see | The employer's position in `companies.parent_company_id` |

They disagree by design: a **subsidiary-employed `HR` approves across the whole group while
reading one company only**. An implementation in which any two of these always agree has
merged them, and the merge is a silent widening of access, not a simplification.

### Carve-out — event tables freeze `company_id`, and release tenant scope through an employee

This is the **second** carve-out to §2, independent of the shared org-structure one above.
It comes from `adr/0003` decision 7 and it governs every table Phase 2 is about to create.

On an **event** table — `employee_status_history` today, and all Phase 2 leave, payroll and
attendance tables — `company_id` is a **frozen historical fact**: it records the employer
**at the moment the event happened**. It is written once and **never cascaded** when the
employee later transfers to another group company. A payslip issued by AIM must not be
rewritten as TURSENIA's because the person transferred afterwards; that is not an update,
it is falsification.

**⚠ Consequence — frozen rows fall outside the new employer's tenant scope.** After a
transfer, every pre-transfer history row still carries the **old** `company_id`, so the
ordinary tenant scope filters them out. The employee's history tab would appear to
**begin on the transfer date, with no error raised** — the same silent-missing-rows
failure mode the shared-branch carve-out above guards against (`adr/0002`). Fewer rows,
not an exception.

**Therefore: an event table accessed *through* an employee relationship releases the
tenant scope.** Permission has already been decided one level up — **if the user may read
this employee, they may read this employee's history.** Re-filtering row by row adds no
security whatsoever and breaks the record. Queried **directly** for reporting, the tenant
scope applies in full, so "how many promotions did TURSENIA make this year" stays
correctly scoped to TURSENIA.

**Test both directions**, as with the `adr/0002` carve-out: history stays visible after a
transfer, *and* direct reporting queries stay scoped.

#### The three cascade categories — apply this when creating any new table

What `company_id` *means* differs per table, and that meaning decides what happens on a
company transfer. There are exactly three categories:

| Category | `company_id` means | On transfer | Tables |
|---|---|---|---|
| **Descriptive** | Tenant marker; the row describes the *person* | **Cascade** | `employee_family_members`, `employee_education_history`, `employee_employment_history`, `employee_documents` |
| **Event** | The employer *at the time it happened* | **Frozen forever** | `employee_status_history`, and all Phase 2 leave / payroll / attendance tables |
| **Company-reference** | A real reference to a company, unrelated to employment | **Untouched** | `employee_roles`, `employee_job_functions` |

**The three-question test — ask it of every new table.** *If this person's payroll employer
changed tomorrow, would this row still be true?*

- Yes, and it is about the person → **descriptive**, cascade
- Yes, because it happened under the previous employer → **event**, freeze
- Yes, because `company_id` here is not about the employer at all → **company-reference**,
  leave alone

Company-reference rows are left alone for a different reason than event rows: a Manager
role at AIM is still a Manager role at AIM after the person's payroll moves elsewhere.
Cascading it would corrupt the data outright rather than merely hide it.

**A new table placed in the wrong category corrupts data on transfer**, and the corruption
is not visible at insert time — it appears only after a transfer that may be months away.
Decide the category in the module spec, before the migration is written. Full reasoning:
`adr/0003` decision 7; per-table detail in `schema.md` § Company transfer.

### Carve-out — `audit_logs` takes a third scope class

The **third** carve-out to §2, and the only table taking a scope class of its own.

`audit_logs.company_id` is **nullable**, and `NULL` means **"a system-level event"** — an
audited action whose subject belongs to no company, such as a Master Admin changing another
Master Admin's `system_access`, or a tenant-scope bypass entered through
`MasterAdminContext`.

Neither of the two usual classes fits, and they fail in **opposite** directions:
`TenantScope` hides those rows from everyone including Master Admin — whose own actions
they mostly are — while `SharedTenantScope` shows them to everyone, so a subsidiary `HR`
would read every group-level administrative action. The table therefore declares
`App\Models\Scopes\SystemTenantScope`:

```
company_id IN (:read_scope)
OR (company_id IS NULL AND the account has system_access = FULL)
```

⚠ **`NULL` does not mean the same thing on `branches` as it does here.** There it means
*available to all companies*; here it means *attributable to no company*. Same column type,
opposite meaning — decide it per table, and never pick a scope class because a column
happens to be nullable.

Applies to `audit_logs` and to nothing else without an ADR, the same restriction
`SharedTenantScope` carries. `adr/0005` decision 6's guard test must **recognise** this
class as a valid declaration rather than exempt the model from the test. See
`adr/0005` decision 6's amendment note and `audit-trail.spec.md` §11.

### Carve-out — `security_events` carries no tenant scope at all

The **fourth** carve-out, and the only table in the system with no scope class at all —
including not the `SystemTenantScope` above.

`security_events` records authentication events, which happen **before authentication** —
so `SystemTenantScope` does not fit it either, since that class reads the account's
`system_access` and there may be no account.
There is no authenticated user from whom to resolve a read scope, and in the
failed-attempt case there may be **no account at all** — an attempt against a phone number
that has never existed here has no subject, so no employer, so no company. `company_id` is
therefore **nullable**, filled where knowable and left null where it is not, and it is a
**reporting convenience, never an access control**.

Access control for the table is a permission check in `audit-trail.spec.md` BR-AT9, applied
at read time: Master Admin sees everything, `HR` and `ASSISTANT_DIRECTOR` see within their
read scope, and an event with a null `user_id` — belonging to no company — is Master Admin
only.

**The opt-out must be declared on the model, not left as silence.** `adr/0005` decision 6's
guard test exists precisely so that *"deliberately unscoped"* and *"someone forgot"* stay
distinguishable; a `SecurityEvent` with no declaration must fail the suite like any other
model. This carve-out is a **declaration, not a precedent** — no other table takes it
without an ADR.

## 3. Every Business Table Must Include

- `company_id` (except pure reference/lookup tables; nullable on the shared
  org-structure tables `branches` and `departments` — see the §2 carve-out)
- `created_by`, `updated_by` — nullable, FK to `users`
- Soft deletes
- Timestamps

### Deliberate exceptions — do not "fix" these

Four tables depart from the list above **on purpose**. Each omission is load-bearing:
adding the missing column back would create a second way to express a state the table
already expresses once, and two mechanisms for one meaning eventually disagree. If you
find yourself about to add a soft delete here because "every business table has one,"
that is the mistake this section exists to stop.

| Table | Omits | Why |
|---|---|---|
| `employee_status_history` | `updated_by`, `updated_at`, soft deletes | Append-only ledger — rows are inserted, never edited or deleted |
| `employee_roles` | Soft deletes | Revocation is `revoked_date`; a `deleted_at` would be a second way to say "revoked" |
| `audit_logs` | `updated_by`, `updated_at`, soft deletes, `created_by` | Append-only. `user_id` **is** the actor, so `created_by` would record the same person twice |
| `security_events` | `updated_by`, `updated_at`, soft deletes, `created_by` | Append-only, and written before there is an authenticated actor to attribute |

**`employee_status_history` is an append-only ledger.** A correction is a **new row**, not
an edit to an existing one. Mutability would defeat the entire point of the table: a
ledger that can be rewritten after the fact cannot answer "when did this employee move
from Grade C to D" with any authority. It therefore carries `created_at` and
`changed_by` only — no `updated_at`, no `updated_by`, no `deleted_at`. See `adr/0003`
decision 8.

**`employee_roles` has no soft deletes, and none may be added.** A role is withdrawn by
setting `revoked_date`; re-granting later inserts a **new row**, which preserves the full
cycle (held Jan–Aug, revoked Aug, re-granted November). Adding `deleted_at` would create a
**second mechanism meaning "revoked"**, and every authority check would then have to test
both — the check that tests only one is a silent security hole, not an error. **Every
authority query filters `WHERE revoked_date IS NULL`**, applied as a default model scope
rather than repeated at each call site. The same reasoning bans an `is_enabled` flag on
this table. See `adr/0003` decisions 1 and 3.

**`audit_logs` and `security_events` are append-only for the same reason and more
strongly.** There is no update path, no delete path, and no UI affordance for either, **not
for Master Admin** (`audit-trail.spec.md` BR-AT6). A correction is a new row. This is what
makes it safe to let `HR` *read* the audit log at all: the value of an audit trail comes
from not being able to **delete** it, not from not being able to **see** it. A soft delete
here would be a delete path with a nicer name.

The single exception is the `security_events` retention sweep — a scheduled command with
one fixed predicate, removing only rows with a null `user_id` past the configured window
(`audit-trail.spec.md` BR-AT11). It touches `audit_logs` never.

All four exceptions are recorded in `schema.md` on the tables themselves as well, so none
can be discovered only by reading this file.

### Not exceptions — `positions`, `branches` and `departments` are a scheduled correction

**These three carry no soft deletes and no `created_by` / `updated_by`, and they are
deliberately absent from the table above.** They are **not** exceptions to §3 and must not be
added to it: they are **business tables that do not yet meet it**, with a decided date for
when they will. See **`adr/0008`**.

The distinction matters, because the two are corrected in opposite directions. An exception
above is **finished** — adding the missing column back would create a second way to express a
state the table already expresses once, and doing so is the mistake this section exists to
stop. These three are **unfinished**: the columns are genuinely required and are simply not
written yet.

`adr/0008` decision 3 defers them to the **Org Structure module**, whose screens will define
the shape they need, and decision 5 puts a **guard test** behind the deferral — no delete
route, controller action or service method may exist for `Branch`, `Department` or `Position`
while the tables lack `deleted_at`. Nothing can delete these rows today: every foreign key
resolves to `ON DELETE NO ACTION`, and no deletion path exists anywhere in `app/`.

⚠ **Do not read this section as licence to leave a new table incomplete.** `adr/0008`
decision 4 draws the line at whether the migration already exists: **new tables are born
complete; existing tables are corrected when their screen arrives.** `job_functions` is
created with all four columns in the migration that creates it, precisely because it is new.

## 4. Structured Data Over Free Text

Learned directly from the legacy AHS system: don't store `working_days = "ISNIN - SABTU"`
as a string. Anything the system needs to calculate against — time ranges, day lists,
rates — must be a structured column (`TIME`, `JSON`, enum), never a free-text field
parsed at runtime.

## 5. Config Over Hardcode

All HR policy numbers — annual leave days, OT rate, EPF contribution base, sick leave
tiers, lateness penalty amounts — live in a per-company Policy Configuration
table/model. Never hardcode them in business logic, even though all six current
entities happen to share the same values today. See `business-rules.md` for the
current default values.

---

## 6. Naming

- **Tables:** `snake_case`, plural (`leave_requests`, `attendance_import_rows`)
- **Models:** `PascalCase`, singular (`LeaveRequest`, `AttendanceImportRow`)
- **Methods:** verbs, `camelCase` (`calculateOvertime()`, not `overtimeCalc()`)
- **Migration files:** never let two migrations in the same batch share an identical
  timestamp. If generating several in one command, verify with
  `ls database/migrations | sort` before committing — the legacy system shipped three
  migrations with an identical timestamp in one batch.

---

## 7. Migrations

- Forward-only. Never run `migrate:fresh` or `db:wipe` against shared or production data.
- Do not patch a table's design with a later "repair" migration if it can be avoided —
  this means the module spec is reviewed and approved **before** the first migration for
  that module is written, not after.
- Every migration merged into a branch = `schema.md` updated in the **same commit**.

---

## 8. Git

- **Conventional commits only:** `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`
- **One branch per module:** `feat/employee-master`, `feat/leave-approval`
- No direct commits to `main`
- **No backup-suffix files, ever** — no `*.backup_*`, `*_old.php`, `*_v2.php`. If you
  need a backup, commit first.

### Terminal for actions, browser for reading

**All git *actions* — commit, push, merge, PR create/edit, branch delete, approve — go
through the terminal + `gh` CLI.**

The GitHub web UI **may** be used to visually read diffs and PR descriptions; rendered
markdown and side-by-side diffs are genuinely easier to review in a browser, and reading
changes nothing. But **never click an action button** there — merge, approve, edit,
delete. Those must be run as `gh` commands so every action leaves a corresponding
terminal record.

The point is traceability: an action taken in the browser exists only in GitHub's event
log, disconnected from the local shell history that shows what was actually done and in
what order. Merges in particular happen via `gh pr merge`.

> **One-time exception, already spent.** PR #1 was merged with the browser's merge button
> before this clarification existed. It is recorded here as history, **not** a violation
> to correct retroactively — the merge is sound and nothing needs reverting. Do not
> repeat it; from here, merges are `gh pr merge`.

---

## 9. Testing

Pest. **Mandatory** for anything touching money or statutory entitlement:

- Leave balance calculation (accrual, pro-rata, carry-forward)
- Payroll calculation (EPF, SOCSO, PCB, OT)
- Attendance-derived pay adjustments (lateness penalty, Saturday hours banking)

Optional elsewhere, but encouraged for anything with non-trivial branching logic.

### Every guard test must be watched failing before it is trusted

A **guard test** is one that protects a rule rather than exercising a feature: an
architecture test, a registry check, a "this column must not exist" assertion, a tripwire
that fires when a blocker is lifted.

**The rule: break the thing it guards, watch the test fail, then restore.** Record in the
commit message or the PR that you did, and which test failed. A guard that has never been
observed failing has not been shown to guard anything.

⚠ **This is written down because it happened three times in one session, and every time the
suite was green.**

| The guard | Why it was empty |
|---|---|
| "every registry entry has a non-empty field list" | Looped over an **empty array**. Zero iterations, zero assertions, permanent pass |
| "the seeder never calls `env()`" | Matched the seeder's own **docblock**, which explains why `env()` is wrong — using the string it searches for |
| "the config declares no fallback value" | Matched a **comment** containing `env('MASTER_ADMIN_PASSWORD')`, and passed against a real fallback on the line below it |

None of the three was a wrong assertion. Each was a *correct* assertion pointed at nothing:
an empty set, or prose that happened to contain the pattern. **A guard test that checks
nothing and a guard test that passes look identical from the outside**, which is the whole
problem — there is no failure to notice, and the green tick is indistinguishable either way.

Two habits follow, both cheap:

- **A guard over a collection must assert the collection is not empty**, or must fail
  explicitly while it is — as `AuditedFields` and `SalaryFields` do, each declaring *why* it
  is empty and *until when*.
- **A guard that reads source text must strip comments first.** Documentation of a rule
  routinely contains the string the rule forbids; that is what good documentation looks
  like, and it is exactly what defeats a naive search.

The cost is one minute per guard. The alternative is a test suite whose most important
assertions are the ones least likely to be true.

**When a deliberate break produces GREEN, the first assumption is that the break did not take
effect — not that the guard is empty.** Confirm the break actually changed behaviour before
concluding anything about the guard: a break silently dropped by mass-assignment protection, a
cached config, or a filtered test run looks identical to a guard that never asserted anything.

Recorded 2026-08-13, after a seeder break set via `updateOrCreate` was dropped because the
column was not fillable, and the guard read as empty when it was sound.

**Where two guards protect the same path, a test for the inner one must satisfy the outer one
first** — otherwise it exercises the wrong guard, and breaking the right one will not change
its colour. This is the same failure as the paragraph above with its sign reversed: not a
break that produces green, but a break that produces **red for the wrong reason**, which is
just as undetectable and rather more convincing.

Recorded 2026-08-13, after `AuthorshipObserver` began refusing writes with no actor and
started intercepting the BR-16 restricted-role tests before the rule they were written for
could run.

### ⚠ Principle #5 is enforced by nothing — a finding, not a decision

**`CLAUDE.md` Principle #5 — *`schema.md` is updated in the same commit as any migration* — was
broken on 2026-08-13 in PR #37**, the PR that built an enforcement mechanism. The authorship
`NOT NULL` migration landed and `schema.md` never mentioned it.

**It passed the §10 checklist, because §10 does not check it.** No test compares
`database/migrations/` against `schema.md`'s Status table, and none compares a migration's
columns against the entry describing them. **The principle most often cited in this project
rests entirely on somebody remembering it.**

That is the same shape as the `WithoutModelEvents` finding above: a rule everyone believes is
in force, carried by nothing. A guard comparing the two would have caught it in the run that
introduced it.

**This is a record of the finding, not a decision about the remedy.** The guard is not written,
and designing it needs its own scope — what counts as "described", how a multi-table migration
maps to entries, and whether the Status table or the per-table sections are the source of
truth. Recorded here so its absence is a known gap rather than a surprise the next time it
fails.

### ⚠ A model hook is enforcement only where events are enabled

**Every rule in this project enforced "structurally rather than by policy" is a model event,
and `Model::withoutEvents()` switches all of them off at once.** Laravel's
`WithoutModelEvents` trait — scaffolding on `DatabaseSeeder`, chosen by nobody — does exactly
that for the whole seeding run.

Verified on 2026-08-13 rather than assumed. Inside `withoutEvents()`, `audit_logs` and
`security_events` both accepted an `UPDATE` and a `DELETE` that are refused everywhere else:

| Enforced by a hook | Bypassed |
|---|---|
| `audit_logs`, `security_events` append-only | **yes** |
| `employee_status_history` append-only | **yes** |
| `employee_documents.file_path` write-once | **yes** |
| `employee_roles` BR-16 restricted grants | **yes** |
| `AuthorshipObserver` | **yes** |
| Global scopes (`TenantScope` and the rest) | no — query-time, not events |

**It was found only because one fail-closed constraint was installed.** Making
`created_by` `NOT NULL` turned a silent `NULL` into a failed seeder in a single run;
everything above had been bypassed since the first seeder and nothing had ever said so.
That is the whole argument for fail-closed over fail-open, stated by an accident rather than
by a design review.

Two rules follow. **Never suppress events for a whole seeding run** — if one seeder genuinely
needs it, scope it to that seeder and write down why. And when a rule is described as
structural, **state which mechanism carries it**: a global scope survives `withoutEvents()`, a
hook does not, and the difference decides whether the rule holds in seeders, imports and
console commands at all.

### ⚠ Query-builder writes bypass model events entirely — the second hole, same family

Not by suppressing them, but by **never raising them**. A mass `update()` through the query
builder writes rows without `AuthorshipObserver`, without any hook, and **without anything
failing**.

`AuthorshipCoverageTest` does not catch it: that guard checks **which models are registered**,
not whether a write path goes through a model at all. It stays green for the entire time this
hole is open.

Found 2026-08-13 while writing `TransferCompany`, whose cascade uses a mass update deliberately
and therefore sets `updated_by` by hand. **That is correct there. The gap is everywhere else
that uses one.**

Recorded as a finding, not a decision. **Anything described as enforced by a hook holds only
for writes that go through a model instance.**

---

## 10. Required Validation Before Calling a Module "Done"

Adapted from the legacy AHS system's own `AGENTS.md` — this part of their governance
was solid and is carried forward:

1. `php artisan optimize:clear`
2. PHP syntax check on changed files
3. `php artisan route:list --no-ansi` — sanity check routes registered correctly
4. `php artisan test`
5. `npm run build` if any frontend file changed
6. Migration test against an **empty test database** — never against a database with
   real or seeded production-like data
7. Sensitive-file check — confirm no `.env`, credentials, employee documents, salary
   files, or database dumps are staged for commit

Report: summary of changes, files changed, migrations added, test results
(exact pass/fail), remaining risks, rollback notes.

---

## 11. Editing a Migration That Has Already Been Merged

**Permitted ONLY while all three of these conditions hold:**

- **(a)** no production environment exists,
- **(b)** no real data exists in any database,
- **(c)** the repo is held by a single developer.

**When any one condition falls, this option dies** and a repair migration becomes the only
route — even though that is the pattern `CLAUDE.md` §9 records as the legacy system's
disease.

**The reasoning:** a repair migration is **permanent debt**; an in-place edit is **zero
debt** while this window is open. The window closes on the **first deployment**, or on the
**first clone by a second person** — whichever comes first.

⚠ **Anyone who already has a local database must `migrate:fresh`** — editing a migration does
not change a database that has already run it.

**Opened 2026-08-13. Every use must be logged below.**

### Usage log

- **2026-08-13** — index `(company_id, staff_status)` on `employees`.
- **2026-08-13** — development database dropped and reseeded so no row predates the authorship
  observer (`adr/0009` decision 3).
