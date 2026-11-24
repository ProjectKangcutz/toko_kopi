@props(['type' => 'info', 'title' => null])

@php
    $styles = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'danger' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'alert '.($styles[$type] ?? 'alert-info').' border-0 shadow-sm']) }} role="alert">
    @if($title)
        <div class="fw-bold mb-1">{{ $title }}</div>
    @endif
    <div>{{ $slot }}</div>
</div>
