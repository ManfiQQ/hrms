<?php

namespace App\Actions\Employee;

use App\Actions\Auth\GenerateActivationToken;
use App\Models\Company;
use App\Models\Employee;
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
     */
    public const AUDITS = [
        Employee::class => ['employee_no'],
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
}
