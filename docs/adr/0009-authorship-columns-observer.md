# ADR 0009 — Authorship Columns Are Written by an Observer

- **Status:** Accepted — 2026-08-13
- **Supersedes:** nothing
- **Related:** `adr/0003` decision 3 (`RestrictedRoleContext` precedent),
  `adr/0008` (correction taken while a closing window is open),
  `conventions.md` §3, §9, §11, `employee-master.spec.md` §5.1
- **Raised by:** Employee Master slice 2, which found that the mechanism §5.1
  promises does not exist

---

## Context

`conventions.md` §3 requires `created_by` and `updated_by` on every business
table, and `employee-master.spec.md` §5.1 states they are *"populated
automatically."*

**No such mechanism exists.** There is no observer, no trait, no base model and
no hook anywhere in the codebase that fills either column. `CreateEmployee` does
not set them. **Every row written to date carries `NULL` in both.**

This is a data defect that has already occurred, not a missing feature, and it
reaches further than the columns themselves. `schema.md` removed
`employee_documents.uploaded_by` on the argument that *"`created_by` already
records the same person."* **That sentence has never been true.** The write-once
lock on `file_path`, built specifically to keep `created_by` honest as the
uploader, is currently protecting a `NULL`.

Employee Master slice 2 set both columns explicitly inside its own Actions. That
is correct and is not wasted, but it is not the mechanism: every future write
path — the legacy importer, queue jobs, Phase 2 modules — would have to remember.

---

## Decision

### 1. An observer fills both columns, and a guard test enforces coverage

`AuthorshipObserver` sets **both columns on `creating`**, and rewrites `updated_by`
on every `updating`, registered for every model whose table carries the columns.

> **⚠ Amended 2026-08-13, during implementation and in the same PR.** This
> sentence originally read *"sets `created_by` on `creating` and `updated_by` on
> `updating`"* — which **contradicts decision 3 below**, where both columns become
> `NOT NULL`. A row inserted with a null `updated_by` could not be written at all,
> so the two decisions could not both hold.
>
> Writing both on insert is also the more honest reading, not a convenience:
> **for a row that has never been updated, its last update is its creation.**
> Eloquent stamps `created_at` *and* `updated_at` on insert for exactly that
> reason.
>
> Amended rather than replaced by a new ADR: the original text was written before
> implementation exposed the conflict, and the decision itself has not changed.

**A guard test fails when any model whose table has a `created_by` column is not
covered by the observer.** Without that test this decision is a trait in
disguise: a model that forgets the mechanism writes `NULL` and raises nothing,
which is the exact failure mode this ADR exists to close.

A trait was rejected for that reason. Explicit per-Action assignment was rejected
because it requires every future write path to remember, and §5.1 promises
automatic.

This is the same reasoning that put BR-16 on a model hook rather than in a policy
(`adr/0003` decision 3): **the absence of a path is not a guarantee.**

### 2. No authenticated user is refused, and the escape hatch is explicit

Outside an explicit context and with no authenticated user, the observer
**throws**. `NULL` is not written.

Seeders, console commands and the importer enter `AuthorshipContext`
deliberately, naming the acting user and stating a reason — the same shape as
`RestrictedRoleContext`, deliberately not a second design for the same problem.

**A silent `NULL` fallback was rejected.** It would leave the columns nullable in
practice, leave the `uploaded_by` argument still untrue for every row written
outside HTTP, and produce a mechanism that appears to enforce something while
enforcing nothing.

`employee_roles.assigned_by` is already `NOT NULL`, and this ADR holds
`created_by` to the same standard. Two columns answering the same question under
different standards converge on the looser one, because the looser one is easier.

### 3. Existing rows are discarded, not backfilled — and this option expires

Both columns become **`NOT NULL`**. Existing rows are not backfilled; the
development database is dropped and reseeded with the observer already in place,
so no row predates the mechanism.

**This is the second recorded use of `conventions.md` §11**, and it is not a
migration edit — it is the discarding of legacy data that is not data. All three
conditions hold today: no production environment, no real data, single developer.

**Backfilling to Master Admin was rejected.** Master Admin did not create those
rows. An audit column that states a confident falsehood is worse than one that
admits ignorance, and this project has rejected that trade three times already
(`is_active`, `uploaded_by`, `phone_no`).

**Leaving the columns nullable was rejected.** `NULL` would then mean two things
— *written before the mechanism* and *the mechanism failed* — and nothing could
tell them apart.

> **⚠ This decision expires on first deployment.** Once real data exists the
> reseed is unavailable, and the only remaining choices are the two rejected
> above. **The observer must therefore be in place before the first production
> row is written.** If deployment is reached with this undone, reopen with a new
> ADR and accept one of the two costs — do not reach for the reseed.

---

## Consequences

**Accepted**

- Every seeder is rewritten to enter `AuthorshipContext`. Four exist today; the
  cost grows with every one not yet written.
- **The implementation PR will turn hundreds of tests red at once** — every
  factory and seeder writing without an authenticated user fails together. That
  is the guard working, not a regression, and it is stated here so the size of
  the red does not read as a fault in the mechanism.
- Local development databases are dropped. Master Admin, companies and policy
  configurations must be reseeded.
- One observer touches every write in the system. A fault there is a fault
  everywhere — which is the argument for the coverage guard, not against the
  observer.
- `employee_documents.uploaded_by` stays removed, and its justification becomes
  true for the first time.

**Not changed**

- `employee_status_history` and the other §3 exceptions keep their documented
  exemptions. This ADR fills columns that exist; it does not add columns.
- `RestrictedRoleContext` is untouched. The two contexts answer different
  questions and stay separate classes.
