@extends('layouts.app')

@section('title', 'Master Admins')

@section('body')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <header class="mb-8">
            <h1 class="text-xl font-semibold tracking-tight">Master Admins</h1>
            <p class="mt-1 text-sm text-slate-500">Maximum three, minimum one — enforced in the action, not here.</p>
        </header>

        @livewire('accounts.master-admins')
    </div>
@endsection
