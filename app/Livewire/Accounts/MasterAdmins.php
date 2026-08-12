<?php

namespace App\Livewire\Accounts;

use App\Actions\Auth\CreateMasterAdmin;
use App\Actions\Auth\RemoveMasterAdmin;
use App\Exceptions\Auth\MasterAdminLimitException;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Master Admin management — auth-rbac.spec.md §5.8, §7.
 *
 * ⚠ THE SCREEN ENFORCES NOTHING. BR-A13's 3/1 limits live in `CreateMasterAdmin` and
 * `RemoveMasterAdmin`; this component only asks and reports. What it renders — a disabled
 * button at three, a hidden one at one — is presentation, and the Actions refuse regardless
 * of what the form offered.
 *
 * That split is the whole point of §5.8's wording. A cap enforced in the UI is a cap that a
 * console command, a seeder or a future API route walks straight past, and what it bounds is
 * the set of accounts that bypass tenant scope and read every salary in the group.
 */
class MasterAdmins extends Component
{
    public string $name = '';

    public string $phoneNo = '';

    public string $email = '';

    public ?string $issuedToken = null;

    public ?int $issuedFor = null;

    /**
     * ⚠ `User::class` is passed even though the ability takes no target. A policy method with
     * no model argument is unreachable without it — Laravel would look for a Gate-defined
     * ability of that name, find none, and DENY. That fails closed, which is the safe
     * direction, but it denies Master Admin too and the screen simply never opens.
     */
    public function mount(): void
    {
        Gate::authorize('administerMasterAdmins', User::class);
    }

    public function create(CreateMasterAdmin $action): void
    {
        // Re-authorised per action: a Livewire action is its own request, so a mount-time
        // check would authorise for the life of the page.
        Gate::authorize('administerMasterAdmins', User::class);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phoneNo' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        try {
            $result = $action->execute($this->name, $this->phoneNo, $this->email ?: null);
        } catch (MasterAdminLimitException $e) {
            // Surfaced as a form error rather than a crash: hitting the ceiling is an
            // ordinary answer to an ordinary request, not a fault.
            $this->addError('phoneNo', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            $this->addError('phoneNo', $e->validator->errors()->first());

            return;
        }

        // ⚠ Shown once and never stored. The new Master Admin is reached by redeeming this
        // activation QR — no password is created for them, and none is passed between two
        // people (adr/0004 decision 7).
        $this->issuedToken = $result['activationToken'];
        $this->issuedFor = $result['user']->getKey();

        $this->reset(['name', 'phoneNo', 'email']);

        $this->dispatch('account-updated', message: 'Master Admin created. Send them the activation QR — it is the only way in.');
    }

    public function remove(int $userId, RemoveMasterAdmin $action): void
    {
        Gate::authorize('administerMasterAdmins', User::class);

        $target = User::query()->findOrFail($userId);

        try {
            $action->execute($target, auth()->user());
        } catch (MasterAdminLimitException $e) {
            $this->addError('removal', $e->getMessage());

            return;
        }

        $this->dispatch('account-updated', message: 'Master Admin stood down. The account can still read, and can no longer write.');
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    public function getMasterAdminsProperty()
    {
        return User::query()->where('system_access', 'FULL')->orderBy('id')->get();
    }

    public function getAtCapacityProperty(): bool
    {
        return $this->masterAdmins->count() >= CreateMasterAdmin::MAXIMUM;
    }

    public function getIsLastRemainingProperty(): bool
    {
        return $this->masterAdmins->count() <= RemoveMasterAdmin::MINIMUM;
    }

    public function render()
    {
        return view('livewire.accounts.master-admins');
    }
}
