{{-- Family — §7.1. The emergency contact's name and number also appear on Employment, and
     that duplication is §6.2's exception rather than an oversight. --}}
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
            <tr><th class="pb-2 pr-4">Relationship</th><th class="pb-2 pr-4">Name</th><th class="pb-2 pr-4">Contact</th><th class="pb-2">Emergency</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($employee->familyMembers as $member)
                <tr wire:key="family-{{ $member->id }}">
                    <td class="py-2 pr-4">{{ $member->relationship }}</td>
                    <td class="py-2 pr-4 font-medium">{{ $member->name }}</td>
                    <td class="py-2 pr-4">{{ $member->contact_no ?? '—' }}</td>
                    <td class="py-2">{{ $member->is_emergency_contact ? 'Yes' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-slate-500">No family members recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
