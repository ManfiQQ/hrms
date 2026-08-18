# ADR 0012 — Employee Documents: Serving, Storage, Upload, and Submission

- **Status:** Accepted — 2026-08-14
- **Amends:** `employee-master.spec.md` §6.3 — which defines who may *read*
  each document type and is silent on every write
- **Related:** `adr/0004` decision 9 (per-type employee reads), `adr/0003`
  decision 7 (cascade categories) and decision 8 (one fact, one record),
  `adr/0011` (deferring code with no caller), `conventions.md` §3 §9,
  `schema.md` § `employee_documents`
- **Raised by:** UI §7 — the Documents tab cannot be built without deciding
  who may write, where bytes live, and how they are served

---

## Context

`employee_documents` exists: migrated, tenant-scoped, write-once on
`file_path`, tested. `EmployeePolicy::viewDocument()` exists and decides which
of the seven types an employee may read of their own record.

**Nothing in the system can put a file into it.** No controller, no Livewire
component, no route, no FormRequest accepts an `UploadedFile`. No code anywhere
calls `Storage::`. The only producer of a `file_path` value is a test factory.

And `viewDocument()` has no caller in application code — its only callers are
three assertions in `EmployeePolicyTest`. **That is the shape `adr/0011`
deferred a scope over and `conventions.md` §9 records twice**: an ability
nobody calls is an ability nobody misses, which is how
`EmployeePolicy::transfer()` came to be missing for days with a green suite.

Nine questions block any code, and none can be inferred from what is written.
§6.3's every verb is a read verb — *view*, *download* — and the nearest thing
to a write rule is `schema.md` noting that `created_by` is the uploader, which
says who is *recorded*, not who is *allowed*.

**These files are IC scans, passports and certificates.** The decisions below
are made once, before the first byte is written, because a serving mechanism
chosen for convenience is not one that can be quietly replaced afterwards.

---

## Decision

### 1. Files are served by a controller that asks the policy, never by a signed URL

A route takes the **`employee_documents` row id**, loads the model, calls
`EmployeePolicy::viewDocument()`, and streams the bytes. The path never appears
in a URL and is never accepted as input.

**Signed URLs were rejected, and the reason is not convenience.** A signature is
a **bearer token**: it carries no identity. The policy is never consulted, a
frozen account is not stopped, and a forwarded link works for whoever holds it —
so an HR user pasting a link into WhatsApp has shared an IC scan with that
entire group. `config/filesystems.php`'s `serve => true` was turned off on
2026-08-14 for exactly this reason (`conventions.md` §9).

**The deciding argument is structural, not preferential.** `type` is a column on
the row, not a property of the file, and `viewDocument()` reads `type` to decide
whether the employee may see their own document. **Any serving mechanism taking
a file path as input has already discarded the only column the rule reads.**

Cost accepted: every view passes through PHP. These are scans opened
occasionally by HR, not assets fetched thousands of times a day.

### 2. Bytes live on the `local` disk, under the employee id

```
documents/{employee_id}/{uuid}.{ext} accepted records
submissions/{employee_id}/{uuid}.{ext} pending employee submissions
```


Disk `local` — `storage/app/private`, no longer reachable over HTTP.

**Scoped by `employee_id`, not `company_id`, and that is not a style choice.**
`employee_documents` is a **descriptive** child table: its `company_id`
**cascades on a company transfer** (`adr/0003` decision 7). A path containing
`company_id` would be stale the moment `TransferCompany` runs — the row would
say TURSENIA while the bytes sat in an AIM folder — and **`file_path` is
write-once**, so it could not be corrected without breaking the lock that keeps
`created_by` honest.

`employee_id` never changes. A transferred employee keeps their record and their
`employee_no` (`adr/0003` decision 7), which makes it the only identifier in this
system that survives a transfer.

A flat `documents/{uuid}` layout was rejected for a different reason: it cannot
be traced back to a person from the disk alone, so a restored file backup
without its database is thousands of unattributable scans.

### 3. HR and Master Admin upload directly; employees submit

`HR` and Master Admin write to `employee_documents` directly. They are the only
accounts that do.

An employee's upload goes to a **separate table**,
`employee_document_submissions`, and becomes a document only when HR accepts it.

**A status column on `employee_documents` was rejected.** It would put official
records and proposals in one table, meaning every read must remember to filter —
and a read that forgets is silently wrong, showing HR an unreviewed passport scan
as though it were the record. That is the pattern this project has refused six
times by name: `is_active`, `is_enabled`, `hr_scope`, `primary_role`,
`secondary_company_id`, `is_master_admin`.

Keeping submissions in their own table means `employee_documents` continues to
mean exactly one thing, and no filter can be forgotten.

### 4. HR sets the type; the employee never chooses it

`employee_document_submissions` has **no `type` column.** It carries the file and
an optional free-text `note` — *"new passport, old one expired"* — which guides
HR and governs nothing.

**`type` is set by HR at the moment of acceptance**, because `type` drives read
permission. An employee choosing it would be declaring part of their own access
rule, which is the wrong shape however the choice is constrained.

Two consequences fall out without a rule to enforce them:

- **`OTHER` is closed to employees structurally.** It is the escape hatch for
  internal notes and investigation material, hidden from the employee
  (`adr/0004` decision 9) — and an employee cannot submit into it because they
  cannot name a type at all.
- **A future eighth type needs no change here.** `schema.md` calls the seven a
  starting set, amendable by migration. A whitelist of employee-submittable
  types would have to be updated with each one; nothing does.

### 5. HR's review of a submission is a data gate, not a workflow

One reviewer, one step, two outcomes: accept or reject. `HR` and Master Admin,
each acting directly — the same shape as `TransferCompany`, where neither
approves the other.

**This is deliberately not an approval.** The Approval Engine routes: stages in
order, endorsement against decision, what happens when an approver is on leave,
escalation when one is frozen. None of that exists here and none is wanted.
Naming this a workflow would create a second approval mechanism outside the
engine built to hold them — which is the failure this project has avoided
throughout.

**Do not later connect this to the Approval Engine on the grounds that it looks
like one.** If a document review ever genuinely needs to route past HR, that is
a new decision and a new ADR, not a wiring job.

### 6. Accepting moves the file; rejecting deletes it

**Accept** — the file moves from `submissions/` to `documents/`, a row is
created in `employee_documents` with the type HR chose, and the submission row
is marked accepted with the resulting document id.

**Reject** — a written reason is **required**, the **file is deleted from disk
immediately**, and the submission row remains as the record: who submitted, when,
who rejected it, and why. An event is emitted for the Notification Engine.

**A retention window was considered and rejected.** Keeping rejected files for
a period would need a scheduled task — this project has none, and
`routes/console.php` is empty — and the notification telling the employee to
resubmit needs the Notification Engine, which has not started and is blocked on
the outstanding client decision about notification channel — email, WhatsApp, or
in-app only (`CLAUDE.md` §10).

> **⚠ THE FIRST CLAUSE IS NOW FALSE, AND THE CONCLUSION STANDS — `adr/0016`, 2026-08-18.**
> *"This project has none, and `routes/console.php` is empty"* was true when this was written.
> Two tasks are registered there today, `security-events:prune` and `sessions:prune`, and both
> are asserted by tests. **But nothing calls `schedule:run`** — no cron entry, no deployment —
> so no scheduled task can be relied on to run, and the rejection above holds for a different
> reason than the one it gives. `adr/0016` decision 5 makes the cron entry a documented step of
> the first deployment.

**What settles it: the rejected file is not the only copy.** The employee
uploaded it, so it is still on their phone. A window would protect something
that has not been lost, at the cost of leaving unreviewed IC scans on disk —
including the case that matters most, where somebody attaches the wrong file
entirely and rejection is how it leaves the system.

⚠ **Until the Notification Engine exists, the employee learns of a rejection
only by looking.** The event is emitted with no listener, joining
`AccountFrozen` and `AccountActivated`. This is a real gap, recorded rather than
hidden.

### 7. Submissions are visible to HR, Master Admin, and their sender only

Not supervisors, not `ACCOUNT`, not `ASSISTANT_DIRECTOR` — a narrower set than
`employee_documents` itself.

The reason is what a submission is: an **unreviewed file of unknown type**.
Everything that governs document visibility is per-type (`adr/0004` decision 9),
and a submission has no type until HR assigns one. There is no rule that could
be applied to it, so the only defensible readers are the two who decide its fate
and the one who sent it.

### 8. A replaced document's file is kept, and only HR may remove it

When a document is replaced, the old row is soft-deleted and **its file stays on
disk by default.**

**This deliberately differs from decision 6, and the distinction is the point:
a rejected file was never a record. A replaced one was.** An old passport scan
was the official document for two years; it may be referenced in a letter, a
work permit, or a statutory filing made while it was current. Deleting it erases
evidence of a state that was true — the same objection `adr/0003` decision 7
makes to rewriting frozen payroll history, which it calls falsification rather
than an update.

**`HR` and Master Admin may delete such a file, under two conditions:**

- **Only for a row that is already soft-deleted.** A current document's file
  cannot be removed; it must be replaced first. This stops deletion becoming a
  shortcut for discarding a live record.
- **Audited, with a written reason** — see decision 9.

The row always remains, carrying its `file_path` and a marker that the bytes are
gone: who removed them, when, and why. **The record of what existed survives
even when the file does not**, and a caller reading `file_path` must handle the
absence rather than assume it.

### 9. Every document operation is audited, and this is a named exception

Upload, submission accepted, submission rejected, replacement, and file deletion
all write to `audit_logs`. `EmployeeDocument` joins `AuditedFields`, which
enforces the registry in both directions.

**⚠ This contradicts the rule applied to role grants on 2026-08-13**, where
mirroring `employee_roles` into `audit_logs` was refused because the pivot
already records `assigned_by` — one fact, two records (`adr/0003` decision 8).
By that argument `created_by` on a document row is enough, and this is an
exception to it.

**The exception is named rather than a reversal, because documents are the only
place in this system where the record and the thing it refers to can come
apart.** Every other table stores facts; this one stores a pointer to bytes. A
row can say a file exists when it does not — after decision 8's deletion, that
is not hypothetical but designed. Where a row cannot fully answer for what
happened, a second record is not duplication.

**Role grants stay unaudited**, and that decision is unchanged. This exception
does not generalise: it applies to tables holding files, and there is exactly
one.

### 10. Uploads accept PDF, JPEG and PNG, up to 10 MB

Enforced in the FormRequest, and the MIME type is checked from the file's
contents, not from its extension or the client's declaration.

Three formats cover every scan and certificate this module holds. The limit
leaves room for a high-resolution scan while refusing the file sizes an upload
path with no ceiling will eventually receive.

### 11. The file-serving controller is built with the Documents tab, not before

The controller, the routes, the FormRequests, the Actions and the submissions
table land with §7's Documents tab — in the same PR, not ahead of it.

**This follows `adr/0011`'s deferral for the same reason.** `viewDocument()` has
existed since slice 2 with no caller in application code, and
`EmployeePolicy::transfer()` was missing for days while the suite stayed green,
because an Action reached only by a test that calls it directly never asks a
policy anything (`conventions.md` §9). Building the serving layer before the
screen that calls it repeats that deliberately.

---

## Consequences

**Accepted**

- A new table, `employee_document_submissions`, with its own model, factory,
  policy abilities and tenant scope.
- Every document view costs a PHP request. Accepted in decision 1.
- Employees cannot be told a submission was rejected until the Notification
  Engine exists. Recorded in decision 6, and it is a real gap.
- `file_path` may point at bytes that are gone (decision 8). Every reader must
  handle that; a caller assuming presence is a bug, not an edge case.
- HR carries the whole upload burden for documents the company issues, and the
  review burden for the ones employees send. There is no third path.
- The audit exception in decision 9 must be re-read whenever role-grant
  auditing is revisited. Two rules that disagree stay correct only while the
  reason for the disagreement is written down.

**Not changed**

- `adr/0004` decision 9 — which types an employee may read of their own record.
  This ADR decides writing; reading is unchanged.
- The write-once lock on `file_path`, and replacement as a new row plus a soft
  delete of the old.
- `employee_documents` remains **descriptive**: its `company_id` cascades on a
  company transfer (`adr/0003` decision 7). Only the path scheme avoids
  `company_id`, and decision 2 explains why.
- `conventions.md` §3's soft-delete rule, which governs rows. Bytes
  on disk were never in its scope, and this ADR is where they are.
