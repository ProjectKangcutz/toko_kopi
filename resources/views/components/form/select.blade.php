@props(['name', 'label' => null, 'options' => [], 'selected' => null, 'required' => false, 'placeholder' => '-- Pilih --'])

<div class="mb-3">
    @if($label)
        <label for="{{ $name }}" class="form-label fw-medium">
            {{ $label }} @if($required)<span class="text-danger">*</span>@endif
        </label>
    @endif
    <select
        name="{{ $name }}"
        id="{{ $name }}"
        @if($required) required @endif
        {{ $attributes->merge(['class' => 'form-select'.(isset($errors) && $errors->has($name) ? ' is-invalid' : '')]) }}
    >
        @if($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach($options as $optValue => $optLabel)
            <option value="{{ $optValue }}" @selected((string) old($name, $selected) === (string) $optValue)>
                {{ $optLabel }}
            </option>
        @endforeach
    </select>
    @error($name)
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
