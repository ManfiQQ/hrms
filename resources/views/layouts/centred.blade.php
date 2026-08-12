@extends('layouts.app')

{{--
    The shell for the two unauthenticated-ish screens: login, and the forced password change.
    Both are single-purpose pages a person should not be able to navigate away from by
    accident, so there is deliberately no navigation chrome here.
--}}

@section('body')
    <main class="flex min-h-full items-center justify-center px-4 py-12">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <h1 class="text-xl font-semibold tracking-tight text-slate-900">AL HADDAD SUCCESS</h1>
                <p class="mt-1 text-sm text-slate-500">@yield('subtitle')</p>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                @if (session('status'))
                    <p class="mb-4 rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-800">
                        {{ session('status') }}
                    </p>
                @endif

                @yield('card')
            </div>
        </div>
    </main>
@endsection
