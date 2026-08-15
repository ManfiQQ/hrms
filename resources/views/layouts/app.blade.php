<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'AHS HR')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
    {{--
        The first navigation in this layout, added 2026-08-15 with the employee list.

        ⚠ THE EMPLOYEE LINK IS GATED ON `viewAny`, WHICH IS NOT "can they see anybody". Every
        account can see its own record, so a "can see at least one employee" test is true for
        everyone and would put this link in front of a clerk whose list is one row long. The
        gate is: any authority role, or a system_access other than STANDARD — see
        EmployeePolicy::viewAny().

        ⚠ It does NOT belong on the dashboard, which refuses to show employment facts without a
        module behind them. A nav link is a door, not a fact about anybody.
    --}}
    @auth
        <nav class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center gap-6 px-4 py-3 text-sm">
                <a href="{{ route('dashboard') }}" class="font-semibold tracking-tight">AHS HR</a>

                @can('viewAny', App\Models\Employee::class)
                    <a href="{{ route('employees.index') }}"
                       class="font-medium text-slate-600 transition hover:text-slate-900 {{ request()->routeIs('employees.*') ? 'text-slate-900 underline underline-offset-4' : '' }}">
                        Employees
                    </a>
                @endcan
            </div>
        </nav>
    @endauth

    @yield('body')
    @livewireScripts
</body>
</html>
