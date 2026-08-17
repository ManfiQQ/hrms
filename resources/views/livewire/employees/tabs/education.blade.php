{{-- Education — §7.1: level, institution, year. --}}
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
            <tr><th class="pb-2 pr-4">Level</th><th class="pb-2 pr-4">Institution</th><th class="pb-2">Year</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($employee->educationHistory as $record)
                <tr wire:key="education-{{ $record->id }}">
                    <td class="py-2 pr-4">{{ $record->level }}</td>
                    <td class="py-2 pr-4 font-medium">{{ $record->institution }}</td>
                    <td class="py-2">{{ $record->year ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="py-6 text-center text-slate-500">No education history recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
