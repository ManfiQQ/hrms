# ADR 0008 — Soft Deletes and Attribution on Org-Structure Tables

- **Status:** Accepted — 2026-08-13
- **Supersedes:** nothing
- **Related:** `adr/0002` (shared org structure), `adr/0003` decision 2
  (`job_functions` as a reference table), `conventions.md` §2, §3, §9, §11
- **Raised by:** finding #7 of the read-only audit performed before
  Employee Master slice 1

---

## Context

`positions`, `branches` and `departments` carry no `deleted_at`, no
`created_by` and no `updated_by`. `conventions.md` §3 requires all four on
every business table and lists exactly four deliberate exceptions
(`employee_status_history`, `employee_roles`, `audit_logs`,
`security_events`). These three appear in neither list. The omission is
undocumented — neither a decision nor a declared exemption.

**What the audit established about present risk.** Every foreign key in the
repo is declared without an `onDelete` clause, so MySQL resolves them to
`ON DELETE NO ACTION`, which InnoDB checks immediately and which therefore
behaves as `RESTRICT`. There is no cascade anywhere. Beyond that, there is
no route, no view, no Livewire component and no `->delete()` call anywhere
in `app/` that touches `Branch`, `Department` or `Position`.

**These tables cannot be deleted today because nothing exists that could
delete them.** The gap is not a live defect; it is missing columns for a
screen that has not been built.

---

## Decision

### 1. All three are business tables, not reference tables

`positions`, `branches` and `departments` are **business tables** and are
**not** added to the `conventions.md` §3 exception list.

The test is not row count or apparent simplicity — it is **who creates a
row, and when**. `leave_types` is defined by the Employment Act; HR does not
invent a new leave type on a Monday afternoon. A department is created by HR
when a unit opens. A table whose rows are authored through the application
needs `created_by` to answer *who did this*.

This is the same reasoning `adr/0003` decision 2 already applied to
`job_functions`, and it is applied here without modification.

### 2. Removal is soft delete, and it is blocked while the row is in use

A `branch`, `department` or `position` still referenced by any employee
**may not be soft-deleted**. The application blocks it and states how many
employees hold it, so the operator is told what to do rather than shown a
constraint violation.

Closure is permitted only once the row is unheld. A closed row stays
readable so history does not break, and disappears from every picker.

`NO ACTION` at the database layer is retained as the last line of defence.
It is a backstop, not the user-facing rule: a bare SQL error is not a
message anyone can act on.

### 3. The columns are added by the Org Structure migration, not now

`positions`, `branches` and `departments` already have migrations. Adding
columns to them today means an in-place edit under `conventions.md` §11 —
spending a use of a rule opened the same day, for columns with no consumer,
in a shape the Org Structure screens have not yet defined.

The columns are therefore added **in the Org Structure module**, whose
migrations will define the screens that delete these rows. The §11 window
stays open until the first deployment or the first clone by a second person;
neither is imminent.

**This is a deferral with a guard, not an open item** — see decision 5.

### 4. `job_functions` is created complete in slice 1

`job_functions` is created by Employee Master slice 1 and carries
`deleted_at`, `created_by` and `updated_by` **in the migration that creates
it**, per `CLAUDE.md` Principle #4's reasoning applied to attribution.

**The distinction that separates decision 3 from decision 4 is not the kind
of table. It is whether the migration already exists.** A table being
created now takes its full shape from its first line, at zero cost. A table
that already exists is corrected when the screen that operates on it is
built.

Stated as a rule: **new tables are born complete; existing tables are
corrected when their screen arrives.**

### 5. A guard test enforces the deferral

A test asserts that **no delete route, controller action or service method
exists for `Branch`, `Department` or `Position` while those tables lack
`deleted_at`**. If someone builds a deletion path before the columns land,
the suite fails and names this ADR.

Written under `conventions.md` §9 and, per that section, **confirmed failing
before it is trusted** — by adding a delete route and watching it go red.

This converts a deferral that depends on memory into one enforced by the
suite. `CLAUDE.md` §2 prefers structural enforcement over written policy;
this is that preference applied to a decision about the future.

---

## Consequences

**Accepted**

- Three tables stay out of convention until Org Structure is built. The
  divergence is now documented and tested rather than silent.
- `job_functions` will carry attribution columns its three siblings lack.
  The inconsistency is temporary, deliberate, and explained by decision 4.
- Org Structure inherits a migration that edits three existing files. Its
  author must read `conventions.md` §11 and log the use.

**Rejected**

- **Adding the columns now.** No consumer exists, the required shape is not
  yet known, and it spends a §11 use for nothing.
- **Declaring the three tables reference tables.** It would exempt them from
  attribution permanently, and the same argument would exempt
  `job_functions`, which `adr/0003` already refused.
- **Relying on `NO ACTION` alone.** It prevents data loss and communicates
  nothing. The failure mode is an HR user facing a raw SQL error.

**Note on the audit itself**

Finding #7 read as cleanup and was nearly treated as such. The question it
actually asked — *what happens to employee records when a department is
deleted* — could only be answered by inspecting the live foreign-key
metadata, which is what moved this from an assumed emergency to a
scheduled correction. The verification changed the decision.
