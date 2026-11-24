@props(['name', 'label' => null, 'value' => null, 'rows' => 4, 'required' => false, 'placeholder' => ''])

<div class="mb-3">
    @if($label)
        <label for="{{ $name }}" class="form-label fw-medium">
            {{ $label }} @if($required)<span class="text-danger">*</span>@endif
        </label>
    @endif
    <textarea
        name="{{ $name }}"
        id="{{ $name }}"
        rows="{{ $rows }}"
        placeholder="{{ $placeholder }}"
        @if($required) required @endif
        {{ $attributes->merge(['class' => 'form-control'.(isset($errors) && $errors->has($name) ? ' is-invalid' : '')]) }}
    >{{ old($name, $value) }}</textarea>
    @error($name)
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
