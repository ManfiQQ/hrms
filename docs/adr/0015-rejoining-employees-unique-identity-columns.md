# ADR 0015 — Rejoining Employees and the Unique Identity Columns

- **Status:** Accepted — 2026-08-17
- **Amends:** `schema.md` — the unique indexes on `employees.ic_no`,
  `employees.passport_no`, `employees.fingerprint_id` and `users.phone_no`
- **Related:** `adr/0003` decision 9 (a rejoiner gets a new record),
  `adr/0013` decisions 1–2 (the identity columns), `adr/0006` (the number lives
  on `users`), BR-2, BR-13, BR-A1, BR-A18, BR-A20
- **Raised by:** the two dated notes recording that a rejoining employee cannot
  be registered at all — one in `schema.md`, one at BR-A18

---

## Context

**`adr/0003` decision 9 designed the rejoining flow, and nothing can execute it.**

A returning employee gets a **new** employee record with a **new** `employee_no`,
linked to the old one through `previous_employee_id`. That is deliberate: the
break in service is what decides leave entitlement, and BR-2 makes a terminal
status terminal precisely so the two employments stay distinct.

**Then `adr/0013` made `ic_no` and `passport_no` unique**, and `users.phone_no`
has been unique and NOT NULL since `adr/0006` because it is the login username.

**A person has one IC and one phone number.** The new record carries the same
values as the old one, and the old rows still hold them — an employee record is
soft-deleted at most, and `User` has no soft deletes at all: a terminal status
freezes the account and expires it (BR-A15, BR-A17) without removing the row.

So the flow fails twice:

- `employees.ic_no` — refused by the FormRequest as a duplicate, or by the index
  as a raw constraint violation on any path that skips validation
- `users.phone_no` — refused **inside `CreateEmployee`'s transaction**, as a raw
  1062, because nothing validates it in advance: no `unique:users,phone_no` rule
  exists anywhere in `app/`

**Every escape is closed by a rule that is correct on its own.** A second number
is refused (`adr/0006` decision 7). A placeholder is refused (BR-A1).
Reactivating the old account is refused (BR-A18). Creating the employee without
an account is refused (BR-A20). Deleting the old row would destroy the audit
trail that the freeze and the expiry exist to preserve.

**It has never been hit.** No registration screen exists, so no HR user has met
it. This is corrected before the first attempt rather than after — which is the
only reason the correction is cheap.

**⚠ And the correction has a deadline that is not ours.** The legacy import
(§5.5) is blocked on client answers, not on us. The moment it runs, records
arrive carrying IC numbers of people who have worked here before — and the
decision below stops being a schema change and becomes a schema change plus a
data question.

---

## Decisions

### 1. The rejoining design stands unchanged

A rejoiner gets a **new** employee record, a **new** `employee_no`, a **new**
account, and `previous_employee_id` pointing at the old record. `adr/0003`
decision 9, BR-2, BR-13 and BR-A18 are **not** reversed.

**Reactivating the old record was considered and rejected**, and the reason is
not tidiness. The old record carries `join_date` from the first employment, so
annual leave would accrue from a date six years before the person returned —
BR-2 makes the break terminal precisely so that cannot happen. The old account
carries a password the person set years ago and has not used since; reactivation
would restore working credentials rather than issue new ones (BR-A21).

**What changes is the schema, not the design.** The design was right and the
indexes could not express it.

### 2. `superseded_at` on `employees` and `users`

A nullable timestamp on both tables. **NULL means this row still holds its
identity values. A date means it has been replaced by a later record.**

It records what actually happened — *this row was superseded* — rather than
being derived from expiry or from a terminal status. Both of those are
computed: `AccountExpiry` reads the most recent terminal ledger row and adds a
window, and an index cannot compute anything. It needs a value it can read off
the row.

**It is not a soft delete and must not be read as one.** A superseded record is
fully present, fully readable, and still the answer to every question about the
employment it describes. Only its claim on the identity values is released.

### 3. The unique indexes become functional indexes

The four unique indexes are rebuilt as functional indexes over an
expression, so that a superseded row stops competing for the value it
holds without giving that value up.

```
UNIQUE ((IF(superseded_at IS NULL, ic_no, NULL)))
UNIQUE ((IF(superseded_at IS NULL, passport_no, NULL)))
UNIQUE ((IF(superseded_at IS NULL, fingerprint_id, NULL)))
UNIQUE ((IF(superseded_at IS NULL, phone_no, NULL)))
```

**MySQL treats every NULL in a unique index as distinct from every other**, so
superseded rows stop competing while live rows are constrained exactly as
before. **Nothing is emptied and nothing is deleted** — the values stay on the
old rows, the audit trail stays intact, and the old record remains findable by
the IC it was registered under.

**⚠ An index only constrains values that are stored identically, so the storage
form is part of this decision rather than a later detail.** `ic_no` and
`passport_no` are stored as **digits and letters only — no dashes, no spaces**.
`900101-14-5501` and `900101145501` are two strings to any index and one person;
a unique index cannot see that, and neither can the prior-record search in
decision 5.

That pairing is what makes the failure worse than a missed match. The search
would report *this person has never worked here*, and the index would refuse the
IC as already taken — **two answers, both wrong, and HR with no way to tell which
to believe.**

**The registration form renders the separators as its own boxes — six, two,
four** — so the separator is never typed, and only one form can arrive through
the form at all. **The legacy import must send that same form.**

**⚠ This closes the form path and not the import path, and that gap is the whole
of what stays open.** An import does not go through the form, and **the legacy
file has never been seen**: its source format, column mapping and table scope are
all unknown (§5.5, `CLAUDE.md` §10 question (d)). Deciding a normalisation rule
against an assumed shape is the NGTime pattern this project has refused by name.

So the normalisation question in the Consequences below stays open **for that
path specifically**. A rule constrains what arrives next; a normaliser rewrites
what is already stored, and only the second reaches rows an import wrote.

**Two alternatives were tested and rejected**, on MySQL 8.4 on this project
rather than from recollection:

- **A partial index** — `UNIQUE (ic_no) WHERE superseded_at IS NULL` — is a
  syntax error. MySQL has no filtered indexes; that is PostgreSQL.
- **A composite** — `UNIQUE (ic_no, superseded_at)` — is created successfully
  and **removes the constraint entirely**. Two live rows with the same IC and
  NULL `superseded_at` are both accepted, because the NULLs are distinct. It
  reads like a narrowing and is a cancellation.

**⚠ `fingerprint_id` is included, and it is not an identity column.** It is a
device id typed in from NGTime, and it is unique for a different reason — but a
rejoiner reusing a device id hits the same wall, so it takes the same shape.

**⚠ Making these functional indexes means dropping and recreating four unique
indexes.** `conventions.md` §11's window is open — no production, no real data,
one developer — but this is a forward migration regardless: the columns are not
being corrected, the constraint is being redefined.

### 4. Set automatically, inside `CreateEmployee`'s transaction

When `previous_employee_id` is supplied, `CreateEmployee` marks the prior record
and its account superseded **before** writing the new rows, in the transaction
that already exists.

**Order is load-bearing.** The new `users` row cannot be written while the old
one still binds the number, so the mark must land first or the insert fails
exactly as it does today.

**No separate HR action.** A button would open a window in which the new record
exists and the number is still bound, and it would put the burden of remembering
on the person least able to see the consequence.

**⚠ Nothing else may set this column.** It is written by one Action, on one
path, and a second writer would be a second definition of what "superseded"
means.

### 5. The registration form asks before it searches

A checkbox — *"this employee has worked here before"* — then a search over prior
records to select the one being linked.

**Without the checkbox, a duplicate IC is refused**, and that refusal is the
protection against a genuine duplicate: two records for one person created by
accident, which is what the unique index is for in the first place.

**⚠ That search reads records the employee list cannot show.** A prior record
may be soft-deleted, and `Employee` carries `TenantScope` — so the search needs
a scope the list does not have, and building it is part of the registration
screen rather than a detail of it.

### 6. A guard asserts that no live row is superseded

`superseded_at` set on a row that is not terminal releases an identity value
while the account still logs in — two live accounts could then share one
username, which is the failure `users.phone_no` being unique exists to prevent.

The guard is over the data, not over the caller: **no `users` row may carry
`superseded_at` while its employee holds a non-terminal `staff_status`.**

---

## Consequences

**Accepted**

- **Two migrations, dropping and recreating four unique indexes**, plus a
  nullable timestamp on two tables.
- **Archived-record search does not exist.** The employee list cannot show a
  soft-deleted row and cannot cross a tenant boundary, so decision 5's search is
  a capability the registration screen has to build rather than call.
- **The functional index is harder to read than a plain one.** Somebody looking
  at the schema meets `IF(superseded_at IS NULL, ic_no, NULL)` and has to work
  out why. The reason is here; the index cannot carry it.
- **`superseded_at` is a fourth state a row can be in**, beside live,
  soft-deleted and terminal. They overlap and are not the same: a superseded
  record is normally also terminal, but a terminal record is not superseded
  until somebody rejoins.
- **A wrongly-set `superseded_at` frees an identity value silently.** Nothing
  errors — two live accounts simply become able to share a username. Decision
  6's guard exists for that and nothing else.

**Not changed**

- **`AccountExpiry`.** A superseded account was already expired; the column
  changes what the index sees, not what the middleware asks.
- **BR-A1, BR-A20, BR-A18, BR-2, BR-13, `adr/0003` decision 9.** Every one of
  them stands exactly as written — this ADR makes the flow they describe
  executable rather than altering it.
- **`adr/0006`.** The number still lives on `users`, and there is still one of
  them per person.
- **Nothing is deleted, and nothing is emptied.** The old record keeps its IC,
  the old account keeps its number, and the audit trail keeps its subject.
- Normalisation stays a separate question. IC is stored digits-only
  with no dashes, and that is its own ADR.

---

## Amendment — 2026-08-17, with the implementation

Built by `2026_08_17_100000` (employees), `2026_08_17_100100` (users), and the release logic in
`CreateEmployee::supersedePrior()`. Twenty-six tests. **A rejoining employee can be registered,
end to end, carrying the same IC and the same phone number.**

Four things are recorded here because they changed, sharpened or were discovered during the
build — not to restate what the decisions already say.

### 1. Decision 6 is now an explicit caller rule, not only a rule over the data

Decision 6 states the invariant over the rows: **no `users` row may carry `superseded_at` while
its employee holds a non-terminal `staff_status`.** It said nothing about who must refuse to
create that state, and the difference matters.

**A guard over data alone would have documented the failure rather than prevented it.** It runs
over rows that already exist; nothing stopped the front door producing them. `conventions.md` §9
is explicit that a guard asserting something the code cannot produce is a guard pointed at
nothing — and the inverse is worse: a guard asserting something the code *can* produce, with no
gate in front of it, reports the breach after the username has already been released.

So **`CreateEmployee` refuses a non-terminal predecessor**, and the refusal is part of this ADR
rather than an implementation detail of it. It also enforces BR-2 at the one place it bites here:
a prior record still `ACTIVE` is not a rejoin, it is a duplicate person — the exact thing the
unique index has always existed to catch. If it is genuinely the same unbroken employment, it is
a transfer (§5.7), not a rejoin.

Both layers exist and they are not duplicates: the Action stops the state being created, the data
guard asserts no other path created it. Removing the Action's check was run as a deliberate break
and **the data guard's own test went red on a real violation** — `superseded_at` set on a live
record — which is what proves it prevents rather than describes.

### 2. The already-superseded record keeps its original timestamp

Decision 4 says the prior record is marked; it does not say what happens when that record is
**already** marked, which occurs the moment somebody claims a predecessor a third employment has
already superseded.

**It is left exactly as it is.** Whether two records may claim one predecessor is undecided —
nothing makes `previous_employee_id` unique, and `EmployeeStoreRequest` says so in as many words.
Overwriting the timestamp would answer that question silently **and** destroy the date of the
first supersession, which is the older fact. Leaving it alone answers nothing and loses nothing.

### 3. The composite is wrong in BOTH directions, not just one

Decision 3 records that `UNIQUE (ic_no, superseded_at)` cancels the constraint: two live rows
both carry NULL, NULLs are distinct, both are accepted. Running it as a deliberate break
confirmed that — and surfaced a second defect the decision did not anticipate.

It **also refuses two legitimately superseded rows** marked within the same second, failing on a
composite key of the value and the timestamp. Somebody who leaves and returns twice has three
records; under the composite, two of those supersessions colliding on a shared timestamp is a
registration that dies for no reason a reader could diagnose.

**So the composite is not a weaker version of the functional index. It is wrong at both ends**,
and only one of those ends was argued when it was rejected.

### 4. What this ADR decided and this PR did NOT build

- **The registration form.** Decision 5's checkbox and prior-record search do not exist. ⚠ **The
  constraint is fixed and the workflow is not** — the rejoiner path is reachable only through
  `CreateEmployee` directly, so no HR user can yet register a rejoiner through a screen.
- **The archived-record search itself**, which decision 5 already named as a capability the
  screen must build rather than call. A prior record is routinely soft-deleted and may sit under
  a former employer, so it needs a scope the employee list deliberately does not have.
- **Normalisation.** Decision 3 closes the **form** path: `ic_no` is validated at 12 digits,
  `passport_no` at letters and digits, both separator-free. It does not touch the **import**
  path, which does not come through a form and whose file has never been seen (§5.5, `CLAUDE.md`
  §10 question (d)). ⚠ **That path is now the whole of the exposure**, and it is the one that
  writes rows in bulk. Recorded in `conventions.md` §9.

⚠ **One thing the build revealed about the legacy importer**, since it will meet all of this at
once: the authorship observer (`adr/0009`) refuses a write with no actor, and releasing an
identity claim is a write. **An importer that supersedes anything must enter
`AuthorshipContext`**, naming the acting user and a reason. Discovered by a probe failing on it,
not by reading the code.

---

## Amendment — 2026-08-17, decision 5's search as built

Decision 5 said the registration form asks *"has this employee worked here before?"* and then
searches prior records. It did not say what the search returns, and the difference between the
two available shapes is large enough that it is recorded here rather than left to the screen.

**It returns an ANSWER, not a browsable set.** `App\Services\Employee\PriorEmploymentLookup`
takes one identifier and returns one `PriorEmployment` or null. There is no name search, no
`LIKE`, no list and no pagination.

### What it returns

`employeeId`, `fullName`, `employeeNo`, `companyName`, `servedFrom`, `servedTo`. Six fields, and
each is there for a stated reason: the id is what `previous_employee_id` is set to; the name is
the only guard against a mistyped identifier landing on somebody else's record; the dates carry
the disambiguation load when several prior records match.

**⚠ `companyName` is returned deliberately, and removing it was argued and withdrawn on
2026-08-17. Do not remove it on a privacy argument.** The case for removing it was that a
subsidiary-employed `HR` reads one company only (`adr/0004` decision 1), so naming AIM to a
TURSENIA-employed reader discloses group structure across a tenant boundary.

It stays because **linking is an act, not a read.** Setting `previous_employee_id` fixes prior
service across employers — what a Leave spec will later compute entitlement from (BR-13) — and an
HR who links an AIM record without being shown "AIM" is performing a cross-company act blind.
Hiding the employer hides what they are doing rather than protecting anything, and the six
companies are not a secret: `CLAUDE.md` §5 lists them and the employee list renders them in its
own filter.

**What it does NOT return:** date of birth, IC, passport, address, bank details, statutory
numbers, department, position, level. A caller needing those is reading a record, not asking this
question, and that goes through `EmployeePolicy`.

### Why exact match only

A fuzzy or listable version turns an existence check into an identity oracle over every archived
employee in the group. **What keeps the narrow shape safe is who may call it, not what it
returns** — there is deliberately **no HTTP route**, and it is invoked from the registration
component behind the same `create` gate the form is behind, re-checked on every call because
every Livewire action is its own request.

**Three keys, not one.** `ic_no` alone would be wrong for this workforce: it is nullable, and a
non-citizen holds `passport_no` instead. `users.phone_no` is included because it is the only one
of the three that is NOT NULL.

**⚠ A blank identifier throws rather than answering null.** Measured: `where('ic_no', null)` does
not compile to `ic_no = NULL` — Laravel compiles it to `IS NULL`, which matches **every
passport-only employee**, and a form posting an untouched box sends exactly that null. Observed
directly with the guards removed: the lookup returned a stranger's record.

### ⚠ This is not a general read scope

It releases `TenantScope` and soft deletes for **one exact-match query returning six fields**. It
widens nothing else, and `EmployeePolicy` is untouched by it. An archived-record BROWSE — the
wide shape decision 5 could have been read as requiring — is not built and is not authorised by
this amendment.

### The rule the FormRequest was missing

Building the form exposed something neither this ADR nor `EmployeeStoreRequest` had noticed:
**the rejoining flow could not pass validation at all.** The `unique` rules are scoped to
`superseded_at IS NULL`, and `CreateEmployee::supersedePrior()` releases the old claim as its
first act *inside* the transaction — so at validation time the prior record still looks live and
the rule refuses the rejoiner their own IC and their own number.

The flow was reachable through the Action directly, which is why `RejoinerIdentityTest` passed.
It was refused by the FormRequest, which was written before any form existed to submit through
it. Both requests now exclude the declared prior record from the live-row check.
