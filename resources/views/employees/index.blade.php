@extends('layouts.app')

@section('title', 'Employees')

{{--
    Wrapper for the Livewire component, deliberately thin — the same shape as
    accounts/master-admins.blade.php.

    ⚠ Wider than the account screens (max-w-7xl, not max-w-3xl): this is a six-column table,
    and the account screens are forms about one record.
--}}

@section('body')
    <div class="mx-auto max-w-7xl px-4 py-12">
        <header class="mb-8">
            <h1 class="text-xl font-semibold tracking-tight">Employees</h1>
            <p class="mt-1 text-sm text-slate-500">
                Who you can see here depends on your role and your employer — it is not a setting.
            </p>
        </header>

        @livewire('employees.employee-list')
    </div>
@endsection
