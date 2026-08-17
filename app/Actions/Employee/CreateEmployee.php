<?php

namespace App\Actions\Employee;

use App\Actions\Auth\GenerateActivationToken;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Sequence\SequenceGenerator;
use App\Support\Auth\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * BR-A20 — the account is created in the same transaction as the employee record.
 *
 * ⚠ NOT A SEPARATE STEP HR MUST REMEMBER, and not a convenience. The client requires every
 * employee to verify their own attendance data, and payroll is BLOCKED when attendance is
 * incomplete (payroll-notes.md §3). An employee without an account cannot verify, so an
 * account is an operational requirement for everyone — not an optional extra for office
 * staff (adr/0004 decision 7).
 *
 * Since adr/0006 it is structural as well: the login username lives on `users.phone_no`, so
 * an employee with no account has no username and no way into the system at all.
 *
 * ⚠ EVERYTHING HERE IS ONE TRANSACTION. The employee, the number, the account and the
 * activation token land together or not at all. A half-completed registration is the worst
 * outcome available: an employee row with no account is a person who cannot log in, and a
 * burned `employee_no` with no employee is a gap in a sequence that must never rewind.
 */
class CreateEmployee
{
    /**
     * ⚠ BR-AT13's declaration. Every pair must also appear in App\Support\Audit\AuditedFields
     * — the architecture test fails in both directions.
     *
     * Creation is audited on `employee_no` because that is the fact worth being able to
     * account for later: who brought this person onto the payroll, and when. The number is
     * never reissued, so the row is permanently meaningful.
     *
     * ⚠ `superseded_at` IS AUDITED ON BOTH MODELS, AND THE SECOND IS NOT REDUNDANT. On
     * `Employee` the row records that a historical record released its identity numbers; on
     * `User` it records that an account released a LOGIN USERNAME. The second is the
     * security-relevant one — a wrongly-set value there lets two live accounts share a
     * username — and an audit trail that showed only the employee half would name the event
     * without naming the credential it moved (`adr/0015` decision 2).
     */
    public const AUDITS = [
        Employee::class => ['employee_no', 'superseded_at'],
        User::class => ['superseded_at'],
    ];

    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly GenerateActivationToken $activation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  employee fields; employee_no is ignored
     * @param  string  $phoneNo  the login username — normalised here, stored on the ACCOUNT
     * @param  Company  $employer  the payroll and legal employer
     *
     * @return array{employee: Employee, user: User, activationToken: string}
     */
    public function execute(array $attributes, string $phoneNo, Company $employer): array
    {
        // ⚠ Normalised through the ONE normaliser BR-A1 requires, before the transaction
        // opens. An employee registered under one normalisation cannot log in under the
        // other, and the system would report "invalid credentials" to somebody typing their
        // own number correctly.
        $normalised = PhoneNumber::normalise($phoneNo);

        if (! PhoneNumber::isValid($normalised)) {
            throw new InvalidArgumentException(
                "\"{$phoneNo}\" is not a usable login username: BR-A1 requires 9-12 digits after "
                .'normalisation. There is no placeholder path — a dummy number occupies the '
                .'unique index and hands one person\'s username to another.'
            );
        }

        return DB::transaction(function () use ($attributes, $normalised, $employer) {
            // ⚠ FIRST STATEMENT IN THE TRANSACTION, BEFORE ANY INSERT, AND THE ORDER IS
            // LOAD-BEARING RATHER THAN TIDY (adr/0015 decision 4).
            //
            // The prior record still holds this person's `ic_no` and the prior ACCOUNT still
            // holds their `phone_no`. Both are unique — now scoped to rows where
            // `superseded_at IS NULL` — so the new rows below cannot be written until the old
            // claim is released. Move this call after $employee->save() or $user->save() and
            // registration dies on a raw 1062 inside this transaction, exactly as it did before
            // adr/0015 existed.
            //
            // Verified rather than reasoned: on MySQL 8.4.11, mark-then-insert inside one
            // transaction succeeds and insert-then-mark is refused 1062.
            //
            // ⚠ WHAT ACTUALLY CATCHES A MOVED LINE is RejoinerIdentityTest's end-to-end case —
            // register a rejoiner carrying the same IC and the same phone number. Move this call
            // below either save() and that test goes red with the 1062 itself. No test can
            // reverse the order INSIDE this method without editing it, so there is no synthetic
            // guard here and none is claimed; the behavioural test is the guard. A second test
            // performs insert-then-mark directly against the database, so the reason this order
            // exists is recorded as an observed failure rather than only as this comment.
            //
            // ⚠ AND IT IS INSIDE THE TRANSACTION, NOT BEFORE IT. If anything below fails, the
            // mark rolls back with it — otherwise a registration that never completed would
            // have permanently released an identity nobody took over, leaving an old record
            // stripped of its claim for no reason.
            $this->supersedePrior($attributes['previous_employee_id'] ?? null);

            // Claimed under lockForUpdate inside this transaction. If anything below fails,
            // the number rolls back with it and is not burned (adr/0003 decision 9).
            // ⚠ Any employee_no in $attributes is DISCARDED. The locked sequence is the only
            // permitted source (BR-13, adr/0003 decision 9) — a caller-supplied number would
            // be exactly the MAX() + 1 collision this design removes, arriving through the
            // front door. The legacy importer, which does carry numbers from the old system,
            // is a separate path with its own rules (employee-master.spec.md §5.5).
            $employee = new Employee();
            $employee->fill($attributes);

            // ⚠ company_id is set HERE, never mass-assigned. It is deliberately absent from
            // Employee's $fillable so it can never arrive from request input
            // (employee-master.spec.md §5.1) — it is the payroll and legal employer, and the
            // whole tenant boundary rests on it being chosen rather than posted.
            $employee->company_id = $employer->id;

            // ⚠ Overwritten unconditionally. The locked sequence is the ONLY permitted source
            // (BR-13, adr/0003 decision 9) — a caller-supplied number would be exactly the
            // MAX() + 1 collision this design removes, arriving through the front door. The
            // legacy importer, which does carry numbers from the old system, is a separate
            // path with its own rules (employee-master.spec.md §5.5).
            $employee->employee_no = $this->sequences->nextEmployeeNo();

            $employee->save();

            // ⚠ Built field by field, not mass-assigned. `employee_id`, `system_access`,
            // `phone_no` and `must_change_password` are all absent from User's fillable list
            // on purpose — system_access above all, which only Master Admin may change
            // (auth-rbac.spec.md §6). A mass-assigned account is one a crafted request can
            // promote to FULL.
            $user = new User();
            $user->name = $employee->full_name;

            // The login username, on the ACCOUNT (adr/0006). An employee holds none.
            $user->phone_no = $normalised;
            $user->employee_id = $employee->id;

            // ⚠ NO USABLE PASSWORD IS GENERATED, and this is the decision rather than an
            // omission. `password` is NOT NULL, so it holds a random 64-character value that
            // nobody has ever seen and nobody can reproduce — not a temporary credential,
            // and not one that could be shared, guessed or reused.
            //
            // The employee sets their own on redeeming the QR, authenticated by the token
            // alone. The IC number was proposed as a first password and REJECTED: it is not
            // a secret and can never be changed, so it would open a window — lasting until
            // first login — in which anyone knowing a phone number and an IC could enter as
            // that person, with the audit log showing the employee themselves
            // (adr/0004 decision 7).
            $user->password = Str::random(64);

            // Everyone starts here. Permissions come entirely from employee_roles plus
            // derived read scope; FULL and VIEW_ONLY are never reached by omission
            // (adr/0004 decision 2).
            $user->system_access = 'STANDARD';

            // BR-A23 gates every route until it is cleared. Redemption is what clears it.
            $user->must_change_password = true;

            $user->save();

            // §5.6 — inside this transaction, so an account never exists without a way to
            // activate it.
            $token = $this->activation->execute($user);

            $this->audit->record(
                action: 'employee.created',
                subject: $employee,
                field: 'employee_no',
                old: null,
                new: $employee->employee_no,
                reason: 'Employee registered with an account and an activation token (BR-A20).',
            );

            return ['employee' => $employee, 'user' => $user, 'activationToken' => $token];
        });
    }

    /**
     * Release the prior record's claim on this person's identity values — `adr/0015` decision 4.
     *
     * ⚠ NOT A SEPARATE HR ACTION, AND A BUTTON WAS REJECTED. One would open a window in which
     * the new record exists and the old number is still bound, and it would put the burden of
     * remembering on the person least able to see the consequence. It happens here or nowhere.
     *
     * ⚠ THIS IS THE ONLY WRITER OF `superseded_at` IN THE SYSTEM. The column is absent from
     * `Employee::$fillable` and from `User`'s `#[Fillable]` list so it cannot arrive from request
     * input, and a second writer would be a second definition of what "superseded" means.
     */
    private function supersedePrior(mixed $previousEmployeeId): void
    {
        if ($previousEmployeeId === null) {
            return;
        }

        // ⚠ LOADED WITHOUT TenantScope AND WITH TRASHED ROWS, BOTH DELIBERATELY — the same
        // carve-out AccountExpiry and ReadScopeResolver take (conventions.md §2). A rejoiner may
        // return to a DIFFERENT group entity, so the prior record belongs to whoever employed
        // them then; and an archived prior record is the ordinary case rather than an error,
        // because §5.2 soft-deletes and never hard-deletes. A scoped or non-trashed read would
        // find nothing and silently skip the release, and the insert below would then fail on a
        // constraint whose cause is two layers away.
        $prior = Employee::withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->find($previousEmployeeId);

        if ($prior === null) {
            throw new InvalidArgumentException(
                "previous_employee_id {$previousEmployeeId} matches no employee record. The "
                .'rejoiner link is what ties a new record to the employment it continues from '
                .'(BR-2, BR-13), and a link pointing at nothing would leave the prior record '
                .'still holding this person\'s IC and phone number.'
            );
        }

        // ⚠ THE GUARD OF adr/0015 DECISION 6, ENFORCED AT THE POINT OF WRITE.
        //
        // Decision 6 states the rule over the DATA — no `users` row may carry `superseded_at`
        // while its employee holds a non-terminal `staff_status` — because a superseded live row
        // releases a username while the account still logs in, and two live accounts could then
        // share one. That is the exact failure `users.phone_no` being unique exists to prevent.
        //
        // ⚠ A DATA GUARD ALONE WOULD ONLY DOCUMENT THE FAILURE. It runs over rows that already
        // exist; this refuses to create them. Without this check the guard asserts something the
        // front door can still violate — recorded as an explicit caller rule in adr/0015's
        // 2026-08-17 amendment, where decision 6 stops being implicit.
        //
        // ⚠ IT ALSO ENFORCES BR-2 AT THE ONE PLACE THAT MATTERS HERE: a rejoin continues from an
        // employment that ENDED. A prior record still ACTIVE is not a rejoin, it is a duplicate
        // person — the very thing the unique index exists to catch.
        if (! $prior->hasTerminalStatus()) {
            throw new InvalidArgumentException(
                "Employee {$prior->employee_no} holds staff_status {$prior->staff_status}, which "
                .'is not terminal, so it cannot be superseded. A rejoin continues from an '
                .'employment that has ended (BR-2), and superseding a live record would release '
                .'its IC and its login username while the account still works — two live '
                .'accounts able to share one username (adr/0015 decision 6). If this is the same '
                .'unbroken employment, it is a transfer, not a rejoin.'
            );
        }

        $supersededAt = now();

        // ⚠ AN ALREADY-SUPERSEDED RECORD IS LEFT EXACTLY AS IT IS, AND THIS IS NOT AN OVERSIGHT.
        // Whether two records may claim ONE predecessor is undecided — EmployeeStoreRequest says
        // so in as many words, and nothing makes the link unique. Overwriting the timestamp would
        // silently answer that question and destroy the date of the FIRST supersession, which is
        // the older fact. Leaving it alone answers nothing and loses nothing.
        if (! $prior->isSuperseded()) {
            $prior->superseded_at = $supersededAt;

            // ⚠ Eloquent save(), never a query-builder update. conventions.md §9 records that
            // query-builder writes bypass model events entirely, which would skip the adr/0009
            // authorship observer — and this write is exactly the kind whose actor matters.
            $prior->save();

            $this->audit->record(
                action: 'employee.superseded',
                subject: $prior,
                field: 'superseded_at',
                old: null,
                new: $supersededAt,
                reason: 'Superseded by a rejoining registration; identity claim released '
                    .'(adr/0015 decision 4).',
            );
        }

        // ⚠ THE ACCOUNT IS THE HALF THAT MATTERS MORE. `employees.ic_no` blocked the rejoiner
        // with a message naming a field; `users.phone_no` blocked it as a raw 1062 inside this
        // very transaction, because User has no soft deletes and the freeze leaves the row in
        // place holding the number for ever (BR-A15, BR-A17, BR-A18).
        //
        // ⚠ READ THROUGH THE RELATIONSHIP ON THE ALREADY-UNSCOPED $prior, so a prior account
        // belonging to a former employer is still found.
        $priorAccount = $prior->user;

        // ⚠ NULL IS TOLERATED RATHER THAN ASSUMED IMPOSSIBLE. BR-A20 creates an account in the
        // same transaction as every employee, so this should never be null — but if it is, the
        // release below is a no-op and the insert proceeds, because there is then no account
        // holding the number. Throwing would block a registration over a defect in an OLD row.
        if ($priorAccount !== null && $priorAccount->superseded_at === null) {
            $priorAccount->superseded_at = $supersededAt;
            $priorAccount->save();

            $this->audit->record(
                action: 'account.superseded',
                subject: $priorAccount,
                field: 'superseded_at',
                old: null,
                new: $supersededAt,
                reason: 'Login username released to the rejoining employee\'s new account; the '
                    .'frozen account keeps its number and its audit trail (adr/0015 decision 2).',
            );
        }
    }
}
