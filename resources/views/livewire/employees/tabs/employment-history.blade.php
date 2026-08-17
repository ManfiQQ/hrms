{{--
    Employment history — §7.1.

    ⚠ EMPLOYMENT BEFORE THIS GROUP, NOT MOVEMENT BETWEEN ITS ENTITIES. A company transfer is
    employee_status_history and appears on the Status history tab; a reader who confuses the
    two reads a transfer as a resignation.
--}}
<div class="space-y-3">
    <p class="text-xs text-slate-500">Employment before joining this group. Transfers between group companies appear under Status history.</p>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr><th class="pb-2 pr-4">Employer</th><th class="pb-2 pr-4">Position</th><th class="pb-2 pr-4">From</th><th class="pb-2">To</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($employee->employmentHistory as $record)
                    <tr wire:key="employment-history-{{ $record->id }}">
                        <td class="py-2 pr-4 font-medium">{{ $record->company_name }}</td>
                        <td class="py-2 pr-4">{{ $record->position ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $record->start_date ?? '—' }}</td>
                        <td class="py-2">{{ $record->end_date ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-slate-500">No previous employment recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
