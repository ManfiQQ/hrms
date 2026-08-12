<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\AuthPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The forced password change — BR-A2, BR-A23.
 *
 * ⚠ The minimum length is read from `policy_configurations`, never hardcoded
 * (conventions.md §5). Six characters is the client's choice over the recommended eight, and
 * the number has to be changeable in one place when that is revisited.
 *
 * ⚠ NO COMPOSITION RULES — no forced uppercase, digits or symbols, deliberately. They
 * produce `Abcd1234!` and passwords written on paper; a memorable phrase is stronger than a
 * short complex string kept on a sticky note. If this ever grows a regex, BR-A2 has been
 * overruled without an ADR.
 *
 * ⚠ The current password is NOT required, and that is not an oversight. This screen is
 * reached in two ways, and in both the existing password is already known to someone else:
 * after HR resets it, and after a QR activation where the employee has never had one. Asking
 * for it would make the activation path impossible. The gate exists precisely because the
 * current credential is not trusted.
 */
class PasswordChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is behind the auth middleware; there is no per-account decision to make
        // here. Authorization decisions live in policies, never in a FormRequest.
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $minimum = app(AuthPolicy::class)->int('auth.password.min_length', $this->user());

        return [
            'password' => ['required', 'string', 'min:'.$minimum, 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }
}
