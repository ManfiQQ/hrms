@extends('layouts.app')

@section('title', 'Dashboard')

{{--
    Deliberately minimal. Its job today is to be a real authenticated route — somewhere the
    session gate, the freeze gate and BR-A23's gate can be observed working.

    The dashboards auth-rbac.spec.md §7 describes — the BR-A19 lifecycle countdown across
    five roles — belong to the modules that own that data. Sketching them here would put
    employment facts in a view with no module behind it.
--}}

@section('body')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <header class="mb-8 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Signed in</h1>
                <p class="mt-1 text-sm text-slate-500">{{ auth()->user()->name }}</p>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button
                    type="submit"
                    class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                >
                    Sign out
                </button>
            </form>
        </header>

        @if (session('status'))
            <p class="mb-6 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                {{ session('status') }}
            </p>
        @endif

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-slate-600">
                The HR modules are not built yet. This page exists so that the session,
                the account state gate and the forced password-change gate have somewhere
                to be observed working.
            </p>
        </div>
    </div>
@endsection
