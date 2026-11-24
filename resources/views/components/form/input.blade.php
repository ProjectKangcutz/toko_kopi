@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'required' => false, 'placeholder' => ''])

<div class="mb-3">
    @if($label)
        <label for="{{ $name }}" class="form-label fw-medium">
            {{ $label }} @if($required)<span class="text-danger">*</span>@endif
        </label>
    @endif
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $name }}"
        value="{{ old($name, $value) }}"
        placeholder="{{ $placeholder }}"
        @if($required) required @endif
        {{ $attributes->merge(['class' => 'form-control'.(isset($errors) && $errors->has($name) ? ' is-invalid' : '')]) }}
    >
    @error($name)
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
