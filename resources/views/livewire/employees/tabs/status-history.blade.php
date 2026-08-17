{{--
    Status history — §7, adr/0003 decision 8.

    ⚠ READ-ONLY, and §7 says so at the interface level for a reason: employee_status_history is
    append-only, a correction is a new row, and a screen offering an edit would be promising
    something the model refuses.

    ⚠ EVERY LINE NAMES ITS COMPANY. Two sources reach this list under two different scope
    rules — the ledger freezes the employer at the time and releases tenant scope to stay
    readable after a transfer; employee_roles carries no tenant scope at all — so a
    transferred employee's timeline genuinely spans two employers, and an unlabelled line
    would attribute an old employer's event to the current one.
--}}
<div class="space-y-3">
    <p class="text-xs text-slate-500">
        Oldest first. Role grants and revocations come from the authority pivot; everything else
        from the employment ledger. Neither is a copy of the other.
    </p>

    @forelse ($this->statusTimeline as $entry)
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-l-2 border-slate-200 pl-3 text-sm"
             wire:key="timeline-{{ $entry->source }}-{{ $entry->sourceId }}-{{ $entry->date->format('Ymd') }}">
            <span class="w-24 shrink-0 font-mono text-xs text-slate-500">{{ $entry->date->format('d M Y') }}</span>

            <span class="font-medium">{{ $entry->label }}</span>

            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ $entry->companyName ?? 'company not recorded' }}</span>

            @if ($entry->actorName)
                <span class="text-xs text-slate-500">by {{ $entry->actorName }}</span>
            @endif

            {{-- The source, so a reader can tell which table answered — §7 requires it. --}}
            <span class="ml-auto font-mono text-[10px] uppercase tracking-wide text-slate-400">{{ $entry->source }}</span>

            @if ($entry->reason)
                <p class="w-full text-xs text-slate-500">{{ $entry->reason }}</p>
            @endif
        </div>
    @empty
        <p class="py-6 text-center text-sm text-slate-500">No status or authority history recorded.</p>
    @endforelse
</div>
