# ADR 0010 — A Company Transfer Is a Ledger Event

- **Status:** Accepted — 2026-08-13
- **Supersedes:** nothing
- **Amends:** `employee-master.spec.md` §5.7 and BR-17 — both currently state that a transfer
  *"must not touch `employee_status_history`"*, and both are now wrong for two independent
  reasons
- **Related:** `adr/0002` (shared org structure), `adr/0003` decision 7 (three cascade
  categories) and decision 8 (role history lives in the pivot), `conventions.md` §2 §4 §9,
  `employee-master.spec.md` §5.3 §5.7 §7, BR-17
- **Raised by:** the attempt to implement `TransferCompany`, which found that §5.7 forbids by
  name the thing the module needs

---

## Context

`employees.company_id` is **the only mutable column on `employees` with no history**, and it
is the most statutorily loaded of them: it decides which legal entity is responsible for an
employee's EPF, SOCSO and EA Form. §5.7 itself requires that *"the record must show which
entity was responsible **from which date**"* — and nothing in the system answers that today
except reconstructing it from audit rows.

§5.7 nonetheless says, by name:

> Writing a `STAFF_STATUS`-style history row for the transfer itself is **not** in the current
> `change_type` set; if the client wants transfers on the timeline, that is a new enum value
> and an ADR, not an improvisation here.

This is that ADR.

**A second, independent conflict was found at the same time.** §5.3 requires *every* change to
`department_id` to write a ledger row in the same transaction, and a transfer must set a new
department — the department carries approval routing per (department, company), so after a
transfer the old department's HOD can no longer approve for this person
(`conventions.md` §2: an HOD approves only within their own `employees.company_id`). So a
transfer writes a `DEPARTMENT` row whether or not it writes a transfer row. **§5.7's
"must not touch" was already unachievable**, by a route that has nothing to do with decision 1
below.

---

## Decision

### 1. `EMPLOYER` is the fifth `change_type`

The set becomes **five**:

```
STAFF_STATUS · POSITION · DEPARTMENT · LEVEL · EMPLOYER
```

| Column | Value |
|---|---|
| `old_value` / `new_value` | the old and new `companies.id` |
| `old_label` / `new_label` | the company **`code`** at the time — `AIM` → `TURSENIA` |

**Named for the field, not the event.** The four existing values name what changed
(`STAFF_STATUS`, `POSITION`, `DEPARTMENT`, `LEVEL`), and `employees.company_id` means *"the
payroll and legal employer — that meaning only"* (`schema.md`). `COMPANY` was rejected because
a row reading `change_type = COMPANY` with its own `company_id` column beside it uses one word
for two different things on one row. `COMPANY_TRANSFER` was rejected for naming the event and
breaking the convention the other four keep.

> **⚠ The label is the code, and the trade-off is real.** `old_label` / `new_label` are a
> snapshot of **display text**, and the §7 timeline already renders companies as codes
> (*"Role → Manager (AIM)"*). But an EA Form carries the **registered name**, not the code, so
> a reader using this row for a statutory question gets the short form. That is accepted
> because `old_value` holds the id, which resolves to the full row including the registered
> name as it is today — and the label exists to stop a **renamed** company rewriting history,
> which the code serves as well as the name.

**This is not the duplication `adr/0003` decision 8 forbids.** `audit_logs` will also record
the transfer, and that is the same relationship `staff_status` already has: the ledger answers
*what was the value on that date*, the audit answers *who changed it and why*. Two different
facts about one event — which is exactly why `ChangeEmployeeStatus` writes both today.

### 2. The surviving roles and job functions are **shown**, not stored

**⚠ This decision reverses the instruction that started it, and the reversal is recorded here
rather than smoothed over.**

The original instruction was: *the transfer writes one `employee_status_history` row listing
every role and job function that remains, with its company.* The reasoning behind it stands
and is not in question — **authority that persists after the employment relationship changes
is a silent bug, and the system must not guess HR's intent, but must not stay silent either.**

Three things overturned the mechanism:

1. **The instruction's own words were about seeing, not storing:** *"HR sees what remains when
   they open the history tab."* Seeing is a read-side requirement.
2. **The data already exists, fully dated.** *Which roles were live at time T* is
   `effective_date <= T AND (revoked_date IS NULL OR revoked_date > T)` against
   `employee_roles`. Storing a snapshot stores a **derivable** fact — the duplication this
   project has rejected for `is_active`, `uploaded_by`, `is_enabled`, `secondary_company_id`,
   `primary_role` and `hr_scope`.
3. **The ledger row has nowhere to put a list.** Every column is scalar, so the list would be
   packed into `new_label` or `reason` as text — `"ISNIN - SABTU"` again, the unstructured
   storage `conventions.md` §4 exists to prevent and `CLAUDE.md` §9 records as a legacy defect.

A separate snapshot table was also rejected, for reason 2 alone: it is well-shaped and stores
something already answerable.

> **A decision recorded together with why the alternative was refused is not re-litigated. One
> that is not, is.** That is why this section keeps the original instruction rather than
> presenting the outcome as though it had been the first idea.

#### 2a. The confirmation is part of this decision, not an implementation note

**`TransferCompany` returns the set of roles and job functions that will survive the transfer,
and the screen shows them to HR before they confirm.**

The concern is answered **at the point of decision**, not in a record read afterwards. HR
seeing *"this transfer leaves 2 roles and 1 job function in place at AIM"* while they can still
change their mind is worth more than any row written after the fact — and it needs no new
storage at all.

Where a durable record of what HR was shown is wanted, it belongs in the **`audit_logs` row
the transfer already writes**, which already carries `reason`. One row, already mandatory.

> **⚠ §7's UI does not exist yet, so this half is a CONTRACT, not a completed feature.** The
> Action fulfils it from day one by returning the set. The screen, when it is written, **must
> render it before the confirm button.**
>
> **If the UI ignores the returned set, decision 2 becomes a hole**: the snapshot was refused
> on the ground that HR would see the same information at a better moment, and if HR never
> sees it, nothing was traded — something was simply dropped. Written down here so that
> failure cannot happen quietly.

### 3. Both rows freeze to the **new** company

`employee_status_history.company_id` is a frozen event fact — the employer at the moment the
event happened. A transfer changes the employer *within* the transaction that writes these
rows, so "the moment" is ambiguous. It resolves to the **new** company, for the transfer row
and for the `DEPARTMENT` row alike.

| | Frozen to **old** | Frozen to **new** ✅ |
|---|---|---|
| Old employer's reporting | Counts a department move for someone who stopped being theirs in the same instant — **a wrong number that looks right** | Ends at the last pre-transfer event |
| New employer's reporting | **No record of the arrival at all** — the person appears from nowhere | Opens with the row that explains itself |

BR-17's literal phrasing (*"the employer at the time it happened"*) leans the other way: an
instant before the write, the employer is the old one. **The practical argument overrules it.**
Freezing to the old company leaves the new company's history beginning with a **gap** — the
silent-missing-rows failure `adr/0002` and the §2 carve-out exist to prevent, reproduced at the
reporting level. Freezing to the new company leaves the old company's history simply **ending**,
which is correct: they stopped employing the person.

**The absence of further rows is the departure. The absence of an arrival row is a gap.**

**Both rows must agree**, or one transaction produces two rows in two different companies'
reports and the timeline is internally incoherent. The transfer row is the **arrival record** —
the first row of the new employment, giving a clean partition with no gap and no overlap.

⚠ This choice affects **direct reporting queries only**. The employee's own Status History tab
is unaffected either way, because `Employee::statusHistory()` releases the tenant scope
(`conventions.md` §2's second carve-out).

### 4. The cascade lifts `TenantScope`

The four descriptive child tables — `employee_family_members`,
`employee_education_history`, `employee_employment_history`, `employee_documents` — have their
`company_id` cascaded inside the transfer's transaction, with the tenant scope **lifted**.

**The row set belongs to the employee, not to the reader.** A cascade filtered by the acting
account's read scope would update only the rows that account can see and **leave the rest
behind silently** — fewer rows updated, no error, and a record that is half-transferred in a
way nothing reports. That is the same failure mode `adr/0002` names for shared branches, on the
write side.

`employee_status_history` (event) is frozen and `employee_roles` /
`employee_job_functions` (company-reference) are untouched, exactly as `adr/0003` decision 7
requires. **All of it is one transaction:** a half-transferred employee is a state nobody can
repair, because no single field says which half ran.

---

## Consequences

**Accepted**

- **`employee-master.spec.md` §5.7 and BR-17 must be amended.** Both say a transfer must not
  touch `employee_status_history`; it now writes two rows. §5.7's paragraph requiring an ADR
  for a fifth enum value is discharged by this document.
- **A migration widens the `change_type` enum** to five values. Forward migration, not an edit
  to the creating one — `conventions.md` §11 does not apply.
- **`StatusHistoryScopeTest`'s guard `it_accepts_exactly_four_change_types` must be edited**,
  and that edit is the designed cost: the test names the set explicitly so a fifth value cannot
  arrive quietly. It becomes *exactly five*, and must still reject `CORE_ROLE` — role history
  stays in the pivot (`adr/0003` decision 8).
- **`AuditedFields` gains `Employee::company_id`**, now that an Action exists to write it. The
  registry's note already says the entry was withheld only for that reason.
- Terminal statuses (`RESIGNED`, `TERMINATED`) refuse a transfer, and the refusal **names the
  rejoiner path** — a new record with `previous_employee_id`, never a transfer. Without a
  structural refusal HR will use a transfer for a rejoin because it is easier, and the break in
  service that decides leave entitlement disappears silently.
- `department_id` is a **required argument** to the transfer, not an optional one and not
  defaulted to the existing value. `branch_id` and `position_id` are **not** required: both are
  nullable and neither carries routing.

**Rejected**

- **A snapshot of surviving roles, in any container** — ledger rows, packed text, or a table of
  its own. All three store a fact `employee_roles` already answers by date. See decision 2.
- **`COMPANY` as the enum value.** One word for two meanings on a single row.
- **Freezing the rows to the old employer.** It opens the new company's history with a gap,
  which is the failure the carve-out exists to prevent.
- **Cascading within the reader's tenant scope.** It leaves rows behind and reports nothing.

**Not changed**

- `employee_roles` and `employee_job_functions` are untouched by a transfer. A Manager role at
  AIM is still a Manager role at AIM after the person's payroll moves (`adr/0003` decision 7).
- `employee_no` survives a transfer with the person (BR-13).
- HR **and** Master Admin may each transfer directly, neither approving the other (§5.7).
