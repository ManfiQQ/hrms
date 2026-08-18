# ADR 0016 — Scheduled Tasks

- **Status:** Accepted — 2026-08-18
- **Amends:** `schema.md` § `sessions` — the pruning it states as fact;
  `auth-rbac.spec.md` BR-A1 — a named exception for the system account
- **Related:** `adr/0009` decisions 2–3 (authorship, and what it refuses),
  `adr/0006` (the number lives on `users`), `adr/0013` decisions 4–5 (two
  deferred warnings), `adr/0015`, BR-A13, BR-A15, BR-A17
- **Raised by:** `ChangeEmployeeStatus` needs to accept a future
  `effective_date`, and there is nothing to act on it when that date arrives

---

## Context

**Two tasks are registered, and neither has ever run.**
`routes/console.php` schedules `security-events:prune` daily and
`sessions:prune` hourly. Both commands exist. Two tests assert the
registration — `PruneSecurityEventsTest` and `SessionConfigurationTest`.

**Nothing calls `schedule:run`.** No cron entry, no deployment script, no
document that mentions one. The registrations are correct and unreachable.

**So `schema.md`'s statement that expired session rows are pruned on a schedule
is true of the code and false of the system**, and has been since it was
written. The table grows without bound, and the tests are green.

⚠ **That is the defect this ADR exists to close, and it is worse than a missing
feature.** A test asserting a task is registered proves the registration, not
that anything invokes it — a §9 finding arriving from a direction nobody was
watching. A scheduler nobody calls fails exactly like a
scheduler that works.

**Five things are waiting on this mechanism**, and the one that surfaced it is
the smallest:

| Waiting | From |
|---|---|
| Freezing an account on a future last working day | this ADR's trigger |
| Expired session pruning | `schema.md`, stated as already happening |
| Permit expiry warnings | `adr/0013` decision 4 |
| Missing EPF/SOCSO warnings | `adr/0013` decision 5 |
| Every scheduled notification | the Notification Engine, not started |

**The trigger is a real defect, not a hypothetical.** BR-A15 freezes an account
in the same transaction as the terminal status. An employee who gives notice on
the 18th for a last day of the 31st would be frozen the moment HR records it —
locked out of verifying their own attendance for the two weeks they are still
working. Notice periods are how people resign; this is the ordinary case.

**And the system has never been deployed** — there has been no moment at which a
cron entry would have been added. The legacy system on the VPS runs from a
separate repository and shares nothing with this one.

**⚠ The failure mode of everything below is silence.** Cron dies — a reboot, a
path change, a deployment that drops the entry — and no task runs. Nothing
errors. Nothing logs. An employee stays active past their last day, sessions
accumulate, and nobody learns of it until somebody asks why a departed employee
can still sign in.

That is the shape `conventions.md` §9 records repeatedly: **a rule believed
to be in force, carried by something that is not there.**

---

## Decisions

### 1. A system user, as a real `users` row

Scheduled work writes rows, and `AuthorshipObserver` throws when there is no
authenticated user. The tasks below change `staff_status`, revoke
`employee_roles`, and write ledger rows — all three are observed models.

**A `users` row represents the system.** `phone_no` is the literal string
`SYSTEM`. `system_access` is `STANDARD`, so BR-A13's 3/1 Master Admin limits are
untouched. It holds no employee record and cannot sign in.

**`SYSTEM` is not a placeholder number, and that distinction is the whole
argument.** BR-A1 forbids a dummy number because *a dummy number occupies the
unique index and hands one person's username to another*. A Malaysian mobile
number is 9–12 digits beginning `01`; a string containing letters can never
collide with one. It takes nobody's username because it is not in the space of
usernames.

It also reads as what it is. An HR user who finds `SYSTEM` in a record needs no
explanation; one who finds `000000000000` wonders whose number it was.

**Naming a Master Admin was rejected.** The scheduler would write that a person
froze an account at midnight, and `adr/0009` decision 3 refused exactly this
trade: *an audit column that states a confident falsehood is worse than one that
admits ignorance*. Master Admins can also be removed (BR-A13), so the record
would eventually point at somebody with no connection to it.

**A `NULL` `created_by` was rejected twice over.** `adr/0009` decision 2 refuses
the silent fallback, and decision 3 made the column `NOT NULL` — dropping the
development database to do it. Reversing that ten days later, for a case of our
own making, is what `conventions.md` §11 exists to prevent.

**⚠ Two consequences that must be built, not assumed:**
`PhoneNumber::normalise()` must be checked against `SYSTEM` — a normaliser that
strips non-digits returns an empty string. And the login form must refuse it
before it reaches the database.

### 2. Three tasks, and two deferred

**Running:**

- **Terminal-status freeze on a future `effective_date`** — the trigger
- **Scheduler heartbeat** — decision 4
- **Expired session pruning** — already registered, never invoked

**Deferred, with reasons:**

- **Permit expiry warnings** (`adr/0013` decision 4)
- **Missing EPF/SOCSO warnings** (`adr/0013` decision 5)

Both need the Notification Engine, which has not started and is blocked on the
outstanding client decision about notification channel — email, WhatsApp, or
in-app only (`CLAUDE.md` §10). But the deeper reason is that **neither has a resend rule**: a
permit that expired yesterday — warn daily until renewed, once, or weekly? That
question is better answered alongside the engine that will carry the answer than
invented here.

**Session pruning is included precisely because it needs nothing new.** It is
already written, already registered, already tested. It has never run, and every
day it does not is a day `schema.md` is wrong.

### 3. Repeat-safety through a predicate, not a lock

**The tasks act only on rows that still need acting on.** The freeze task selects
employees whose `staff_status` is not yet terminal and whose ledger holds a
terminal row effective today or earlier. Once frozen, the employee is outside
the predicate; a second run in the same minute or the same day finds nothing.

**`withoutOverlapping()` was rejected**, and not because it fails. It prevents
two *concurrent* runs and does nothing about two *consecutive* ones — cron
firing again a minute later, or somebody running `schedule:run` by hand during
an investigation. A lock that covers the rare case and leaves the ordinary one
teaches that safety comes from the lock rather than from the logic.

**⚠ The predicate is the guarantee, so the predicate is what gets tested.** A
test that runs each task twice and asserts the second run changes nothing is not
an extra test; it is the one that proves the decision.

This also makes every task safe to run by hand — which matters on a system where
the only way to know a task works is to invoke it.

### 4. A heartbeat, checked from the dashboard

**Every scheduler run writes a timestamp** — `scheduler.last_run`. The Master
Admin dashboard displays it, and marks it stale past a threshold.

**The check cannot live inside the scheduler.** A scheduler that has stopped
cannot report that it has stopped; the absence is the signal, and something
outside it has to notice.

**It is checked on the dashboard, not on every request.** A per-request check
adds a read to every page load to catch a rare condition. The dashboard is where
somebody is already looking for the state of the system.

**⚠ This depends on somebody opening the dashboard.** A stale heartbeat nobody
looks at is no better than no heartbeat. That is why the event below exists —
but until the Notification Engine can carry it, this decision is honest about
being a display and not an alarm.

**A stale heartbeat emits an event**, joining the others already waiting for that
engine — some emitted in code today, some declared by ADRs and not yet built. When
the engine arrives it connects to all of them at once.

### 5. Cron is a deployment step, and it has not happened

**Installing the cron entry is part of the first deployment**, documented rather
than assumed:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

**⚠ The first deployment fails silently if this is missed.** Everything else
works — the app serves, employees register, HR signs in — and only the scheduled
work is absent. The heartbeat is the one thing that would show it, which is why
it is checked on the first day rather than added later.

---

## Consequences

**Accepted**

- **A `users` row that is not a person.** Every check assuming `users` means a
  human must tolerate it — `PhoneNumber::normalise()` and the login form named
  explicitly in decision 1.
- **Another event with no listener.** Correct while the engine is unbuilt, and
  worth stating rather than leaving to be discovered.
- **The heartbeat is a display, not an alarm**, until that engine exists.
- **Two `adr/0013` warnings stay deferred**, and their resend rules stay
  unanswered.
- **Session pruning starts working**, and `schema.md`'s sentence becomes true for
  the first time.

**Not changed**

- **BR-A1 for employee accounts.** The exception is named, bounded to one row,
  and rests on `SYSTEM` being outside the space of phone numbers rather than
  inside it as a placeholder.
- **`adr/0009` decision 2.** No silent `NULL`. The system user is an actor, not
  an absence of one.
- **BR-A13.** The system account is `STANDARD`, so the 3/1 limits count what
  they always counted.
- **BR-A15's same-transaction freeze.** A terminal status effective today still
  freezes immediately; the scheduler handles only dates that have not arrived.
- The append-only ledger. A scheduled freeze writes a row like any
  other; nothing about being scheduled changes what the ledger is.
