{{--
    The employee list — employee-master.spec.md §5.4, §7.

    ⚠ SIX COLUMNS, AND NONE OF adr/0013's TWELVE. Identity and statutory fields — IC, passport,
    date of birth, bank account — are the Personal tab's, behind a per-tab permission check.
    A list identifies people; it does not display their identity.

    ⚠ NO PER-ROW LINK, and its absence is deliberate rather than unfinished. The employee
    detail screen does not exist yet, and a row linking to a 404 is worse than a row that does
    not link. The link lands with that screen.
--}}
<div class="space-y-6">

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">

            {{-- One box, four fields, substring match (§5.4). HR remembers a last name. --}}
            <label class="lg:col-span-2 block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Search</span>
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Employee no, name, nickname or email"
                       class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
            </label>

            {{--
                ⚠ HIDDEN when the account reads one company, and this means a group-scoped
                account and a subsidiary-scoped account see DIFFERENT FORMS. That is intended:
                a select with one option is a control that cannot change the answer, and a
                label reading "Company: AIM" answers a question an AIM supervisor never asked.
                Do not "tidy" the two layouts into one — that restores the dead control.
            --}}
            @if ($this->showsCompanyFilter)
                <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Company</span>
                    <select wire:model.live="companyId" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        <option value="">All companies</option>
                        @foreach ($this->companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Branch</span>
                <select wire:model.live="branchId" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">All branches</option>
                    @foreach ($this->branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Department</span>
                <select wire:model.live="departmentId" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">All departments</option>
                    @foreach ($this->departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Position</span>
                <select wire:model.live="positionId" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">All positions</option>
                    @foreach ($this->positions as $position)
                        <option value="{{ $position->id }}">{{ $position->title }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Status</span>
                <select wire:model.live="staffStatus" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">All statuses</option>
                    @foreach (\App\Models\Employee::STAFF_STATUSES as $status)
                        <option value="{{ $status }}">{{ $status }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Employment type</span>
                <select wire:model.live="employmentType" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">All types</option>
                    @foreach (\App\Models\Employee::EMPLOYMENT_TYPES as $type)
                        <option value="{{ $type }}">{{ $type }}</option>
                    @endforeach
                </select>
            </label>

            {{-- Display only — never an authorization or routing input (BR-9). --}}
            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Level</span>
                <select wire:model.live="level" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">All levels</option>
                    @foreach (\App\Models\Employee::LEVELS as $tier)
                        <option value="{{ $tier }}">{{ $tier }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="mt-3 flex items-center justify-between">
            <p class="text-xs text-slate-500">{{ $employees->total() }} {{ Str::plural('employee', $employees->total()) }}</p>

            <button type="button" wire:click="clearFilters"
                    class="text-xs font-medium text-slate-600 underline underline-offset-2 hover:text-slate-900">
                Clear filters
            </button>
        </div>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Employee no</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3">Department</th>
                        <th class="px-4 py-3">Position</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse ($employees as $employee)
                        <tr wire:key="employee-{{ $employee->id }}">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-600">{{ $employee->employee_no }}</td>
                            <td class="px-4 py-3 font-medium">{{ $employee->full_name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $employee->company?->name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $employee->department?->name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $employee->position?->title ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $employee->staff_status }}</td>
                        </tr>
                    @empty
                        {{--
                            Two different empty states, because they mean different things. With
                            filters on, the reader narrowed too far. With none, the reader can
                            genuinely see nobody — which for a supervisor is adr/0011 decision 4
                            showing: nobody names them in direct_supervisor_id or manager_id, and
                            an unfilled column should look empty rather than be smoothed over.
                        --}}
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">
                                @if ($this->hasActiveFilters)
                                    No employees match these filters.
                                @else
                                    No employees to show. If you supervise staff, their records may not
                                    name you as their supervisor or manager yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($employees->hasPages())
        <div>{{ $employees->links() }}</div>
    @endif
</div>
