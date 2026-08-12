{{--
    Master Admin management — auth-rbac.spec.md §5.8.

    ⚠ Nothing here enforces BR-A13. The disabled button at three and the hidden one at one are
    PRESENTATION; CreateMasterAdmin and RemoveMasterAdmin refuse regardless of what this form
    offered. A cap enforced in a Blade condition is one a console command walks past.
--}}
<div class="space-y-6" x-data="{ notice: null }"
     x-on:account-updated.window="notice = $event.detail.message; setTimeout(() => notice = null, 8000)">

    <div x-show="notice" x-cloak
         class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" x-text="notice"></div>

    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
            Master Admins ({{ $this->masterAdmins->count() }} of {{ \App\Actions\Auth\CreateMasterAdmin::MAXIMUM }})
        </h3>

        <p class="mt-2 text-xs text-slate-500">
            These accounts bypass tenant scope and read every salary in the group. The limit is
            three, and the last one cannot be stood down.
        </p>

        @error('removal') <p class="mt-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $message }}</p> @enderror

        <ul class="mt-4 divide-y divide-slate-100">
            @foreach ($this->masterAdmins as $admin)
                <li class="flex items-center justify-between py-3">
                    <div>
                        <p class="text-sm font-medium">{{ $admin->name }}</p>
                        <p class="text-xs text-slate-500">{{ $admin->phone_no }}</p>
                    </div>

                    @if ($admin->is(auth()->user()))
                        {{-- Refused by the Action too; hidden here so nobody reaches for it. --}}
                        <span class="text-xs text-slate-400">This is you</span>
                    @elseif ($this->isLastRemaining)
                        <span class="text-xs text-slate-400">Cannot stand down the last one</span>
                    @else
                        <button type="button" wire:click="remove({{ $admin->id }})"
                                wire:confirm="Stand this account down? It keeps read access and loses every write."
                                class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                            Stand down
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Add a Master Admin</h3>

        @if ($this->atCapacity)
            <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
                The limit of {{ \App\Actions\Auth\CreateMasterAdmin::MAXIMUM }} has been reached.
                Stand one down before adding another.
            </p>
        @else
            <form wire:submit="create" class="mt-3 max-w-sm space-y-3">
                <div>
                    <label for="ma-name" class="block text-sm font-medium text-slate-700">Name</label>
                    <input id="ma-name" type="text" wire:model="name"
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('name') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    {{-- The phone number is the login username (adr/0006). --}}
                    <label for="ma-phone" class="block text-sm font-medium text-slate-700">Phone number (login)</label>
                    <input id="ma-phone" type="text" inputmode="tel" wire:model="phoneNo"
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('phoneNo') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="ma-email" class="block text-sm font-medium text-slate-700">Email (optional)</label>
                    <input id="ma-email" type="email" wire:model="email"
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('email') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                </div>

                {{-- No password field, deliberately: none is created (adr/0004 decision 7). --}}
                <p class="text-xs text-slate-500">
                    No password is set. The account is reached by scanning its activation QR,
                    which forces them to choose their own.
                </p>

                <button type="submit"
                        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    Create Master Admin
                </button>
            </form>
        @endif

        @if ($issuedToken && $issuedFor)
            <div class="mt-6 rounded-md border border-sky-200 bg-sky-50 p-4">
                <p class="text-sm font-medium text-sky-900">Activation QR issued</p>
                <p class="mt-1 text-xs text-sky-800">
                    This is the only way into the new account, and it expires. Open the image and
                    send it to them.
                </p>
                <a href="{{ route('activation.image', $issuedFor) }}" target="_blank"
                   class="mt-3 inline-block rounded-md border border-sky-300 bg-white px-3 py-2 text-sm font-medium text-sky-900 hover:bg-sky-100">
                    Open QR image
                </a>
            </div>
        @endif
    </section>
</div>
