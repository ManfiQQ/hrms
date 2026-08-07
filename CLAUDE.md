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
| AL HADDAD SUCCESS SDN BHD | AHS | Parent company |
| AL HADDAD INTEGRATED MARKETING HQ | AIM HQ | Subsidiary |
| ZISH GLOBAL | ZISH | Subsidiary |
| THALHAH | THALHAH | Subsidiary |
| TURSENIA | TURSENIA | Subsidiary |
| ESSOFEEYA ENTERPRISE | ESSOFEEYA | Subsidiary |

Canonical spelling is binding. The legacy system used three different spellings for
ESSOFEEYA ENTERPRISE across three different files — do not repeat that. Always use the
spelling in this table.

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
- **Core Role vs Level taxonomy** — the legacy system used two overlapping
  classification systems (`core_role`: ASSISTANT_DIRECTOR/HR/MANAGER/SUPERVISOR/STAFF/
  MASTER_ADMIN, used for approval routing; `level`: STAFF/SUPERVISOR/MANAGER/HOD/ADMIN,
  used for org display). These need to be reconciled into one clear model before the
  RBAC spec is finalized.
- **Lateness penalty calculation** — source handbook clause 9.1.3 is internally
  inconsistent (garbled numbers). Confirm exact formula before implementing payroll
  deduction logic.
- **NGTime attendance export at full scale** — structure confirmed from a 24-employee
  sample; full company export not yet reviewed. Column mapping should be re-verified
  when available.
