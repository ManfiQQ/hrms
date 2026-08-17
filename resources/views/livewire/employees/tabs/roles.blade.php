{{--
    Roles & functions — §7.1.

    ⚠ READ-ONLY. No grant or revoke control is rendered, and EmployeePolicy::grantRole() is
    never consulted here — a screen with no write path has nothing for it to authorise. Who
    may grant or revoke, per role and per company, is §6's matrix.

    ⚠ Revoked rows are VISIBLY SEPARATE. A revoked role is history, not a current grant, and
    the two must never read as the same thing (§7).
--}}
<div class="space-y-6">
    <section>
        <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current authority</h2>

        @forelse ($employee->roles as $role)
            <p class="mt-1 text-sm" wire:key="role-{{ $role->id }}">
                <span class="font-medium">{{ Str::title(str_replace('_', ' ', $role->role)) }}</span>
                at {{ $role->company?->name ?? '—' }}
                <span class="text-slate-500">— from {{ $role->effective_date }}, granted by {{ $role->assignedBy?->name ?? 'unknown' }}</span>
            </p>
        @empty
            {{-- No row at all IS the staff state — there is no STAFF value (adr/0003 decision 1). --}}
            <p class="mt-1 text-sm text-slate-500">Holds no authority role. That is the ordinary staff state, not a missing record.</p>
        @endforelse
    </section>

    <section>
        <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Job functions</h2>

        @forelse ($employee->jobFunctions as $assignment)
            <p class="mt-1 text-sm" wire:key="function-{{ $assignment->id }}">
                <span class="font-medium">{{ $assignment->jobFunction?->name ?? '—' }}</span>
                at {{ $assignment->company?->name ?? '—' }}
            </p>
        @empty
            <p class="mt-1 text-sm text-slate-500">No job functions assigned.</p>
        @endforelse
    </section>

    @if ($this->revokedRoles->isNotEmpty())
        <section class="rounded-lg border border-slate-200 bg-slate-50 p-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Revoked — history, not current authority</h2>

            @foreach ($this->revokedRoles as $role)
                <p class="mt-1 text-sm text-slate-500 line-through decoration-slate-400" wire:key="revoked-{{ $role->id }}">
                    {{ Str::title(str_replace('_', ' ', $role->role)) }} at {{ $role->company?->name ?? '—' }}
                    — revoked {{ $role->revoked_date }}
                </p>
            @endforeach
        </section>
    @endif
</div>
