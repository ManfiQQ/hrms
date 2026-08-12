<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Login input — conventions.md §1 puts all validation in a FormRequest, never inline in a
 * controller.
 *
 * ⚠ Validation here is about SHAPE ONLY. Whether the number belongs to an account, whether
 * the password matches, and whether the account is locked are all decided by
 * AuthenticationService, and all three produce the same generic failure (BR-A3). A validation
 * rule such as `exists:employees,phone_no` would turn this form into an existence oracle
 * before authentication was even attempted — the username is a phone number, so that is worth
 * more to an attacker than it looks.
 *
 * ⚠ There is no `remember` field, and none may be added (BR-A4). Removing the checkbox from
 * the form is not the same as disabling the feature; the rule is enforced by
 * AuthenticationService::attempt() having no such parameter at all.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Not `numeric`: the field accepts 012-345 6789 and +60123456789 as typed, and
            // PhoneNumber::normalise is what reconciles them (BR-A1). Rejecting formatting
            // here would fail people entering their own number correctly.
            'phone_no' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'phone_no' => 'phone number',
        ];
    }
}
