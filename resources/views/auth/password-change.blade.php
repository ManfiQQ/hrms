@extends('layouts.centred')

@section('title', 'Set your password')
@section('subtitle', 'Set your own password to continue')

@section('card')
    {{--
        BR-A23's screen. While must_change_password is true, every route except this one and
        logout redirects here — including for Master Admin, whose first password comes from
        an environment variable.

        ⚠ The current password is deliberately not asked for. This screen is reached after HR
        resets a password, and after a QR activation where the employee has never had one;
        requiring it would make the second path impossible. The gate exists precisely because
        the existing credential is not trusted.
    --}}
    <form method="POST" action="{{ route('password.change.update') }}" class="space-y-4">
        @csrf

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700">
                New password
            </label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="new-password"
                required
                autofocus
                class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
            >
            {{--
                ⚠ No composition hint, because there are no composition rules (BR-A2). Forcing
                uppercase, digits and symbols produces Abcd1234! and passwords written on
                paper; a memorable phrase is stronger than a short complex string on a sticky
                note. The minimum comes from policy_configurations, never a literal.
            --}}
            <p class="mt-1 text-xs text-slate-500">
                A phrase you will remember is stronger than a short, complicated word.
            </p>
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-slate-700">
                Repeat new password
            </label>
            <input
                id="password_confirmation"
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                required
                class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
            >
        </div>

        @error('password')
            <p class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $message }}</p>
        @enderror

        <button
            type="submit"
            class="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2"
        >
            Save password
        </button>
    </form>

    {{--
        Logout stays reachable from here. An account that can neither change its password nor
        leave is trapped, and the person most likely to be trapped is someone who opened the
        system on a shared terminal and wants out of it.
    --}}
    <form method="POST" action="{{ route('logout') }}" class="mt-4">
        @csrf
        <button type="submit" class="w-full text-center text-xs text-slate-500 hover:text-slate-800">
            Sign out instead
        </button>
    </form>
@endsection
