<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Auth\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changing `users.phone_no` — auth-rbac.spec.md §6, §7, `adr/0006`.
 *
 * ⚠ THIS IS THE ONLY PLACE A PERSON'S PHONE NUMBER CHANGES ANYWHERE IN THE SYSTEM. Since
 * `adr/0006` the number lives on the account and `employees` holds none, so there is no
 * profile field to edit and no second copy to keep in step. `employee-master.spec.md` §6.4
 * renders no such field at all.
 *
 * ⚠ AND IT IS A CREDENTIAL CHANGE, NOT A CONTACT UPDATE. The number is the login username
 * (BR-A1). Changing it means the employee can no longer sign in with the number they have
 * been using, and whoever now holds the old number cannot either — which is the point, but
 * it is also why this sits beside password reset and unlock rather than on an employee form.
 * `ASSISTANT_DIRECTOR` may create, edit and archive employee records and still cannot do
 * this: an account holder who could change somebody's USERNAME but not reset their PASSWORD
 * is a combination that makes sense from no direction (`adr/0004` decision 6).
 */
class ChangeLoginUsername
{
    /**
     * ⚠ Audited as the username it is. `phone_no` is not a secret — a phone number is known
     * to colleagues, which is exactly why BR-A2's throttling carries the security a
     * six-character password does not — so recording both values is safe and is the whole
     * point: "who changed this person's login, from what, to what, and when".
     */
    public const AUDITS = [
        User::class => ['phone_no'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException
     */
    public function execute(User $user, string $newNumber, ?string $reason = null): string
    {
        // ⚠ Normalised through the ONE normaliser BR-A1 requires, before anything else. A
        // number stored as typed would leave an account reachable only by retyping the exact
        // punctuation — and the person it belongs to would read "invalid credentials" while
        // entering their own number correctly.
        $normalised = PhoneNumber::normalise($newNumber);

        if (! PhoneNumber::isValid($normalised)) {
            throw ValidationException::withMessages([
                'phone_no' => 'Enter a phone number of 9 to 12 digits.',
            ]);
        }

        // ⚠ Checked before writing, so the failure is a message rather than a database error
        // — but the unique index is what actually guarantees it. A dummy or duplicated number
        // occupies that index and hands one person's username to another.
        $takenBy = User::query()
            ->where('phone_no', $normalised)
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($takenBy) {
            throw ValidationException::withMessages([
                'phone_no' => 'Another account already uses this number as its login.',
            ]);
        }

        return DB::transaction(function () use ($user, $normalised, $reason) {
            $previous = $user->phone_no;

            if ($previous === $normalised) {
                // A no-op is not a credential change, and recording one would put an event in
                // the trail that never happened.
                return $normalised;
            }

            $user->forceFill(['phone_no' => $normalised])->save();

            $this->audit->record(
                action: 'account.username_changed',
                subject: $user,
                field: 'phone_no',
                old: $previous,
                new: $normalised,
                reason: $reason ?? 'Login username changed by an administrator.',
            );

            return $normalised;
        });
    }
}
