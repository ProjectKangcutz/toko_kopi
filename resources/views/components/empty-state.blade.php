@props([
    'icon' => 'bi-inbox',
    'title' => 'Belum ada data',
    'message' => 'Data yang Anda cari belum tersedia saat ini.',
    'actionLabel' => null,
    'actionUrl' => null,
])

<div class="mlm-card p-4 p-md-5 text-center my-3">
    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-mlm-secondary text-mlm-primary mb-3" style="width: 64px; height: 64px;">
        <i class="bi {{ $icon }} fs-3"></i>
    </div>
    <h3 class="h5 fw-bold mb-2">{{ $title }}</h3>
    <p class="text-mlm-muted small mx-auto mb-3" style="max-width: 420px;">{{ $message }}</p>
    @if($actionLabel && $actionUrl)
        <a href="{{ $actionUrl }}" class="btn btn-primary">{{ $actionLabel }}</a>
    @endif
</div>
