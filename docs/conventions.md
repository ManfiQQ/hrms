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
(`adr/0002` decision 4). The only `core_role` values that approve across companies are
`HR` and `ASSISTANT_DIRECTOR` — and even they gain **no data visibility** by doing so;
that runs through a separate permission check owned by the Auth & RBAC spec
(`adr/0002` decision 5). Do not infer authority scope from structure scope; that
inference is exactly the error corrected on 2026-08-08.

## 3. Every Business Table Must Include

- `company_id` (except pure reference/lookup tables; nullable on the shared
  org-structure tables `branches` and `departments` — see the §2 carve-out)
- `created_by`, `updated_by` — nullable, FK to `users`
- Soft deletes
- Timestamps

## 4. Structured Data Over Free Text

Learned directly from the legacy AHS system: don't store `working_days = "ISNIN - SABTU"`
as a string. Anything the system needs to calculate against — time ranges, day lists,
rates — must be a structured column (`TIME`, `JSON`, enum), never a free-text field
parsed at runtime.

## 5. Config Over Hardcode

All HR policy numbers — annual leave days, OT rate, EPF contribution base, sick leave
tiers, lateness penalty amounts — live in a per-company Policy Configuration
table/model. Never hardcode them in business logic, even though all five current
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
