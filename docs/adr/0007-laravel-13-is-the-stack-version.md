# ADR 0007 — Laravel 13 Is the Stack Version

- **Status:** **Accepted** — 2026-08-12. Implemented in the same commit: `CLAUDE.md` §3 now
  reads Laravel 13.
- **Date:** 2026-08-12
- **Amends:** `CLAUDE.md` §3 — the Framework row, `Laravel 12` → `Laravel 13`
- **Closes:** a documentation contradiction that survived 22 pull requests unnoticed
- **Does not decide:** the Livewire question. `CLAUDE.md` §3 lists Livewire 3 in the frontend
  row and **it is not installed** — a separate instance of the same drift, deliberately left
  for the account-management screen that will first need it (`auth-rbac.spec.md` §7).
- **Affects:** `CLAUDE.md` §3, `composer.json` (unchanged, and that is the decision)

---

## Context

`CLAUDE.md` §3 has said **Laravel 12** since the file was written, before any code existed.
`composer.json` requires **`^13.8`**, and the lock file resolves **13.24.0** on PHP 8.5.

Both statements have been in the repository from the first commit that had a `composer.json`.
Neither was ever edited to disagree with the other; they simply never agreed.

### It survived twenty-two pull requests

Ten migrations, two modules, seven ADRs and 201 tests were written against Laravel 13 while
the project's own constitution said 12. **Nothing failed, because nothing was wrong** — the
code was written against the framework that is actually installed, and the framework does not
read `CLAUDE.md`.

> **⚠ Count corrected 2026-08-14.** This sentence read *"Six migrations"*. The commit that
> introduced it carried **thirteen migration files, ten of them written by this project** —
> the other three are the defaults `laravel new` ships. Six was neither. The other three
> figures check out: PRs #1–#22 were merged by the time this ADR landed, seven ADR files
> existed including this one, and 201 is consistent with the 176 test declarations then in
> `tests/` once Pest datasets are counted. `conventions.md` §9.

This is the same shape as `adr/0006`, and it is worth naming because it has now happened
twice:

> **No single document was wrong on its own.** `CLAUDE.md` §3 stated an intended stack;
> `composer.json` stated a resolved dependency. Each was internally consistent and locally
> accurate. The contradiction existed only *between* them, and nothing forces a reader to
> hold both open at once.

`adr/0006`'s version of this cost the project a system nobody could log into. This one cost
nothing at all — which is precisely why it lasted so long. **A contradiction with no symptom
has nothing to prompt anybody to look.**

### The 12 was never a decision

Searched for and not found: any ADR, spec, commit message or `CLAUDE.md` paragraph giving a
reason to prefer Laravel 12. There is no compatibility constraint recorded, no hosting
limit, no package pin, no client requirement. §3's other rows carry their reasoning — Sail
over a native stack, Vultr over Coolify on RAM, database sessions over Redis — and the
Framework row carries none.

**It is an assumption that was written down once and never revisited**, most likely the
current stable release at the moment the file was drafted. `laravel new` installed 13, and
every line since has been written against it.

---

## Decision

### 1. Laravel 13 is the stack version, and `CLAUDE.md` §3 says so

The Framework row reads **Laravel 13**. `composer.json` is **unchanged** at `^13.8`; the
document moves to meet the code, not the other way round.

This is the one direction that costs nothing and asserts something true. The project already
runs on 13, is tested on 13, and has 201 passing tests on 13.

### 2. Downgrading to 12 is rejected

The alternative — pin `composer.json` to `^12.0`, reinstall, and make the code match — was
considered and rejected.

**It would carry real risk to honour an assumption with no written reason behind it.** Every
dependency would re-resolve against a different constraint set; PHP 8.5 support differs
across major versions; and this project depends on framework behaviour that a major-version
change touches directly — the session driver contract that BR-A5 and BR-A15 rest on, the
scheduler API in `routes/console.php`, `Schema::getColumns()` and `Schema::getIndexes()`
which three architecture guard tests read, and Pest 4 against a different framework major.

Each would need re-verifying. **The benefit is a document matching a sentence nobody argued
for.** Cost without benefit is not a trade-off; it is just cost.

### 3. `CLAUDE.md` is amended under its own clause, and this is a decision, not a typo fix

The file's opening paragraph says:

> If code and this file disagree, the code is wrong — fix the code, not this file, unless a
> decision has genuinely changed (in which case update this file in the same commit).

> **⚠ Emphasis removed 2026-08-14.** This quotation carried bold on *"unless a decision has
> genuinely changed"* that `CLAUDE.md` does not have. Adding emphasis to a quotation makes
> the source appear to stress the clause the quoter needs, which is the clause this ADR is
> built on. The sentence is otherwise verbatim. `conventions.md` §9.

⚠ **This ADR exists to make the exception legitimate rather than convenient.** The default is
strict and deliberately so: `CLAUDE.md` is the constitution, and a project that edits its
constitution whenever the code disagrees has no constitution. The clause is narrow, and
reaching for it silently — "just correcting a typo" — is how it would erode.

So the reasoning is stated out loud: the framework version was **never a decision**, so there
is no decision here being overridden. What is happening is a decision being **taken for the
first time**, and recorded — which is exactly what the clause contemplates. The ADR is the
evidence that it was argued rather than assumed, and it is why `CLAUDE.md` §3 changes in the
same commit as this file, as the clause requires.

If the Framework row had carried a reason, this would have been a different document with a
different answer.

---

## Consequences

**Positive**

- The constitution and the lock file agree, and a new contributor reading §3 installs what
  the project actually runs.
- The framework version now has a written reason for the first time, so the next person to
  change it has something to argue against rather than a bare number to overwrite.
- No code changes, no reinstall, no re-verification. The suite is green before and after.

**Costs and constraints accepted**

- **`CLAUDE.md` has been edited, which should always be uncomfortable.** The mitigation is
  this file: the edit is one row, argued in advance, and cited from §3 itself.
- **Laravel 13 is newer than 12**, so third-party package support is thinner and upgrade
  notes are shorter. Accepted, because the project is already there — this ADR does not
  choose 13, it records that 13 was chosen by `laravel new` and never reconsidered.
- **The same drift may exist elsewhere in §3 and has not been swept.** One instance is known
  and named above: **Livewire 3 is listed and not installed.** That is left open on purpose —
  it becomes a real decision when the account-management screen needs it, and pre-emptively
  installing a dependency to make a document true would be the wrong direction again.

**Explicitly not changed**

- `composer.json`, `composer.lock`, and every line of application code.
- The rest of `CLAUDE.md` §3, including the deployment constraints and their reasoning.
- Principle #1 and the "fix the code, not this file" default, which stands — this is the
  documented exception, not a relaxation of the rule.

---

## Follow-up

- **Sweep the rest of §3 against reality at the next natural opportunity.** Livewire is the
  known gap; Alpine.js should be checked the same way, since it is listed beside Livewire and
  arrives with it. Not urgent, and deliberately not done here — a sweep folded into this ADR
  would bury a real decision inside housekeeping.
- **When Livewire is installed, record it.** If it is never installed, §3's frontend row
  needs the same treatment this one got.

---

## References

- `CLAUDE.md` §3 — the Framework row, amended here; and the opening clause this ADR invokes
- `CLAUDE.md` Principle #1 — spec before code, and why an unargued assumption is not a spec
- `adr/0006` — the previous instance of a contradiction that no single document contained
- `docs/modules/auth-rbac.spec.md` §7 — the screen that will first need Livewire
- `composer.json`, `composer.lock` — `^13.8`, resolving 13.24.0
