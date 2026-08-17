# ADR 0014 — The Personal Tab Is Tiered by Field, Not by Tab

- **Status:** Accepted — 2026-08-17
- **Amends:** `employee-master.spec.md` §6.2 — its matrix answers Yes/No per tab,
  and the Personal row is now Yes-with-four-fields for the supervisory tier;
  `adr/0004` decision 8 — the same row, in the table this project reads first.
  Each statement is left standing under its own dated pointer
- **Related:** `adr/0013` decision 1 (what put the twelve columns behind this
  tab) and its 2026-08-15 amendment item 1 (the two deferred flags), `adr/0006`
  (the number lives on `users`), `adr/0011` (the reporting-line bound this sits
  on top of), `conventions.md` §9
- **Raised by:** reading §7 for the detail screen — the tab matrix predates the
  columns it now governs, and nothing had re-read the two together

---

## Context

Three facts, none of them wrong, and the contradiction is what they make together.

**`adr/0004` decision 8 gave supervisors the Personal tab on one written argument:**

> *A supervisor needs to know who reports to me and how do I reach them.*

It considered Employment-only and rejected it as too tight, because *a supervisor
who cannot find a phone number in the system will find it on WhatsApp instead*.

**When that was written, the Personal tab held `full_name`, `nickname` and
`email`.** `adr/0013` says so in its own Context: *"The Personal tab has no
personal data behind it."*

**`adr/0013` then put twelve columns behind that tab** — IC, passport, date of
birth, address, bank account, three statutory numbers — and deliberately refused
to reopen who reads it:

> *`adr/0004` decision 8 and §6.2 are untouched — this ADR gives the Personal tab
> something to show, not a new audience.*

That was correct as scope. **The effect is that the tab now contains exactly what
the argument said supervisors do not need.** §6.2's own words: *they do not need
a copy of someone's IC, their spouse's identity card number, or where they went
to school.*

No document is wrong and no decision was careless. **The authorisation argument
and the data it protects stopped matching**, and nothing re-read the two together
until the detail screen was specified. That is the shape `adr/0006` and `adr/0007`
both record: every sentence true on its own, the combination false.

**This has never been an exposure.** The detail screen does not exist, so no
supervisor has read anybody's IC. It is being corrected before the first line
rather than after a leak — which is the only reason it is cheap.

---

## Decision

### 1. The Personal tab is tiered by field

**The supervisory tier — `SUPERVISOR`, `MANAGER`, `HOD` — reads four fields:**
`full_name`, `nickname`, `email`, and `users.phone_no` (read-only, through
`Employee::user()`, `adr/0013` decision 1).

**Everyone else who could open the tab reads all of it:** the administrative
tier (`HR`, `ASSISTANT_DIRECTOR`, `ACCOUNT`), `system_access` `FULL` and
`VIEW_ONLY`, and the employee on their own record.

**Withheld from the supervisory tier, by name:** `ic_no`, `passport_no`,
`permit_expiry`, `date_of_birth`, `gender`, `nationality_id`, `address`,
`epf_no`, `socso_no`, `tax_no`, `bank_name`, `bank_account_no`.

**Those four fields are §6.2's question answered exactly.** *How do I reach
them* is a name and a way to contact it. Nothing else on the tab answers it.

**⚠ `nationality` and `gender` fall on the administrative side, and this is the
paragraph most worth writing.** Neither looks dangerous, and that is precisely
why they are the test. Including a field because it *seems harmless* means the
line is no longer the written argument — it is an estimate, and an estimate
cannot be defended on the next field. The rule is not *what would be bad to
show*; it is *what does the supervisor's stated need require*. These two do not
meet it.

**This is not a demotion.** No supervisor loses anything they ever had: the
matrix granting them the tab was written when the tab held three columns, and
those three are still theirs. The narrowing exists only relative to columns that
arrived three days ago and have never been rendered.

**The administrative tier is unchanged, and so are the other seven tabs.**

**Two edge cases, stated because they are silently wrong otherwise:**

- **The employee's own record wins first.** `viewTab()` already returns true for
  the actor's own record before any role check, and the field set follows the
  same order — an employee reads their own IC regardless of what role they hold.
- **A supervisor who also holds an administrative role resolves administrative
  first**, in the same order `viewTab()` uses. Holding both is not holding the
  lesser one.

### 2. A second method, and the boolean is derived from it

`EmployeePolicy::viewTab()` keeps returning `bool`. A second method,
`EmployeePolicy::personalFieldsFor($actor, $employee): array`, returns the field
list.

**Changing the return shape of all eight tabs because one needs it is a cost
with no return.** `viewTab()` is tested, called, and `adr/0011` has just made it
half of the list's agreement guard (`EmployeeListVisibilityTest`) — changing its
shape changes that guard too. The other seven tabs are genuine yes/no questions,
and a shape that forced them all to return a list would tell a small lie seven
times in order to tell the truth once.

**⚠ `viewTab(TAB_PERSONAL)` is DERIVED from the field list**, defined as
`personalFieldsFor(...) !== []`. It is not a second implementation of the same
rule.

Two copies of the tiering logic would drift, and neither copy would look wrong
on its own — the failure is invisible by construction. This is the same
reasoning that made `SUPERVISORY_ROLES` public in `adr/0011`: **the list scope
and the policy read one set, because two sets that disagree about a role nobody
holds today agree forever and part company the day it is granted.**

It also closes half of the limit below: `viewTab()` can no longer answer *yes*
for a tier whose field list is empty.

**⚠ What this does NOT close, recorded rather than left implicit.** A caller can
still load an `Employee` and read `$employee->ic_no` without asking the policy
anything. The screens are guarded; the model is not. A future export, letter
generator or API endpoint would bypass this entirely, and **nothing at the policy
layer can prevent that** — a model that answered differently depending on who
asked would break Actions, seeders and the importer, and make the record
untrustworthy everywhere.

That is the family `conventions.md` §9 already records twice — *an ability nobody
calls*, and *a comment can cite a protection that does not exist*. **It has not
happened, and it is not recorded in §9 for that reason:** §9 holds things that
occurred and taught something. This is a known limit of a design, written where
the design is.

---

## Implementation context — settled here, built with UI-2

### The two `adr/0013` flags live in a model accessor

**The two `adr/0013` flags live on the model.** Its 2026-08-15 amendment deferred
the choice — accessor, service, or view composer — to the screen that would show
them, and this is that screen. They are accessors: `Employee::hasTerminalStatus()`
is the same shape and the same size, and `conventions.md` §1 admits short
predicates on a model. A view composer is rejected twice over — it has no
precedent here, and something that will emit an event once the Notification
Engine exists does not belong in a presentation layer.

### Both flags render on the Employment tab

**Both flags render on the Employment tab, not Personal**, and decision 1 is why.
After the tiering, a supervisor no longer sees `epf_no`, `socso_no` or
`permit_expiry` — so a flag placed on Personal would be invisible to the tier
most likely to act on it. Employment is read by everybody who can open the record.

> **⚠ This means a supervisor sees *"this employee has no EPF number"*, and that
> is accepted deliberately.** It is an administrative fact about a record, not
> the number itself; the tiering withholds data, not the existence of a gap. A
> flag nobody can see is not a flag.

### UI-2 is read-only, and it has seven tabs

**UI-2 is read-only, and it carries seven tabs.** No grant or revoke controls —
§7 lists the create/edit form and the archive confirmation as separate screens,
and write controls land with UI-3. The Documents tab renders an honest statement
that the document path is not built rather than a list of files nothing can open;
`adr/0012` decision 11 binds the serving path to the PR that builds that tab, and
that PR is not this one. **No `PHOTO` is rendered anywhere, including Personal** —
a photo is a file (`adr/0013` decision 7), and displaying one pulls in the whole
serving path.

---

## Consequences

**Accepted**

- **Supervisors stop seeing IC numbers, home addresses and bank account numbers
  they were never argued into seeing.** The narrowing is deliberate, and it costs
  nobody anything they had — it was never shipped.
- `personalFieldsFor()` is the first policy method in this system that returns
  something other than `bool`. Every other ability answers yes or no, and this
  one is the exception rather than a new convention.
- **The screens are guarded; the model is not** (decision 2). A future export or
  letter generator reading `$employee->ic_no` bypasses this entirely. **It has
  not happened**, and it is written here rather than in `conventions.md` §9
  because §9 records what occurred — this is a limit known from the first day.
- The Employment tab now shows administrative gaps to the supervisory tier. See
  the note above; it was chosen, not overlooked.
- **No code has changed yet.** `EmployeePolicy::SUPERVISORY_TABS` and its docblock
  still say two tabs without qualification until the UI-2 PR, and this ADR's
  pointers are what keep that from reading as current.

**Not changed**

- The other seven tabs, and who may open them. This ADR amends the Personal row
  and the "Why Employment and Personal" paragraph in BOTH `adr/0004` decision 8
  and §6.2 — four locations, and nothing else in either document.
- The administrative tier, at any scope.
- `adr/0011`'s reporting-line bound. **Which employees** a supervisor may open is
  unchanged — this decides what they see once inside.
- `adr/0006`. The number still lives on `users`; the Personal tab displays it and
  never copies it.
- No columns, no migration, no `schema.md` change. This ADR moves nothing and
  stores nothing.
- **Write permissions.** UI-2 is read-only, so this tiering is a read rule and
  must not be read as an editing one — who may edit these fields is §6's
  create/edit row, untouched.
