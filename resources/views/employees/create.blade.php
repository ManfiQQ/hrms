@extends('layouts.app')

@section('title', 'Register employee')

{{--
    Wrapper for the Livewire component, deliberately thin — the same shape as employees/index.

    ⚠ Narrower than the list (max-w-3xl, not max-w-7xl): this is a form about one record, not a
    six-column table.
--}}

@section('body')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <header class="mb-8">
            <h1 class="text-xl font-semibold tracking-tight">Register employee</h1>
            <p class="mt-1 text-sm text-slate-500">
                The record, the number, the account and the activation code are created together
                or not at all (BR-A20).
            </p>
        </header>

        @livewire('employees.employee-create')
    </div>
@endsection
