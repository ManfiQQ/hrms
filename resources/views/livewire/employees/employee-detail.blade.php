{{--
    The employee detail screen — employee-master.spec.md §7 and §7.1.

    ⚠ READ-ONLY. No grant, revoke, edit or archive control appears anywhere below, and that is
    adr/0014 rather than an unfinished screen. §5.6: hiding a control is presentation while the
    authorisation is the rule — a control that was never rendered cannot be one the policy has
    to reject.

    ⚠ THE TAB STRIP IS BUILT FROM THE POLICY, NOT FROM A LIST WRITTEN HERE. A tab absent from
    $this->visibleTabs is one this account may not open, and there is no second place holding
    an opinion about that.
--}}
<div class="space-y-6">

    {{--
        The record header sits OUTSIDE the tabs, because it identifies the record rather than
        describing it (§7.1). All three are already on the list row that linked here.
    --}}
    <header class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <p class="font-mono text-xs text-slate-500">{{ $employee->employee_no }}</p>
                <h1 class="mt-1 text-xl font-semibold tracking-tight">{{ $employee->full_name }}</h1>
            </div>

            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium uppercase tracking-wide text-slate-600">
                {{ $employee->staff_status }}
            </span>
        </div>

        <a href="{{ route('employees.index') }}"
           class="mt-4 inline-block text-xs font-medium text-slate-500 underline underline-offset-2 hover:text-slate-900">
            Back to employees
        </a>
    </header>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <nav class="flex flex-wrap gap-1 border-b border-slate-200 bg-slate-50 px-2 pt-2" aria-label="Employee record">
            @foreach ($this->visibleTabs as $tab)
                <button type="button" wire:click="selectTab('{{ $tab }}')" wire:key="tab-{{ $tab }}"
                        @class([
                            'rounded-t-md px-3 py-2 text-sm font-medium transition',
                            'bg-white text-slate-900 shadow-sm' => $this->activeTab === $tab,
                            'text-slate-500 hover:text-slate-900' => $this->activeTab !== $tab,
                        ])
                        @if ($this->activeTab === $tab) aria-current="page" @endif>
                    {{ $this->tabLabels[$tab] }}
                </button>
            @endforeach
        </nav>

        <div class="p-5">
            @include('livewire.employees.tabs.'.str_replace('_', '-', $this->activeTab))
        </div>
    </div>
</div>
