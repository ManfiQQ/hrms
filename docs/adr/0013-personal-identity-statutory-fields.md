# ADR 0013 — Personal Identity and Statutory Fields on the Employee Record

- **Status:** Accepted — 2026-08-14
- **Amends:** `schema.md` § `employees` — twelve columns added; `employee_documents`
  — one type added
- **Related:** `adr/0004` decision 8 (the Personal tab), `adr/0006` (the login
  identifier lives on `users`), `adr/0003` decision 2 (reference table over
  enum), `adr/0008` decision 4 (new tables born complete), `adr/0012`
  (document handling), `conventions.md` §3 §4 §11, `CLAUDE.md` §5
- **Raised by:** UI §7 gap 4 — asking who may edit each tab exposed that the
  Personal tab has almost nothing to edit

---

## Context

**The Personal tab has no personal data behind it.** `employees` carries no
`ic_no`, no date of birth, no gender, no nationality, no address, no bank
details, and no statutory numbers. The columns that could be called personal are
`full_name`, `nickname` and `email`.

And the one argument `adr/0004` decision 8 gives for letting a supervisor read
that tab is:

> *a supervisor who cannot find a phone number in the system will find it on
> WhatsApp instead*

— while `adr/0006` moved `phone_no` off this record entirely. **The tab's stated
purpose is answered by a column that is not on the record it belongs to.**

So either the columns are missing from the schema, or the tab is something else.
This ADR settles that it is the former, and it reaches further than one tab:
**every statutory field Payroll will need is absent.** EPF, SOCSO, LHDN and the
EA Form all key on an identity number this system does not store, and payments
go to a bank account it does not record.

Discovering that in Phase 2, with Payroll half built, is the expensive version
of this conversation. `CLAUDE.md` Principle #1 exists so that the assumption is
falsified while it is still a paragraph.

**One thing this ADR does not do: it adds no salary data.** Employee Master
holds none (§10 decision 3), and `adr/0003` decision 5 restricts salary reads to
the `ACCOUNT` role. Bank details are where money is *sent*, not how much — and
that distinction is deliberate.

---

## Decision

### 1. Twelve columns are added to `employees`

| Column | Type | Null | Note |
|---|---|---|---|
| `ic_no` | string | **nullable**, unique | Malaysian identity card |
| `passport_no` | string | **nullable**, unique | Non-citizens |
| `permit_expiry` | date | nullable | See decision 4 |
| `date_of_birth` | date | **NOT NULL** | SOCSO rate changes at 60; EIS eligibility |
| `gender` | enum `MALE`/`FEMALE` | **NOT NULL** | EA Form; maternity entitlement |
| `nationality_id` | FK → `nationalities` | **NOT NULL** | See decision 2 |
| `address` | text | nullable | EA Form, statutory correspondence |
| `epf_no` | string | nullable | See decision 3 |
| `socso_no` | string | nullable | See decision 3 |
| `tax_no` | string | nullable | LHDN, for PCB and the EA Form |
| `bank_name` | string | nullable | Where salary is sent |
| `bank_account_no` | string | nullable | Where salary is sent |

**`gender` is an enum, `nationality` is not.** `conventions.md` §4 prefers a
fixed enum where the list is stable and structured data where it is not. Gender
does not grow; nationality does, every time the group hires from somewhere new,
and an enum would mean a migration each time.

**`address` is one text column, not parsed into street, city, postcode and
state.** It is written onto forms and letters as a block, never queried by
component. Structuring it would create five columns nobody filters on and four
more ways to leave it half-complete.

**No `personal_phone` column is added, and this is the important refusal.** A
contact number already exists — `users.phone_no`, which is the login username
and is a personal number precisely because most of this workforce has no company
email (`adr/0006`). Adding a second would be **two numbers for one person**: HR
updates one, login uses the other, and an employee is locked out of their own
account with nothing to notice. The Personal tab **displays `users.phone_no`
through `Employee::user()`, read-only** — edited only through account
management, which is where the login identifier lives.

That also repairs `adr/0004` decision 8's justification, which named a phone
number the record no longer held.

### 2. `ic_no` and `passport_no` are separate columns, and at least one is required

Both nullable, both unique. **A FormRequest rule requires at least one.**

**One column holding either was rejected.** An `identity_no` + `identity_type`
pair is cleaner in the abstract, but every statutory integration asks for a
number by its Malaysian name: EPF, SOCSO and the EA Form all want *No. KP*. A
column that is sometimes a passport makes each of those a conditional read
forever.

**Storing a passport number in `ic_no` was rejected outright.** With
`passport_no` also present it would be the same value in two places — the shape
this project has refused by name six times — and the unique index on a column
called `ic_no` would silently be enforcing a rule about passports.

⚠ **Cost accepted: uniqueness is per column, not across the pair.** Nothing at
the database level stops one person's `ic_no` matching another's `passport_no`.
That combination is not a real identity collision, and enforcing it would need a
constraint spanning two columns of different meaning. It is left to the
FormRequest, and it is written here so nobody reads the unique indexes as
covering more than they do.

### 3. `epf_no`, `socso_no` and `tax_no` are nullable, and that is a fact, not a compromise

**Probationers, contract staff and interns do not have EPF or SOCSO numbers**
until they qualify. A record without one is **correct**, not incomplete.

Making them `NOT NULL` would produce invented numbers for every intern — the
same failure that banned a placeholder phone number (BR-A1) and made
`employee_family_members.contact_no` nullable: **a fabricated number fills the
field without filling the fact, and only an empty one is visibly empty.**

The numbers exist today, held by HR outside the old system, and will be entered
manually. **This is not an import blocker** — it is data entry after the system
is live.

### 4. `permit_expiry` is nullable, and an expired permit blocks nothing

A non-citizen may have no permit recorded — the permit may be in process, or the
person may hold a different pass. **Requiring the date would produce a
fabricated one**, and an invented date in a permit field is worse than an empty
one for the same reason it is worse in a phone field.

**An expired permit does not stop anyone working, and it does not stop the
record being used.** It raises a flag on the record and, once the Notification
Engine exists, notifies HR and the employee. Renewal is the response, not
suspension.

⚠ **The flag only covers records that carry a date.** A non-citizen with
`permit_expiry` empty is never flagged — the system knows nothing about their
permit and does not pretend to. That is the direct cost of decision 4's
nullability, and it is stated so nobody reads the flag as covering everyone.

### 5. A `CONFIRMED` employee without EPF or SOCSO is flagged, never blocked

Registration with EPF and SOCSO takes time — a month or two is normal. **Payroll
must not be blocked while it happens**, because blocking payroll over paperwork
means an employee is not paid.

So: a flag on the record, visible to HR. No gate on `ChangeEmployeeStatus`, no
gate on payroll.

**Refusing confirmation until the numbers exist was rejected.** It would assume
every confirmed employee must hold both, which this group's own practice
contradicts, and it would make a paperwork delay into a status the record cannot
leave.

**⚠ Contributions accrue while the numbers are missing, and settling them is
Payroll's problem, not this module's.** When the numbers arrive in March, the
contributions for January and February are owed and must be paid retroactively.
That requires knowing which months, how much, and when it was settled — figures
Employee Master does not hold (§10 decision 3). **Recorded here so Payroll
inherits it as a written requirement rather than discovering it on a payday.**

### 6. `nationalities` is a reference table, group-wide, editable by HR

A new table: `name` (unique), `created_by`, `updated_by`, timestamps, soft
deletes. **No `company_id`** — one vocabulary for the whole group, the same
reasoning as `job_functions` (`adr/0003` decision 2). Born complete, per
`adr/0008` decision 4.

Starting set, ten values:

```
Malaysia · Indonesia · Bangladesh · Myanmar · Nepal
India · Pakistan · Vietnam · Philippines · Thailand
```


**⚠ HR may create new entries, and this deliberately differs from
`job_functions`, which only Master Admin may extend.** That restriction exists
because a per-account vocabulary is what stops one thing acquiring three
spellings (`CLAUDE.md` §5). Loosening it here has a reason and a cost, and both
belong on the record:

- **The reason:** HR meets a new nationality while registering an employee, and
  a hiring that stalls until Master Admin acts is a rule that gets worked around.
- **The cost:** the structural guarantee is gone. A unique index stops
  `Bangladesh` twice; it does not stop `Myanmar` and `Burma` coexisting.
- **The mitigation, which is not a guarantee:** the picker autocompletes as HR
  types, so an existing entry surfaces before a second is created. That reduces
  the chance. It does not remove it, and this ADR does not claim otherwise.

**Free text was rejected** — it is exactly the legacy failure `CLAUDE.md` §5
records, where one company was spelled three ways across three files.

### 7. `PHOTO` joins the document type enum

An employee photo is a **file**, and `adr/0012` already decides everything about
how files in this system are stored, served, replaced, deleted and audited.

**A `photo_path` column on `employees` was rejected.** It would be a second file
path in the system governed by none of those rules: no write-once lock, no
policy, no audit trail, no defined disk.

`PHOTO` is **readable by the employee**, joining the six types they may already
retrieve (`adr/0004` decision 9). `OTHER` remains the only type hidden from
them, which is what gives it its defined purpose.

---

## Consequences

**Accepted**

- Twelve columns land on `employees` and a new `nationalities` table with its
  model, factory, seeder and policy abilities.
- **HR carries a data-entry burden this ADR creates.** EPF, SOCSO and tax
  numbers exist outside the old system and must be typed in per employee. The
  alternative was inventing them.
- Two flags must be rendered on the employee record — expired permit, and
  `CONFIRMED` without EPF or SOCSO. Both are display rules with no enforcement
  behind them, deliberately.
- Two more events are emitted with no listener, joining `AccountFrozen` and
  `AccountActivated` in waiting for the Notification Engine. **Until it exists,
  both flags are seen only by someone looking at the record.**
- `nationalities` is the first vocabulary in this project that HR may extend,
  and its spelling guarantee is weaker than `job_functions`'. Decision 6 states
  what is given up.
- The legacy import gains new required fields. `date_of_birth`, `gender` and
  `nationality_id` are `NOT NULL`, so any imported row missing them fails —
  which belongs with the import blockers already recorded in `CLAUDE.md` §10.

**Not changed**

- `users.phone_no` remains the single contact number and the login username.
  This ADR adds a display, not a column (`adr/0006`).
- Employee Master still holds no salary data. Bank details record where money
  is sent, never how much (§10 decision 3, `adr/0003` decision 5).
- The eight tabs and who may read them. `adr/0004` decision 8 and §6.2 are
  untouched — this ADR gives the Personal tab something to show, not a new
  audience.
- `adr/0012` in every respect. It decides how document bytes are
  handled; this ADR only adds a type to the enum it governs.
