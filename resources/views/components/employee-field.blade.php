{{--
    One labelled input, bound to a Livewire property.

    ⚠ wire:model.blur, NEVER wire:model.live — and on this form the difference is not a
    preference. The registration form carries more than twenty fields; `.live` sends a request
    on every keystroke, so typing one IC number is twelve round trips and typing an address is
    fifty. `.blur` sends one when the field is left, which is when the value is worth having.

    ⚠ `.defer` is NOT the answer either: this form runs a server-side lookup off one of its own
    fields (`adr/0015` decision 5), so the component must hold current values before the button
    is pressed. `.blur` is what gives it both.

    @param string  $name   the Livewire property, and the validation key
    @param string  $label
    @param string  $type   an <input> type, or 'select' / 'textarea' / 'checkbox'
    @param array   $options  for 'select': value => label
    @param bool    $required  renders the mark only; the rule lives in the FormRequest
--}}
@props([
    'name',
    'label',
    'type' => 'text',
    'options' => [],
    'required' => false,
    'hint' => null,
    'model' => null,
])

@php
    // ⚠ The edit form nests its fields under `form.`, the create form binds them at the top
    // level. One component serves both rather than two drifting apart — and the validation key
    // must match the binding, or @error renders nothing and the field looks valid.
    $model = $model ?? $name;
@endphp

<div class="mb-4">
    <label for="{{ $model }}" class="mb-1 block text-sm font-medium text-slate-700">
        {{ $label }}
        @if ($required)
            <span class="text-slate-400" aria-hidden="true">*</span>
        @endif
    </label>

    @if ($type === 'select')
        <select id="{{ $model }}" wire:model.blur="{{ $model }}"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
            <option value="">—</option>
            @foreach ($options as $value => $text)
                <option value="{{ $value }}">{{ $text }}</option>
            @endforeach
        </select>
    @elseif ($type === 'textarea')
        <textarea id="{{ $model }}" wire:model.blur="{{ $model }}" rows="3"
                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"></textarea>
    @elseif ($type === 'checkbox')
        <input id="{{ $model }}" type="checkbox" wire:model.blur="{{ $model }}"
               class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
    @else
        <input id="{{ $model }}" type="{{ $type }}" wire:model.blur="{{ $model }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
    @endif

    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @error($model)
        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
    @enderror
</div>
