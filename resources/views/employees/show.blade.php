@extends('layouts.app')

@section('title', $employee->full_name)

{{--
    Wrapper for the Livewire component, deliberately thin — the same shape as
    employees/index.blade.php.

    ⚠ The title is the employee's name, which every reader who reached this page can already
    see: they passed EmployeePolicy::view(), and `full_name` is in both Personal field sets
    (adr/0014 decision 1) as well as on the list row that linked here.
--}}

@section('body')
    <div class="mx-auto max-w-5xl px-4 py-12">
        @livewire('employees.employee-detail', ['employee' => $employee])
    </div>
@endsection
