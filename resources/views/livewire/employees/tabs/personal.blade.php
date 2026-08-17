{{--
    Personal — §7.1, tiered by field (adr/0014 decision 1).

    ⚠ THE ROWS COME FROM THE POLICY, NOT FROM THE MODEL. A withheld field is never resolved,
    so it is absent from the response rather than hidden in it: there is nothing greyed out
    and nothing for a browser to reveal. A supervisor gets four rows; the administrative tier,
    FULL, VIEW_ONLY and the employee on their own record get sixteen.
--}}
<dl class="grid gap-x-8 gap-y-3 sm:grid-cols-2">
    @foreach ($this->personalRows as $label => $value)
        <div wire:key="personal-{{ $loop->index }}">
            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</dt>
            <dd class="mt-0.5 text-sm">{{ $value }}</dd>
        </div>
    @endforeach
</dl>
