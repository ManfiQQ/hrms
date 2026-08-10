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
| Framework | Laravel 12 |
| Frontend | Blade + Livewire 3 + Alpine.js |
| Styling | Tailwind CSS + Vite |
| Database | MySQL 8 |
| Local dev | Docker + Laravel Sail, via WSL2 on Windows |
| Deployment | Vultr VPS (Singapore), native LEMP, Deployer |
| Version control | Git + GitHub CLI (`gh`) — terminal only, no GitHub web UI |
| Docs | Obsidian vault, stored in-repo at `/docs` |

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
- **`system_access` value set** — `adr/0001` decision 5 provisions Master Admin with
  `FULL` and Director with `VIEW_ONLY`, but the field is not yet defined in `schema.md`
  and the value regular staff accounts receive is unspecified. Narrowed from the earlier
  form of this item: the `DIRECTOR`-vs-authority-role contradiction is **resolved** — see
  `adr/0001` decision 7, Director authority is off-system and `DIRECTOR` is correctly
  absent from the authority enum. Only the field definition remains. Resolve in the Auth &
  RBAC spec; does not block Employee Master.
- **HR and Assistant Director are employed at group level, and the Employee Master
  permission table does not yet reflect it.** Confirmed with the client: `HR` and
  `ASSISTANT_DIRECTOR` sit under AHS/HQ and administer **all** entities — they are not
  "an HR of TURSENIA". `employee-master.spec.md` §6 currently scopes their reads to
  `own company only`, which is **known-wrong, not merely unconfirmed**: shipping it would
  block HR on the system's first day. It also covers cross-company transfers (§5.7) — a
  group-level HR moving an employee from AIM to TURSENIA is the ordinary case, so the
  question "*which* HR, source or destination" does not arise.
  The §6 table is deliberately left as written with a ⚠ note, because the correct scope is
  an **Auth & RBAC decision** and a second answer written into Employee Master would be a
  second source of truth. **This blocks §6 being implemented in code, not the migrations.**
- **Data visibility vs approval authority — narrowed, salary is now closed.** `HR` and
  `ASSISTANT_DIRECTOR` may approve across companies; every other authority role, `HOD`
  included, is confined to its own `employees.company_id` (`adr/0002` decisions 4–5), and
  an employee with **no `employee_roles` row holds no approval authority at all**.
  Cross-company approval grants **no** read access to that employee's sensitive data.
  **Salary is resolved:** only the `ACCOUNT` role may read salary, and no `HR` may,
  however many HR staff exist (`adr/0003` decision 5). What remains **undefined** is the
  visibility check for everything else — personal documents, family records, disciplinary
  history, full leave history — **and, per the item above, the company scope of HR's reads
  in the first place.** Auth & RBAC spec, see §11. Does not block Employee Master's
  migrations; does block its permission layer.

---

## 11. Next Spec Required

**`docs/modules/auth-rbac.spec.md` (Phase 0) has not been written.** Under Principle #1,
**no Auth code may be written until it exists** — this includes `MasterAdminSeeder`, the
account-provisioning actions, and the forced first-login password-change middleware, all
of which are *decided* in `adr/0001` decision 5 but **not speced**. An ADR records a
decision; it is not a spec and does not authorize code.

The spec must cover the provisioning flow, `system_access`, login/session handling,
password policy, the forced password-change gate, and the full RBAC permission matrix
across all six `employee_roles.role` values plus the Master Admin account type — and the
**absence** of any role, which is how ordinary staff are expressed (`adr/0003`
decision 1). Full checklist in `adr/0001` § Follow-up.

### Required inputs already known — carry these into that spec

**1. Approval authority and data visibility are separate axes, and only one is decided.**
Approval scope is settled: `HR` and `ASSISTANT_DIRECTOR` are the **only**
`employee_roles.role` values that approve across companies; `SUPERVISOR`, `MANAGER` and
`HOD` approve strictly within their own `employees.company_id`, shared department or not
(`adr/0002` decisions 4–5). An employee with **no `employee_roles` row holds no approval
authority at all** — there is no `STAFF` role value — and `ACCOUNT` is **not a routing
tier in either direction** (`adr/0003` decision 4). Visibility is **not** settled: a
cross-company approval must confer **no** read access to that employee's salary, personal
documents, family records, disciplinary history, or full leave history. The spec must
define that visibility check explicitly and state that holding an approval stage is never
an input to it.

**Authority is per company and must be read as such.** It lives in the `employee_roles`
pivot, not on `employees`, so *"what authority does this person have?"* has **no answer
until a company is named** — a permission function without a `company_id` argument is a
bug. Every read filters `WHERE revoked_date IS NULL`; omitting it returns revoked
authority as current, which is a **silent security failure, not an error** (`adr/0003`
decision 1).

**2. Salary visibility is settled — it is the `ACCOUNT` role, not an HR sub-scope.** Only
an employee holding `ACCOUNT` may read salary data, at the company where they hold that
role. **No `HR` account may, regardless of how many HR staff exist** (`adr/0003`
decision 5). Enforcement is structural rather than declarative: `ACCOUNT` is a hardcoded
restricted role that only Master Admin may grant (`adr/0003` decision 3), so HR cannot
grant it to itself.

`hr_scope` (`PAYROLL | OPERATIONS`) — the provisional Payroll HR / Operations HR split
this section previously required — is **withdrawn, not deferred**. The client confirmed
the distinction does not exist. Do not reintroduce it, and do not carry it into the
permission matrix.

The visibility question still open for this spec is the **general** one in item 1 —
documents, family records, disciplinary history, full leave history. It no longer
includes salary.

**3. HR and Assistant Director work at group level — this spec must set their scope, and
Employee Master §6 is wrong until it does.** They sit under AHS/HQ and administer all
entities; there is no "HR of TURSENIA". `employee-master.spec.md` §6 scopes their reads to
`own company only`, which is **known-wrong rather than merely undecided** — it would block
HR on day one. That table was deliberately left as written, carrying a ⚠ note, so the
answer is written **once, here**, and not twice in two documents.

Two things follow for the permission matrix. **Approval scope and read scope are still
separate axes** — group-level employment settles *where HR works*, not *what HR may read*,
and item 1's rule that approving grants no visibility stands unchanged. And **salary stays
out of it regardless**: group-level or not, no `HR` reads salary (item 2).

This also disposes of a question raised against cross-company transfers
(`employee-master.spec.md` §5.7): there is no "source HR vs destination HR", because HR is
not a per-company role in this group.

Order: `auth-rbac.spec.md` → then code. `employee-master.spec.md` §10 has **no open
questions left** — its migrations are unblocked; only its §6 permission layer waits on
this spec.
