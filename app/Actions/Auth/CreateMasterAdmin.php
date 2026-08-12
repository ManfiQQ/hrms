<?php

namespace App\Actions\Auth;

use App\Exceptions\Auth\MasterAdminLimitException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Auth\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BR-A13 — a Master Admin creates another, up to three, auth-rbac.spec.md §5.8.
 *
 * ⚠ THE LIMIT IS ENFORCED HERE, NOT IN THE UI. A cap that lives in a Blade condition is a cap
 * the next caller walks past — a console command, a seeder, a future API route — and what it
 * bounds is the set of accounts that bypass tenant scope and read every salary in the group
 * (`adr/0005` decision 5, `adr/0004` decision 3).
 *
 * ⚠ NO EMPLOYEE RECORD, EVER (`adr/0001` decision 4). That is what keeps the administrative
 * account out of headcount, org charts, payroll runs and leave calendars, and what makes
 * "Master Admin holds no `employee_roles` row" structurally true rather than asserted: with
 * no employee there is nothing for a role row to key to.
 */
class CreateMasterAdmin
{
    /**
     * ⚠ `system_access` is the audited field. There is no `is_master_admin` column — `FULL`
     * with a null `employee_id` is the single mechanism that identifies one (`adr/0004`
     * decision 2), so the grant of that value IS the event worth recording.
     */
    public const AUDITS = [
        User::class => ['system_access'],
    ];

    /** BR-A13's ceiling. A literal because it is a structural limit, not an HR policy number. */
    public const MAXIMUM = 3;

    public function __construct(
        private readonly GenerateActivationToken $activation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{user: User, activationToken: string}
     *
     * @throws MasterAdminLimitException|ValidationException
     */
    public function execute(string $name, string $phoneNo, ?string $email = null): array
    {
        $normalised = PhoneNumber::normalise($phoneNo);

        if (! PhoneNumber::isValid($normalised)) {
            throw ValidationException::withMessages([
                'phoneNo' => 'Enter a phone number of 9 to 12 digits.',
            ]);
        }

        if (User::query()->where('phone_no', $normalised)->exists()) {
            throw ValidationException::withMessages([
                'phoneNo' => 'Another account already uses this number as its login.',
            ]);
        }

        return DB::transaction(function () use ($name, $normalised, $email) {
            // ⚠ Counted inside the transaction and with the rows locked, so two concurrent
            // creations cannot both read "two existing" and both proceed to a fourth. The
            // same reasoning as the employee_no sequence: a check-then-act outside a lock is
            // not a limit, it is a suggestion.
            $existing = User::query()->where('system_access', 'FULL')->lockForUpdate()->count();

            if ($existing >= self::MAXIMUM) {
                throw MasterAdminLimitException::tooMany(self::MAXIMUM, $existing);
            }

            $user = new User();
            $user->name = $name;
            $user->phone_no = $normalised;
            $user->email = $email;

            // No employee, and it stays that way (adr/0001 decision 4).
            $user->employee_id = null;

            $user->system_access = 'FULL';

            // ⚠ NO USABLE PASSWORD, exactly as for an employee. A random 64 characters
            // nobody has seen satisfies NOT NULL and can never be used; the account is
            // reached by redeeming its activation QR, which forces the holder to set their
            // own. A password typed here would be a credential passed between two people
            // for the most powerful account in the system (adr/0004 decision 7).
            $user->password = Str::random(64);
            $user->must_change_password = true;

            $user->save();

            $token = $this->activation->execute($user);

            $this->audit->record(
                action: 'master_admin.created',
                subject: $user,
                field: 'system_access',
                old: null,
                new: 'FULL',
                reason: "Master Admin created; {$existing} existed before this one (limit ".self::MAXIMUM.').',
            );

            return ['user' => $user, 'activationToken' => $token];
        });
    }
}
