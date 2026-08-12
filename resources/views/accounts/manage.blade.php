@extends('layouts.app')

@section('title', 'Account management')

{{--
    Wrapper for the Livewire component. Deliberately thin: there is no employee list and no
    employee form here — those are Employee Master's, and mixing them would blur the line
    §7 draws between account operations and profile operations.
--}}

@section('body')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <header class="mb-8">
            <h1 class="text-xl font-semibold tracking-tight">Account management</h1>
            <p class="mt-1 text-sm text-slate-500">Credentials and access, not employee data.</p>
        </header>

        @livewire('accounts.manage-account', ['user' => $user])
    </div>
@endsection
