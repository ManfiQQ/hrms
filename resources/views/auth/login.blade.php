@extends('layouts.centred')

@section('title', 'Sign in')
@section('subtitle', 'Sign in to the HR system')

@section('card')
    {{--
        ⚠ THERE IS NO REMEMBER-ME CHECKBOX, AND NONE MAY BE ADDED (BR-A4).

        Removing it from this file is not what enforces the rule — the field can be posted
        directly, so AuthenticationService::attempt() has no $remember parameter to receive
        and `users` has no remember_token column for a recaller to be minted against. This
        comment is here so that adding the checkbox back looks like what it is.

        Much of this workforce signs in from SHARED TERMINALS at the factory, studio and
        galleria, where a remembered login means the account is never really logged out.
    --}}
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="phone_no" class="block text-sm font-medium text-slate-700">
                Phone number
            </label>

            {{--
                ⚠ The username is the phone number, not an email address (BR-A1). Most field
                staff have no email at all, which is why users.email is nullable and
                authenticates nothing.

                inputmode="tel" rather than type="tel": the field accepts 012-345 6789 and
                +60123456789 as typed, and PhoneNumber::normalise reconciles them server-side.
                Rejecting formatting in the browser would fail people entering their own
                number correctly.
            --}}
            <input
                id="phone_no"
                name="phone_no"
                type="text"
                inputmode="tel"
                autocomplete="username"
                value="{{ old('phone_no') }}"
                required
                autofocus
                class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
            >
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700">
                Password
            </label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
                class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
            >
        </div>

        {{--
            ⚠ ONE MESSAGE FOR EVERY CAUSE, rendered against the form rather than a field.

            An unknown number, a wrong password and a locked account all produce the same
            text. The username IS a phone number, so an oracle here turns "I know this person
            works here" into "I know their login" (BR-A3).
        --}}
        @error('phone_no')
            <p class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $message }}</p>
        @enderror

        @error('password')
            <p class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $message }}</p>
        @enderror

        <button
            type="submit"
            class="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2"
        >
            Sign in
        </button>
    </form>

    <p class="mt-6 text-center text-xs text-slate-500">
        Forgotten your password? HR can reset it for you.
    </p>
@endsection
