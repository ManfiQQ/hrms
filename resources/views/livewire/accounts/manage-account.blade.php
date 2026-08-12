{{--
    The account management screen — auth-rbac.spec.md §7.

    ⚠ Every control here is an ACCOUNT operation, not a profile edit. That separation is the
    reason ASSISTANT_DIRECTOR reaches none of them despite being able to create, edit and
    archive employee records: the employee form is for employee data, this screen is for
    account credentials.
--}}
<div class="space-y-6" x-data="{ notice: null }"
     x-on:account-updated.window="notice = $event.detail.message; setTimeout(() => notice = null, 6000)">

    <div x-show="notice" x-cloak
         class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" x-text="notice"></div>

    <header class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold tracking-tight">{{ $user->employee?->full_name ?? $user->name }}</h2>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
            <div>
                <dt class="text-slate-500">Login username</dt>
                {{-- The phone number IS the username (adr/0006). --}}
                <dd class="font-medium">{{ $user->phone_no }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Employee no.</dt>
                <dd class="font-medium">{{ $user->employee?->employee_no ?? '—' }}</dd>
            </div>
        </dl>
    </header>

    {{--
        BR-A22's three states. There is deliberately no "sent" — the system records the
        download, not the send, because delivery happens over WhatsApp and a "mark as sent"
        button records an assertion, not a fact.
    --}}
    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Activation</h3>

        <p class="mt-3 text-sm" data-testid="activation-state">
            @switch($this->activationState)
                @case('redeemed')
                    <span class="font-medium text-emerald-700">Redeemed</span>
                    <span class="text-slate-500">— the employee has set their own password.</span>
                    @break
                @case('downloaded')
                    <span class="font-medium text-amber-700">Downloaded, not redeemed</span>
                    <span class="text-slate-500">— in flight, or the employee has not scanned it yet.</span>
                    @break
                @case('generated')
                    <span class="font-medium text-sky-700">Generated, not downloaded</span>
                    <span class="text-slate-500">— it has certainly not been sent.</span>
                    @break
                @default
                    <span class="font-medium text-slate-700">No activation issued</span>
            @endswitch
        </p>

        @if ($user->activation_expires_at && $this->activationState !== 'redeemed')
            <p class="mt-1 text-xs text-slate-500">Valid until {{ $user->activation_expires_at->format('j M Y, g:ia') }}.</p>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @if ($this->activationState !== 'none' && $this->activationState !== 'redeemed')
                {{-- Fetching the image is what stamps activation_downloaded_at (BR-A22). --}}
                <a href="{{ route('activation.image', $user) }}" target="_blank"
                   class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Open QR image
                </a>
            @endif

            <button type="button" wire:click="regenerateActivation" wire:loading.attr="disabled"
                    class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
                Issue a new QR
            </button>
        </div>

        <p class="mt-2 text-xs text-slate-500">
            Issuing a new QR kills the previous one immediately, whether or not it was downloaded.
        </p>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Sign-in</h3>

        @if ($this->isPermanentlyLocked)
            <p class="mt-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">
                Locked after repeated failed sign-ins. Only HR or a Master Admin can lift it.
            </p>

            <button type="button" wire:click="unlock"
                    class="mt-3 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
                Unlock this account
            </button>
        @else
            <p class="mt-3 text-sm text-slate-600">Not locked.</p>
        @endif

        <form wire:submit="resetPassword" class="mt-6 max-w-sm space-y-3">
            <label for="newPassword" class="block text-sm font-medium text-slate-700">Set a temporary password</label>
            <input id="newPassword" type="password" wire:model="newPassword" autocomplete="new-password"
                   class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            @error('newPassword') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror

            {{-- No composition hint, because there are no composition rules (BR-A2). --}}
            <p class="text-xs text-slate-500">
                The employee is forced to replace it the moment they sign in.
            </p>

            <button type="submit"
                    class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Reset password
            </button>
        </form>
    </section>

    {{--
        ⚠ The only place a phone number changes anywhere in the system (adr/0006). It is a
        CREDENTIAL change, not a contact update — the employee signs in with the new number
        from the moment it is saved, and can no longer use the old one.
    --}}
    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Login username</h3>

        <form wire:submit="changeUsername" class="mt-3 max-w-sm space-y-3">
            <input type="text" inputmode="tel" wire:model="newPhoneNo"
                   class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            @error('newPhoneNo') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror

            <p class="text-xs text-slate-500">
                This is the number they sign in with. Changing it here changes their login —
                the employee record has no phone number of its own.
            </p>

            <button type="submit"
                    class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Change username
            </button>
        </form>
    </section>
</div>
