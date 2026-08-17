@extends('layouts.app')

@section('title', 'Edit employee')

@section('body')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <header class="mb-8">
            <h1 class="text-xl font-semibold tracking-tight">{{ $employee->full_name }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $employee->employee_no }} — the phone number and the employment status are
                changed elsewhere, and this form does not render either.
            </p>
        </header>

        @livewire('employees.employee-edit', ['employee' => $employee])
    </div>
@endsection
