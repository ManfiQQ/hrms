<?php

namespace App\Livewire\Accounts;

use App\Actions\Auth\ChangeLoginUsername;
use App\Actions\Auth\RegenerateActivationToken;
use App\Actions\Auth\ResetAccountPassword;
use App\Actions\Auth\UnlockAccount;
use App\Models\User;
use App\Services\Auth\AuthPolicy;
use App\Services\Auth\LoginThrottle;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The account management screen — auth-rbac.spec.md §7.
 *
 * ⚠ Livewire's first genuine requirement in this project. The three auth screens before it
 * are plain form posts and were built without it deliberately; this one is four operations
 * against one record with state that changes under the user, which is what Livewire is for.
 *
 * ⚠ THE COMPONENT HOLDS NO AUTHORISATION LOGIC AND NO BUSINESS RULES. Every operation
 * delegates to an Action, and every entry point re-checks `manageAccount` — a Livewire
 * component is a long-lived object reachable by crafted requests, so authorising once in
 * `mount()` would be authorising once for the life of the page.
 */
class ManageAccount extends Component
{
    public User $user;

    public string $newPassword = '';

    public string $newPhoneNo = '';

    public ?string $issuedToken = null;

    public function mount(User $user): void
    {
        Gate::authorize('manageAccount', $user);

        $this->user = $user;
        $this->newPhoneNo = $user->phone_no;
    }

    public function resetPassword(ResetAccountPassword $action, AuthPolicy $policy): void
    {
        // ⚠ Re-authorised on every call, not just on mount. A Livewire action is an HTTP
        // request that names the component and the method, so the check has to live where
        // the work does.
        Gate::authorize('manageAccount', $this->user);

        $minimum = $policy->int('auth.password.min_length', $this->user);

        // BR-A2 — the minimum comes from policy_configurations, and there are NO composition
        // rules. If this ever grows a regex, that decision has been overruled without an ADR.
        $this->validate(
            ['newPassword' => ['required', 'string', 'min:'.$minimum]],
            ['newPassword.min' => "The password must be at least {$minimum} characters."],
        );

        $action->execute($this->user, $this->newPassword);

        $this->newPassword = '';
        $this->user->refresh();

        $this->dispatch('account-updated', message: 'Password reset. The employee must set their own on next sign-in.');
    }

    public function unlock(UnlockAccount $action): void
    {
        Gate::authorize('manageAccount', $this->user);

        $action->execute($this->user);
        $this->user->refresh();

        $this->dispatch('account-updated', message: 'Account unlocked.');
    }

    public function regenerateActivation(RegenerateActivationToken $action): void
    {
        Gate::authorize('manageAccount', $this->user);

        // The previous token dies here, and both timestamps are cleared — the screen's three
        // activation states must describe the token in play, not a previous one (BR-A22).
        $this->issuedToken = $action->execute($this->user);
        $this->user->refresh();

        $this->dispatch('account-updated', message: 'A new activation QR has been issued. The previous one no longer works.');
    }

    public function changeUsername(ChangeLoginUsername $action): void
    {
        Gate::authorize('manageAccount', $this->user);

        try {
            $action->execute($this->user, $this->newPhoneNo);
        } catch (ValidationException $e) {
            // Surfaced against the field rather than as a flash, because the operator needs
            // to correct the value they typed.
            $this->addError('newPhoneNo', $e->validator->errors()->first());

            return;
        }

        $this->user->refresh();
        $this->newPhoneNo = $this->user->phone_no;

        $this->dispatch('account-updated', message: 'Login username changed. The employee signs in with the new number from now on.');
    }

    /**
     * BR-A22's three states, derived rather than stored.
     *
     * ⚠ There is no fourth state and no "sent" — the system records the DOWNLOAD, not the
     * send. Delivery happens over WhatsApp, outside the system, and the system does not
     * pretend to observe it. What this can say with certainty is the useful half: **if it was
     * never downloaded, it was certainly never sent.**
     */
    public function getActivationStateProperty(): string
    {
        if ($this->user->activation_used_at !== null) {
            return 'redeemed';
        }

        if ($this->user->activation_token === null) {
            return 'none';
        }

        return $this->user->activation_downloaded_at === null
            ? 'generated'
            : 'downloaded';
    }

    public function getIsPermanentlyLockedProperty(): bool
    {
        return app(LoginThrottle::class)->isPermanentlyLocked($this->user);
    }

    public function render()
    {
        return view('livewire.accounts.manage-account');
    }
}
