# CLAUDE.md — HRMS Project Constitution

> This file is the single source of truth for this project. Every AI session (Claude Code)
> and every human contributor reads this before touching code. If code and this file
> disagree, the code is wrong — fix the code, not this file, unless a decision has
> genuinely changed (in which case update this file in the same commit).

---

## 1. Project Identity

**Client:** AL HADDAD SUCCESS SDN BHD (AHS) — a group of companies.

**Purpose:** Replace the legacy "AHS HR" system (built via AI coding tools without
persistent context or governance, resulting in schema drift, patch-on-patch migrations,
and file-level backups instead of git). This is a full rebuild, not a migration of code —
only select data (employee records) and validated business rules carry over.

**Primary goals, in order:** correctness and maintainability first, then speed of
completion, then token efficiency. Speed comes from good governance (this file), not from
skipping steps.

---

## 2. Non-Negotiable Principles

1. **Spec before code.** No migration, controller, or service is written without an
   approved spec file in `/docs/modules/`. If a spec doesn't exist for what you're about
   to build, stop and write the spec first.
2. **Single source of truth.** This file + `/docs/conventions.md` + `/docs/schema.md` +
   `/docs/business-rules.md` govern all technical and business decisions. Do not
   improvise conventions mid-session.
3. **No backup files. Ever.** `file.php.backup_step141A`-style files are permanently
   banned. Git is the only backup mechanism. If you're tempted to duplicate a file before
   editing it, that's a signal to commit first, then edit.
4. **Multi-tenancy from day one.** Every business table is scoped to `company_id` from
   the migration that creates it. Never retrofit tenant scope onto an existing table —
   this exact mistake caused real bugs in the legacy AHS system (see §9).
   One documented carve-out: `branches` and `departments` have a **nullable**
   `company_id` (`NULL` = shared across companies) because org units genuinely span
   companies in this group. The column still exists from creation, and sensitive employee
   data stays scoped to `employees.company_id`, which is NOT NULL. See `adr/0002` and
   `conventions.md` §2.
5. **`schema.md` is updated in the same commit as any migration.** Not "later." Same
   commit.
6. **One module per session.** `/clear` context frequently. Don't let one session span
   multiple unrelated modules.
7. **Conventional commits only**, one branch per module (see `/docs/conventions.md`).

---

## 3. Stack

| Layer | Choice |
|---|---|
| Framework | **Laravel 13** — see `adr/0007` |
| Frontend | Blade + Livewire 3 + Alpine.js |
| Styling | Tailwind CSS + Vite |
| Database | MySQL 8 |
| Local dev | Docker + Laravel Sail, via WSL2 on Windows |
| Deployment | Vultr VPS (Singapore), native LEMP, Deployer |
| Version control | Git + GitHub CLI (`gh`) — terminal only, no GitHub web UI |
| Docs | Obsidian vault, stored in-repo at `/docs` |

**The Framework row said `Laravel 12` until 2026-08-12, while `composer.json` required
`^13.8` and the lock file resolved 13.24.0.** The contradiction survived 22 pull requests
because neither statement was wrong on its own — the same shape as `adr/0006`, where no
single document contained the error either.

**`adr/0007` resolves it in favour of 13**, which is what `laravel new` installed and what
every migration, module and test has been written against. **No technical reason for 12 was
ever recorded** — it was an assumption, not a decision, which is why this row could be
amended under the "unless a decision has genuinely changed" clause above rather than by
changing the code to match. That clause is narrow on purpose; `adr/0007` is the argument that
it applies.

**Livewire 3 was listed here and uninstalled until 2026-08-12.** It landed with the account
management screen (`auth-rbac.spec.md` §7) — its first genuine requirement, and the point at
which installing it stopped being a dependency added to make a table true. The three auth
screens before it are plain form posts and were built without it deliberately (PR #18).

### Deployment constraints

Confirmed with the client. Recorded here because the reasoning was otherwise unwritten,
and an unwritten constraint is one the next person re-litigates or breaks.

**VPS RAM is a cost constraint, not an absolute one.** Coolify was evaluated and rejected
on RAM. Every further resident service — Redis, Elasticsearch, containerised production —
carries the same constraint and must be **weighed against the cost of a larger VPS**. Not
rejected outright, and not assumed free: the client accepts an upgrade where one is
warranted. Decide it per service, with the trade-off written down.

**Daily automated backups, off the VPS, encrypted before upload — with a key we hold.** Not
the storage provider's own encryption. Backups contain IC scans, salary, and medical
records, so a provider-encrypted backup is a backup the provider can read.

| Destination | Role |
|---|---|
| Vultr Object Storage | **Primary** — Singapore, same region as the VPS |
| Google Drive | **Secondary** — a dedicated backup account with 2FA, never a personal one |

**No data is ever deleted for performance.** HR records carry statutory retention periods
(~7 years — Employment Act and LHDN). Where reads get slow the answer is an index, or an
archive table **inside** the database — never a delete. Speed comes from indexes, not from
row count.

---

## 4. Division of Labor

- **claude.ai (chat)** — architecture decisions, planning, business-rule extraction.
  No code is written here.
- **Claude Code (terminal, WSL)** — all code: migrations, models, controllers, services,
  views, tests.
- **Cowork** — delegated, boundaries-clear, multi-step non-coding tasks (compiling
  documents, deep research, reading large file sets). Not used for quick back-and-forth
  discussion.
- **Obsidian** — spec and decision authoring. Vault lives inside `/docs`, so anything
  written there is part of the repo automatically.

**Language boundary.** Discussion in claude.ai runs in Malay; every artefact committed to
this repo — `CLAUDE.md`, `conventions.md`, `schema.md`, specs, ADRs, code comments, commit
messages, test names — is written in English, without exception. An instruction given in
Malay specifies **content**, never the language of the artefact. Where an instruction appears
to demand otherwise, **ask before writing**: a single Malay-language section in an otherwise
English document is drift, and it is worse in the documents whose job is to prevent drift.

---

## 5. Company / Entity Reference

| Entity | Code | Role |
|---|---|---|
| AL HADDAD SUCCESS SDN BHD | AHS | **Parent company — also an operating tenant** |
| AL HADDAD INTEGRATED MARKETING | AIM | Subsidiary |
| ES SOFEEYA ENTERPRISE | ES SOFEEYA | Subsidiary |
| ZISH GLOBAL PLT | ZISH GLOBAL | Subsidiary |
| TURSENIA TRADING | TURSENIA TRADING | Subsidiary |
| SLEGHO ALYA KITCHEN | SLEGHO | Subsidiary |

**Six entities: one parent and five subsidiaries.** That is the complete list.

Canonical spelling is binding. The legacy system used three different spellings for
ES SOFEEYA ENTERPRISE across three different files — do not repeat that. Always use the
spelling in this table.

**`ES SOFEEYA` is two words, with a space.** That is the registered spelling. The joined
form `ESSOFEEYA` used throughout the legacy system is wrong and must not be reintroduced.

**THALHAH is a brand under AIM, not a registered entity.** It does **not** exist as a
company in this system — no `companies` row, no `company_id`, and it must never appear in
a company picker. Anything labelled THALHAH belongs to AL HADDAD INTEGRATED MARKETING.

**AHS is a parent *and* an operating tenant.** It employs its own staff and holds its own
authority roles, so it is seeded like any other company and appears in every company
picker. It is **not** an empty holding row — do not skip it when seeding, and do not
filter it out of a company selector.

**Master Admin may add further companies later without a migration.** The list above is
seed data and a naming reference, not a schema enum. See `adr/0003` decision 9.

---

## 6. Folder Structure (repo root)

```
hrms/
├── CLAUDE.md                 ← this file
├── app/
├── database/
│   └── migrations/
├── docs/                     ← Obsidian vault root
│   ├── conventions.md
│   ├── schema.md
│   ├── business-rules.md
│   ├── modules/              ← one *.spec.md per module, written before code
│   └── adr/                  ← architecture decision records
├── routes/
├── resources/
└── tests/
```

---

## 7. Module Roadmap

**Phase 0 — Foundation**
Multi-tenancy · Auth + RBAC · Approval Workflow Engine · Notification Engine ·
Audit Trail · Document/Letter Generator

**Phase 1 — Employee & Org (current focus)**
Employee Master (full — family, education, employment history) · Org Structure
(list + interactive chart)

**Phase 2 — Operational**
Attendance (Excel import from NGTime, not clock-in app) · Leave · Payroll & Statutory ·
Claims

**Phase 3 — Value-add**
Recruitment · Performance/KPI · Training (HRDCorp) · Disciplinary/Employee Relations ·
Asset · AI Letter Generator (upgrade of Document Generator)

**Phase 4 — Later**
Native mobile app (only if web-responsive proves insufficient)

---

## 8. Reference Docs

- `/docs/conventions.md` — coding rules, architecture layers, git hygiene
- `/docs/schema.md` — living schema document, updated with every migration
- `/docs/business-rules.md` — HR policy rules sourced from the company handbook
- `/docs/modules/*.spec.md` — per-module specs, written before any code
- `/docs/adr/` — architecture decision records

---

## 9. Known Legacy Lessons (from AHS system audit)

The legacy AHS system's schema and `AGENTS.md` were audited before this rebuild began.
Findings that directly shape the rules above:

- **Tenant scope columns were bolted on late.** A migration dated well after the base
  table's creation added `branch_id`/`department_id` to an existing table with a SQL
  backfill. This is the exact failure mode Principle #4 exists to prevent.
- **Unstructured data where structured data was needed.** Working days/hours were
  stored as free-text strings (`"ISNIN - SABTU"`, `"9.00 AM - 5.00 PM"`) instead of
  structured columns — unquery-able, error-prone. See `schema.md` fix.
- **"Repair" migrations existed** to add columns that should have been in the original
  design — a direct symptom of code-before-spec. Principle #1 exists to prevent this.
- **Three migrations shared an identical timestamp** in one batch, risking ambiguous
  execution order. Space out migration timestamps generated in the same session.
- **Governance was added reactively.** The legacy system's `AGENTS.md` (itself
  reasonably well-written) was edited nine days *after* a file-backup incident had
  already occurred — proof that rules written after the fact are too late. This is why
  this file exists before any code does.
- **Naming drifted across documents** — the same company was spelled three different
  ways in three different files. §5 above is the fix: one canonical table, referenced
  everywhere.

The legacy system's **approval hierarchy design** and its validation checklist (in its
`AGENTS.md`) were genuinely good and are carried forward — see `business-rules.md`.

---

## 10. Open Decisions Pending

- **Miscarriage leave week threshold** (20 vs 28 weeks) — source handbook contradicts
  itself. Do not implement until confirmed. See `business-rules.md`.
- **Lateness penalty calculation** — source handbook clause 9.1.3 is internally
  inconsistent (garbled numbers). Confirm exact formula before implementing payroll
  deduction logic.
- **NGTime attendance export at full scale** — structure confirmed from a 24-employee
  sample; full company export not yet reviewed. Column mapping should be re-verified
  when available.
- **Employee self-service** — confirmed as required, not designed. Employees must be able
  to verify their own attendance data, submit corrections to their own profile **subject to
  HR approval**, and file a resignation request. This is a **module of its own**, not a
  section of the Auth & RBAC spec. `adr/0004` decision 7 already assumes it exists:
  accounts are provisioned for every employee precisely so attendance verification is
  possible, and payroll is blocked on unverified attendance. `employee-master.spec.md` §2
  lists self-service as out of scope for Phase 1, which stays true.

### Questions for the client — legacy import, added 2026-08-13

**These are not architecture questions, and nobody here can answer them.** They arose from
implementing Employee Master, where every rule now enforced meets the legacy data at once.

- **(a) How many employees in the current records have no usable mobile number, and who will
  collect the missing ones?** ⚠ This is the hard blocker. `users.phone_no` is the login
  username: NOT NULL, unique, 9–12 digits, and **a placeholder is banned** because it would
  occupy the unique index and hand one person's username to another (BR-A1). BR-A20 requires
  **every** employee to hold an account. So **an employee with no number cannot be given an
  account, and cannot be imported at all** — the record simply cannot be created.
- **(b) Do any two employees share a phone number?** A married couple at the same workplace,
  or a typo. The second one fails the unique index, and there is no placeholder path.
- **(c) Do the legacy records store who granted each authority role, and when?**
  `employee_roles.assigned_by` is NOT NULL and `effective_date` is required. If the old system
  kept neither, historical roles cannot be imported as they stand — and inventing a granter
  would be a confident falsehood in an audit column.
- **(d) When can the full export be provided, and in what format?** The file has never been
  seen. Source format, column mapping and table scope are all unknown, and none can be
  designed without it.
- **(e) Do the legacy records store who each employee's supervisor and manager are?** Added
  2026-08-14 with `adr/0011`, which makes `direct_supervisor_id` and `manager_id` (BR-8) the
  supervisory read bound instead of department equality. Under its **decision 4, an employee
  with both columns empty is read by nobody below `HR`** — no supervisor, no manager. Both
  columns stay nullable on purpose, so the import cannot invent a chain; but if the legacy
  data records no reporting lines at all, every imported employee is invisible to the entire
  supervisory tier until somebody fills them in, and the question of **who does that, and
  from what source** has to be answered before the import runs, not after.

**`employee-master.spec.md` §5.5 is blocked by (a) and (d) at minimum** — the same way the
four items above block Phase 2. The spec's own §5.5 now records the blockers, the four
decisions waiting on them, and two contradictions inside its five sentences that must be
decided rather than coded around.

⚠ **Deciding the column mapping or the row-rejection policy against an assumed shape is the
NGTime pattern above, repeated on purpose.** Structure confirmed from a sample, full export
never reviewed. Do not.

### Closed since 2026-08-11 — do not reopen without an ADR

Three items that sat here are **resolved by `adr/0004`**. Recorded as closed rather than
deleted, so a reader who remembers them does not go looking for a decision that has already
been made.

- **`system_access` value set — CLOSED.** Three values, `NOT NULL`, defaulting to
  `STANDARD`: `FULL` (Master Admin), `VIEW_ONLY` (read-only group-wide, **defined but
  currently unused** — the Director holds a Master Admin account instead), `STANDARD`
  (everyone else, permissions entirely from `employee_roles` + read scope). `adr/0004`
  decision 2; `schema.md` § `users`.
- **HR / Assistant Director group-level scope — CLOSED, and `employee-master.spec.md` §6 is
  corrected.** The fix is **not** "HR is group-level" — that would hardcode today's
  staffing into the permission layer. **Read scope derives from where the employer sits in
  `companies.parent_company_id`**: employed by AHS → reads the whole group; employed by a
  subsidiary → reads that subsidiary only. Same result today, and it still works when a
  subsidiary hires its own HR. `adr/0004` decision 1; spec §6.1. The ⚠ note on §6 is
  discharged and **§6 is now implementable**.
- **Data visibility vs approval authority — CLOSED.** Read scope is the derived rule above;
  salary remains the `ACCOUNT` role alone (`adr/0003` decision 5); documents, family,
  education, employment history and status history are settled per tab (`adr/0004`
  decisions 8–9, spec §6.2–6.3); disciplinary records and leave history are settled for
  modules not yet built (decisions 10–11). **Approval authority is still never an input to
  a visibility check** — that rule is unchanged and load-bearing. The two axes give
  different answers for the same person: a subsidiary-employed `HR` approves across the
  group while reading one company only. An implementation where they always agree has
  merged them and is wrong.

---

## 11. Spec Status and Required Inputs

**`docs/modules/auth-rbac.spec.md` (Phase 0) is written and Accepted — 2026-08-11.**
Under Principle #1, **Auth code is now authorized against it** — `MasterAdminSeeder`
included, along with the account-provisioning actions and the forced first-login
password-change middleware that `adr/0001` decision 5 *decided* but could not authorize.
An ADR records a decision; it is not a spec and does not authorize code. The spec is what
does, and it now exists.

`adr/0004` was its primary input and decided everything this section previously listed as
open. The spec covers the provisioning flow end to end, login and session handling, the
password-change gate as a middleware design, and the permission matrix across all six
`employee_roles.role` values plus the Master Admin account type — including the **absence**
of any role, which is how ordinary staff are expressed (`adr/0003` decision 1). The
checklist in `adr/0001` § Follow-up is covered.

### Required inputs — decided, and carried into the spec as written

**1. Approval authority and data visibility are separate axes, and both are now decided.**
Approval scope: `HR` and `ASSISTANT_DIRECTOR` are the **only** `employee_roles.role` values
that approve across companies; `SUPERVISOR`, `MANAGER` and `HOD` approve strictly within
their own `employees.company_id`, shared department or not (`adr/0002` decisions 4–5). An
employee with **no `employee_roles` row holds no approval authority at all** — there is no
`STAFF` role value — and `ACCOUNT` is **not a routing tier in either direction**
(`adr/0003` decision 4).

Read scope: derived from where the employer sits in `companies.parent_company_id` — AHS →
whole group, subsidiary → that subsidiary only (`adr/0004` decision 1). **Holding an
approval stage is never an input to a visibility check**, and the spec must still say so
explicitly. The two axes disagree by design: a subsidiary-employed `HR` approves across the
group while reading one company only. **If the matrix makes them always agree, it has merged
them and is wrong.**

**Authority is per company and must be read as such.** It lives in the `employee_roles`
pivot, not on `employees`, so *"what authority does this person have?"* has **no answer
until a company is named** — a permission function without a `company_id` argument is a
bug. Every read filters `WHERE revoked_date IS NULL`; omitting it returns revoked
authority as current, which is a **silent security failure, not an error** (`adr/0003`
decision 1). Read scope narrows *which* companies may be named; it never removes the need
to name one.

**2. Salary visibility is settled — it is the `ACCOUNT` role, not an HR sub-scope.** Only
an employee holding `ACCOUNT` may read salary data, at the company where they hold that
role. **No `HR` account may, regardless of how many HR staff exist, and group-level
employment does not change it** (`adr/0003` decision 5, `adr/0004` decision 3). Enforcement
is structural rather than declarative: `ACCOUNT` is a hardcoded restricted role that only
Master Admin may grant (`adr/0003` decision 3), so HR cannot grant it to itself.

`adr/0004` decision 3 widens the rule only for accounts holding **no roles at all**:
`system_access = FULL` and `VIEW_ONLY` read salary too. Master Admin and the Director were
never the targets of the restriction — **what HR must not see is salary**, and that line is
unchanged.

`hr_scope` (`PAYROLL | OPERATIONS`) — the provisional Payroll HR / Operations HR split
this section previously required — is **withdrawn, not deferred**. The client confirmed
the distinction does not exist. Do not reintroduce it, and do not carry it into the
permission matrix.

**3. Read scope is derived from the hierarchy, and `employee-master.spec.md` §6 is now
corrected.** `HR` and `ASSISTANT_DIRECTOR` do sit under AHS/HQ and administer all entities
— but that fact is **not** the rule. The rule is that scope follows the employer's position
in `companies.parent_company_id`, which yields the same answer today and still works when a
subsidiary hires its own HR (`adr/0004` decision 1). Encoding "HR is group-level" directly
would have hardcoded today's staffing into the permission layer.

**There is no manual scope override, and none may be added.** A stored override would be a
second answer to a question the hierarchy already answers, and the two would eventually
disagree — the same reasoning that rejected `secondary_company_id` (`adr/0003` decision 6)
and the `is_enabled` flag (`adr/0003` decision 1). **Scope depends on the hierarchy being
seeded correctly**, so a mis-parented subsidiary grants its staff group-wide reads: it is
load-bearing and must be covered by a test.

This also disposes of a question raised against cross-company transfers
(`employee-master.spec.md` §5.7): there is no "source HR vs destination HR", because HR is
not a per-company role in this group.

**4. Two Phase 2/3 modules already carry constraints from `adr/0004`** — cheap now,
impossible to retrofit cleanly. Disciplinary records must be **two-layered from the first
migration** (decision layer readable by the employee and their manager; investigation layer
HR/Account/Master Admin only — decision 10), and Leave must store the **MC attachment
separately from the request metadata**, since managers may see that a certificate exists but
never its contents (decision 11).

### What is blocked, and what is not

**Nothing blocks code in Phase 0 Auth or Phase 1 Employee Master.** `auth-rbac.spec.md` is
Accepted, and **`employee-master.spec.md` is fully unblocked** — §10 has no open questions,
its migrations were never blocked, and its §6 permission layer is decided and written.

**Still undesigned — neither blocks the two above:**

- **Employee self-service** (§10) — confirmed as required, not designed. A module of its
  own.
- **A consumer for the `BR-A16` freeze event.** `auth-rbac.spec.md` emits it when an account
  is frozen; the **Approval Engine** is what must act on it, and that spec does not exist
  yet. Until it does, the event is emitted and nothing listens.
